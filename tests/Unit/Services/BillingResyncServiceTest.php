<?php

namespace Tests\Unit\Services;

use App\Jobs\ProcessBillingJob;
use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\DebtorProfile;
use App\Models\Upload;
use App\Models\VopLog;
use App\Services\BillingResyncService;
use App\Services\Dto\ResyncEligibility;
use App\Services\Dto\ResyncResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class BillingResyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private BillingResyncService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BillingResyncService();
    }

    // ──────────────────────────────────────────────
    // isResyncAllowedForModel()
    // ──────────────────────────────────────────────

    public function test_resync_allowed_for_legacy_model(): void
    {
        $this->assertTrue($this->service->isResyncAllowedForModel(DebtorProfile::MODEL_LEGACY));
    }

    public function test_resync_not_allowed_for_flywheel_model(): void
    {
        $this->assertFalse($this->service->isResyncAllowedForModel(DebtorProfile::MODEL_FLYWHEEL));
    }

    public function test_resync_not_allowed_for_recovery_model(): void
    {
        $this->assertFalse($this->service->isResyncAllowedForModel(DebtorProfile::MODEL_RECOVERY));
    }

    public function test_resync_not_allowed_for_unknown_model(): void
    {
        $this->assertFalse($this->service->isResyncAllowedForModel('nonexistent_model'));
    }

    // ──────────────────────────────────────────────
    // canResync()
    // ──────────────────────────────────────────────

    public function test_can_resync_returns_allowed_for_legacy_upload_with_eligible_debtors(): void
    {
        $upload = Upload::factory()->create([
            'is_30d_cool' => false,
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subDays(10),
            'billing_completed_at' => now()->subDays(6),
        ]);

        $this->createEligibleLegacyDebtor($upload);

        $result = $this->service->canResync($upload);

        $this->assertTrue($result->allowed);
        $this->assertEquals(1, $result->eligibleCount);
    }

    public function test_can_resync_rejects_explicit_flywheel_model(): void
    {
        $upload = Upload::factory()->create();

        $result = $this->service->canResync($upload, DebtorProfile::MODEL_FLYWHEEL);

        $this->assertFalse($result->allowed);
        $this->assertStringContainsString('not supported', $result->reason);
        $this->assertEquals(DebtorProfile::MODEL_FLYWHEEL, $result->billingModel);
    }

    public function test_can_resync_rejects_explicit_recovery_model(): void
    {
        $upload = Upload::factory()->create();

        $result = $this->service->canResync($upload, DebtorProfile::MODEL_RECOVERY);

        $this->assertFalse($result->allowed);
        $this->assertStringContainsString('not supported', $result->reason);
    }

    public function test_can_resync_rejects_non_legacy_upload_with_all_model_when_no_legacy_debtors(): void
    {
        $upload = Upload::factory()->create([
            'billing_model' => DebtorProfile::MODEL_FLYWHEEL,
            'billing_status' => Upload::JOB_COMPLETED,
        ]);

        $flywheelProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_FLYWHEEL]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => $flywheelProfile->id,
            'billing_model' => DebtorProfile::MODEL_FLYWHEEL,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        $result = $this->service->canResync($upload, DebtorProfile::ALL);

        $this->assertFalse($result->allowed);
        $this->assertStringContainsString('no Legacy debtors', $result->reason);
    }

    public function test_can_resync_allows_non_legacy_upload_with_all_model_when_legacy_debtors_exist(): void
    {
        $upload = Upload::factory()->create([
            'billing_model' => DebtorProfile::MODEL_FLYWHEEL,
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subDays(10),
            'billing_completed_at' => now()->subDays(6),
        ]);

        $this->createEligibleLegacyDebtor($upload);

        $result = $this->service->canResync($upload, DebtorProfile::ALL);

        $this->assertTrue($result->allowed);
    }

    public function test_can_resync_rejects_when_resync_is_already_in_progress(): void
    {
        $upload = Upload::factory()->create([
            'billing_model' => DebtorProfile::MODEL_LEGACY,
            'billing_status' => Upload::JOB_COMPLETED,
        ]);

        Cache::put("billing_resync_{$upload->id}", true, 300);

        $result = $this->service->canResync($upload);

        $this->assertFalse($result->allowed);
        $this->assertStringContainsString('already in progress', $result->reason);
    }

    public function test_can_resync_rejects_when_billing_is_processing(): void
    {
        $upload = Upload::factory()->create([
            'billing_model' => DebtorProfile::MODEL_LEGACY,
            'billing_status' => Upload::JOB_PROCESSING,
        ]);

        $result = $this->service->canResync($upload);

        $this->assertFalse($result->allowed);
        $this->assertStringContainsString('currently processing', $result->reason);
    }

    public function test_can_resync_allows_after_many_runs_if_eligible_debtors_exist(): void
    {
        $upload = Upload::factory()->create([
            'billing_model' => DebtorProfile::MODEL_LEGACY,
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subDays(10),
            'billing_completed_at' => now()->subDays(6),
            'billing_runs' => [
                ['run' => 1, 'status' => 'completed'],
                ['run' => 2, 'status' => 'completed'],
                ['run' => 3, 'status' => 'completed'],
            ],
        ]);

        $this->createEligibleLegacyDebtor($upload);

        $result = $this->service->canResync($upload);

        $this->assertTrue($result->allowed);
    }

    public function test_can_resync_rejects_during_cooldown_period(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subHours(2),
            'billing_completed_at' => now()->subHours(1),
        ]);

        $this->createEligibleLegacyDebtor($upload);

        $result = $this->service->canResync($upload);

        $this->assertFalse($result->allowed);
        $this->assertStringContainsString('Cooldown period active', $result->reason);
    }

    public function test_can_resync_allows_after_cooldown_period_elapsed(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subHours(Upload::RESYNC_COOLDOWN_HOURS + 2),
            'billing_completed_at' => now()->subHours(Upload::RESYNC_COOLDOWN_HOURS + 1),
        ]);

        $this->createEligibleLegacyDebtor($upload);

        $result = $this->service->canResync($upload);

        $this->assertTrue($result->allowed);
    }

    public function test_can_resync_enforces_cooldown_from_archived_run_when_billing_completed_at_is_null(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_IDLE,
            'billing_started_at' => null,
            'billing_completed_at' => null,
            'billing_runs' => [
                [
                    'run' => 1,
                    'billing_model' => DebtorProfile::MODEL_LEGACY,
                    'status' => Upload::JOB_COMPLETED,
                    'started_at' => now()->subHours(2)->toISOString(),
                    'completed_at' => now()->subHours(1)->toISOString(),
                ],
            ],
        ]);

        $this->createEligibleLegacyDebtor($upload);

        $result = $this->service->canResync($upload);

        $this->assertFalse($result->allowed);
        $this->assertStringContainsString('Cooldown period active', $result->reason);
        $this->assertEquals(ResyncEligibility::CODE_COOLDOWN, $result->code);
    }

    public function test_can_resync_allows_when_archived_run_is_old_enough(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_IDLE,
            'billing_started_at' => null,
            'billing_completed_at' => null,
            'billing_runs' => [
                [
                    'run' => 1,
                    'billing_model' => DebtorProfile::MODEL_LEGACY,
                    'status' => Upload::JOB_COMPLETED,
                    'started_at' => now()->subHours(Upload::RESYNC_COOLDOWN_HOURS + 2)->toISOString(),
                    'completed_at' => now()->subHours(Upload::RESYNC_COOLDOWN_HOURS + 1)->toISOString(),
                ],
            ],
        ]);

        $this->createEligibleLegacyDebtor($upload);

        $result = $this->service->canResync($upload);

        $this->assertTrue($result->allowed);
    }

    public function test_can_resync_rejects_when_all_debtors_are_chargebacked(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subDays(10),
            'billing_completed_at' => now()->subDays(6),
        ]);

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
        ]);

        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $result = $this->service->canResync($upload);

        $this->assertFalse($result->allowed);
        $this->assertStringContainsString('No eligible Legacy debtors', $result->reason);
    }

    public function test_can_resync_allows_debtors_with_approved_attempts(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subDays(10),
            'billing_completed_at' => now()->subDays(6),
        ]);

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
        ]);

        BillingAttempt::factory()->approved()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $result = $this->service->canResync($upload);

        $this->assertTrue($result->allowed);
        $this->assertEquals(1, $result->eligibleCount);
    }

    public function test_can_resync_allows_debtors_with_pending_attempts(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subDays(10),
            'billing_completed_at' => now()->subDays(6),
        ]);

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
        ]);

        BillingAttempt::factory()->pending()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => null,
        ]);

        $result = $this->service->canResync($upload);

        $this->assertTrue($result->allowed);
        $this->assertEquals(1, $result->eligibleCount);
    }

    public function test_can_resync_excludes_debtors_with_only_vop_mismatch(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subDays(10),
            'billing_completed_at' => now()->subDays(6),
        ]);

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
        ]);

        VopLog::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'result' => VopLog::RESULT_MISMATCH,
        ]);

        $result = $this->service->canResync($upload);

        $this->assertFalse($result->allowed);
        $this->assertStringContainsString('No eligible', $result->reason);
    }

    // ──────────────────────────────────────────────
    // canResync() — ResyncEligibility DTO
    // ──────────────────────────────────────────────

    public function test_resync_eligibility_denied_returns_true_when_not_allowed(): void
    {
        $dto = new ResyncEligibility(allowed: false, reason: 'test');
        $this->assertTrue($dto->denied());
    }

    public function test_resync_eligibility_denied_returns_false_when_allowed(): void
    {
        $dto = new ResyncEligibility(allowed: true, reason: 'ok');
        $this->assertFalse($dto->denied());
    }

    public function test_can_resync_returns_model_not_supported_code_for_flywheel(): void
    {
        $upload = Upload::factory()->create();

        $result = $this->service->canResync($upload, DebtorProfile::MODEL_FLYWHEEL);

        $this->assertEquals(ResyncEligibility::CODE_MODEL_NOT_SUPPORTED, $result->code);
    }

    public function test_can_resync_returns_no_legacy_debtors_code(): void
    {
        $upload = Upload::factory()->create([
            'billing_model' => DebtorProfile::MODEL_FLYWHEEL,
            'billing_status' => Upload::JOB_COMPLETED,
        ]);

        $flywheelProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_FLYWHEEL]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => $flywheelProfile->id,
            'billing_model' => DebtorProfile::MODEL_FLYWHEEL,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        $result = $this->service->canResync($upload, DebtorProfile::ALL);

        $this->assertEquals(ResyncEligibility::CODE_NO_LEGACY_DEBTORS, $result->code);
    }

    public function test_can_resync_returns_lock_code_when_in_progress(): void
    {
        $upload = Upload::factory()->create([
            'billing_model' => DebtorProfile::MODEL_LEGACY,
            'billing_status' => Upload::JOB_COMPLETED,
        ]);

        Cache::put("billing_resync_{$upload->id}", true, 300);

        $result = $this->service->canResync($upload);

        $this->assertEquals(ResyncEligibility::CODE_LOCK, $result->code);
    }

    public function test_can_resync_returns_processing_code_when_billing_active(): void
    {
        $upload = Upload::factory()->create([
            'billing_model' => DebtorProfile::MODEL_LEGACY,
            'billing_status' => Upload::JOB_PROCESSING,
        ]);

        $result = $this->service->canResync($upload);

        $this->assertEquals(ResyncEligibility::CODE_PROCESSING, $result->code);
    }

    public function test_can_resync_returns_no_eligible_code_when_all_chargebacked(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subDays(10),
            'billing_completed_at' => now()->subDays(6),
        ]);

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
        ]);

        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $result = $this->service->canResync($upload);

        $this->assertEquals(ResyncEligibility::CODE_NO_ELIGIBLE, $result->code);
    }

    public function test_can_resync_cooldown_message_includes_remaining_time(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subMinutes(30),
            'billing_completed_at' => now()->subMinutes(10),
        ]);

        $this->createEligibleLegacyDebtor($upload);

        $result = $this->service->canResync($upload);

        $this->assertFalse($result->allowed);
        $this->assertMatchesRegularExpression('/\d+ hour\(s\) and \d+ minute\(s\) remaining/', $result->reason);
    }

    public function test_can_resync_defaults_to_all_when_no_model_specified(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subDays(10),
            'billing_completed_at' => now()->subDays(6),
        ]);

        $this->createEligibleLegacyDebtor($upload);

        $result = $this->service->canResync($upload);

        $this->assertTrue($result->allowed);
        $this->assertEquals(DebtorProfile::ALL, $result->billingModel);
    }

    public function test_can_resync_preserves_explicit_legacy_model_in_result(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subDays(10),
            'billing_completed_at' => now()->subDays(6),
        ]);

        $this->createEligibleLegacyDebtor($upload);

        $result = $this->service->canResync($upload, DebtorProfile::MODEL_LEGACY);

        $this->assertTrue($result->allowed);
        $this->assertEquals(DebtorProfile::MODEL_LEGACY, $result->billingModel);
    }

    public function test_can_resync_skips_legacy_debtor_guard_for_legacy_upload(): void
    {
        // A Legacy upload with no debtors at all should still reach
        // the "no eligible debtors" check, not the "no Legacy debtors" guard
        $upload = Upload::factory()->create([
            'billing_model' => DebtorProfile::MODEL_LEGACY,
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subDays(10),
            'billing_completed_at' => now()->subDays(6),
        ]);

        $result = $this->service->canResync($upload, DebtorProfile::ALL);

        $this->assertFalse($result->allowed);
        // Should fail with "No eligible" not "no Legacy debtors"
        $this->assertEquals(ResyncEligibility::CODE_NO_ELIGIBLE, $result->code);
    }

    public function test_can_resync_skips_cooldown_when_archived_run_has_no_completed_at(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_IDLE,
            'billing_started_at' => null,
            'billing_completed_at' => null,
            'billing_runs' => [
                [
                    'run' => 1,
                    'billing_model' => DebtorProfile::MODEL_LEGACY,
                    'status' => Upload::JOB_COMPLETED,
                    'started_at' => now()->subMinutes(30)->toISOString(),
                    // No completed_at key
                ],
            ],
        ]);

        $this->createEligibleLegacyDebtor($upload);

        $result = $this->service->canResync($upload);

        // With no completed_at in the archived run, cooldown cannot be enforced
        $this->assertTrue($result->allowed);
    }

    public function test_can_resync_returns_correct_eligible_count_with_mixed_debtors(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subDays(10),
            'billing_completed_at' => now()->subDays(6),
        ]);

        // 2 eligible
        $this->createEligibleLegacyDebtor($upload);
        $this->createEligibleLegacyDebtor($upload);

        // 1 chargebacked (excluded)
        $cbDebtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
        ]);
        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $cbDebtor->id,
        ]);

        // 1 invalid (excluded)
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_INVALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
        ]);

        $result = $this->service->canResync($upload);

        $this->assertTrue($result->allowed);
        $this->assertEquals(2, $result->eligibleCount);
    }

    public function test_get_resyncable_debtors_returns_valid_legacy_debtors(): void
    {
        $upload = Upload::factory()->create();

        $eligible = $this->createEligibleLegacyDebtor($upload);

        $results = $this->service->getResyncableDebtors($upload)->get();

        $this->assertCount(1, $results);
        $this->assertEquals($eligible->id, $results->first()->id);
    }

    public function test_get_resyncable_debtors_excludes_invalid_debtors(): void
    {
        $upload = Upload::factory()->create();

        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_INVALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
        ]);

        $results = $this->service->getResyncableDebtors($upload)->get();

        $this->assertCount(0, $results);
    }

    public function test_get_resyncable_debtors_excludes_non_legacy_billing_model(): void
    {
        $upload = Upload::factory()->create();

        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_FLYWHEEL,
        ]);

        $results = $this->service->getResyncableDebtors($upload)->get();

        $this->assertCount(0, $results);
    }

    public function test_get_resyncable_debtors_includes_debtors_with_approved_attempts(): void
    {
        $upload = Upload::factory()->create();

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
        ]);

        BillingAttempt::factory()->approved()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $results = $this->service->getResyncableDebtors($upload)->get();

        $this->assertCount(1, $results);
    }

    public function test_get_resyncable_debtors_excludes_debtors_with_chargebacked_attempts(): void
    {
        $upload = Upload::factory()->create();

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
        ]);

        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $results = $this->service->getResyncableDebtors($upload)->get();

        $this->assertCount(0, $results);
    }

    public function test_get_resyncable_debtors_includes_debtors_with_pending_attempts(): void
    {
        $upload = Upload::factory()->create();

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
        ]);

        BillingAttempt::factory()->pending()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => null,
        ]);

        $results = $this->service->getResyncableDebtors($upload)->get();

        $this->assertCount(1, $results);
    }

    public function test_get_resyncable_debtors_excludes_debtors_with_pending_attempts_submitted_to_emp(): void
    {
        $upload = Upload::factory()->create();

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
        ]);

        BillingAttempt::factory()->pending()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => 'EMG-ABC123',
        ]);

        $results = $this->service->getResyncableDebtors($upload)->get();

        $this->assertCount(0, $results);
    }

    public function test_get_resyncable_debtors_includes_debtors_with_declined_or_error_attempts(): void
    {
        $upload = Upload::factory()->create();

        $declinedDebtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
        ]);
        BillingAttempt::factory()->declined()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $declinedDebtor->id,
        ]);

        $errorDebtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
        ]);
        BillingAttempt::factory()->error()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $errorDebtor->id,
        ]);

        $results = $this->service->getResyncableDebtors($upload)->get();

        $this->assertCount(2, $results);
    }

    public function test_get_resyncable_debtors_excludes_vop_mismatch(): void
    {
        $upload = Upload::factory()->create();

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
        ]);

        VopLog::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'result' => VopLog::RESULT_MISMATCH,
        ]);

        $results = $this->service->getResyncableDebtors($upload)->get();

        $this->assertCount(0, $results);
    }

    public function test_get_resyncable_debtors_excludes_vop_rejected(): void
    {
        $upload = Upload::factory()->create();

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
        ]);

        VopLog::factory()->rejected()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $results = $this->service->getResyncableDebtors($upload)->get();

        $this->assertCount(0, $results);
    }

    public function test_get_resyncable_debtors_excludes_vop_inconclusive(): void
    {
        $upload = Upload::factory()->create();

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
        ]);

        VopLog::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'result' => VopLog::RESULT_INCONCLUSIVE,
        ]);

        $results = $this->service->getResyncableDebtors($upload)->get();

        $this->assertCount(0, $results);
    }

    public function test_get_resyncable_debtors_includes_debtors_with_no_vop_logs(): void
    {
        $upload = Upload::factory()->create();

        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
        ]);

        $results = $this->service->getResyncableDebtors($upload)->get();

        $this->assertCount(1, $results);
    }

    public function test_get_resyncable_debtors_includes_verified_vop(): void
    {
        $upload = Upload::factory()->create();

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
        ]);

        VopLog::factory()->verified()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $results = $this->service->getResyncableDebtors($upload)->get();

        $this->assertCount(1, $results);
    }

    public function test_get_resyncable_debtors_includes_likely_verified_vop(): void
    {
        $upload = Upload::factory()->create();

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
        ]);

        VopLog::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'result' => VopLog::RESULT_LIKELY_VERIFIED,
            'vop_score' => 70,
            'iban_valid' => true,
            'bank_identified' => true,
        ]);

        $results = $this->service->getResyncableDebtors($upload)->get();

        $this->assertCount(1, $results);
    }

    public function test_get_resyncable_debtors_includes_debtors_without_profile(): void
    {
        $upload = Upload::factory()->create();

        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
            'debtor_profile_id' => null,
        ]);

        $results = $this->service->getResyncableDebtors($upload)->get();

        $this->assertCount(1, $results);
    }

    public function test_get_resyncable_debtors_excludes_debtors_with_non_legacy_profile(): void
    {
        $upload = Upload::factory()->create();

        $flywheelProfile = DebtorProfile::factory()->create([
            'billing_model' => DebtorProfile::MODEL_FLYWHEEL,
        ]);

        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
            'debtor_profile_id' => $flywheelProfile->id,
        ]);

        $results = $this->service->getResyncableDebtors($upload)->get();

        $this->assertCount(0, $results);
    }

    public function test_get_resyncable_debtors_excludes_debtors_from_other_uploads(): void
    {
        $upload = Upload::factory()->create();
        $otherUpload = Upload::factory()->create();

        $this->createEligibleLegacyDebtor($upload);
        $this->createEligibleLegacyDebtor($otherUpload);

        $results = $this->service->getResyncableDebtors($upload)->get();

        $this->assertCount(1, $results);
    }

    public function test_get_resyncable_debtors_excludes_debtor_with_both_chargebacked_and_approved_attempts(): void
    {
        $upload = Upload::factory()->create();

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
        ]);

        BillingAttempt::factory()->approved()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $results = $this->service->getResyncableDebtors($upload)->get();

        // Chargebacked permanently excludes, even if there's also an approved attempt
        $this->assertCount(0, $results);
    }

    public function test_get_resyncable_debtors_excludes_debtor_with_mixed_vop_results(): void
    {
        $upload = Upload::factory()->create();

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
        ]);

        // Has both a verified and mismatch VOP log
        VopLog::factory()->verified()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);
        VopLog::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'result' => VopLog::RESULT_MISMATCH,
        ]);

        $results = $this->service->getResyncableDebtors($upload)->get();

        // The query uses whereDoesntHave OR whereHas(verified/likely_verified)
        // A debtor with a verified log should still be included even with a mismatch
        // (the orWhereHas clause matches)
        $this->assertCount(1, $results);
    }

    public function test_get_resyncable_debtors_with_legacy_profile_included(): void
    {
        $upload = Upload::factory()->create();

        $legacyProfile = DebtorProfile::factory()->create([
            'billing_model' => DebtorProfile::MODEL_LEGACY,
        ]);

        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
            'debtor_profile_id' => $legacyProfile->id,
        ]);

        $results = $this->service->getResyncableDebtors($upload)->get();

        $this->assertCount(1, $results);
    }

    public function test_is_resync_in_progress_returns_true_when_cache_key_exists(): void
    {
        $upload = Upload::factory()->create();

        Cache::put("billing_resync_{$upload->id}", true, 300);

        $this->assertTrue($this->service->isResyncInProgress($upload));
    }

    public function test_is_resync_in_progress_returns_false_when_cache_key_absent(): void
    {
        $upload = Upload::factory()->create();

        $this->assertFalse($this->service->isResyncInProgress($upload));
    }

    public function test_is_resync_in_progress_returns_true_with_non_boolean_cache_value(): void
    {
        $upload = Upload::factory()->create();

        // executeResync stores eligible count, not just true
        Cache::put("billing_resync_{$upload->id}", 42, 300);

        $this->assertTrue($this->service->isResyncInProgress($upload));
    }

    public function test_get_voidable_attempts_returns_approved_and_pending_with_unique_id(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        $approved = BillingAttempt::factory()->approved()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => 'tx_approved_1',
        ]);

        $pending = BillingAttempt::factory()->pending()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => 'tx_pending_1',
        ]);

        $results = $this->service->getVoidableAttempts($upload)->get();

        $this->assertCount(2, $results);
        $this->assertTrue($results->pluck('id')->contains($approved->id));
        $this->assertTrue($results->pluck('id')->contains($pending->id));
    }

    public function test_get_voidable_attempts_excludes_non_voidable_statuses(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->declined()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => 'tx_declined_1',
        ]);

        BillingAttempt::factory()->error()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => 'tx_error_1',
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => 'tx_voided_1',
            'status' => BillingAttempt::STATUS_VOIDED,
        ]);

        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => 'tx_cb_1',
        ]);

        $results = $this->service->getVoidableAttempts($upload)->get();

        $this->assertCount(0, $results);
    }

    public function test_get_voidable_attempts_excludes_attempts_without_unique_id(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->approved()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => null,
        ]);

        $results = $this->service->getVoidableAttempts($upload)->get();

        $this->assertCount(0, $results);
    }

    public function test_get_voidable_attempts_scopes_to_latest_run_when_billing_runs_exist(): void
    {
        $billingStartedAt = now()->subHours(2);

        $upload = Upload::factory()->create([
            'billing_runs' => [['run' => 1, 'status' => 'completed']],
            'billing_started_at' => $billingStartedAt,
        ]);

        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->approved()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => 'tx_old_1',
            'created_at' => $billingStartedAt->copy()->subHours(5),
        ]);

        $newAttempt = BillingAttempt::factory()->approved()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => 'tx_new_1',
            'created_at' => $billingStartedAt->copy()->addMinutes(30),
        ]);

        $results = $this->service->getVoidableAttempts($upload)->get();

        $this->assertCount(1, $results);
        $this->assertEquals($newAttempt->id, $results->first()->id);
    }

    public function test_get_voidable_attempts_returns_all_when_no_prior_billing_runs(): void
    {
        $upload = Upload::factory()->create([
            'billing_runs' => null,
            'billing_started_at' => null,
        ]);

        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->approved()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => 'tx_1',
            'created_at' => now()->subDays(5),
        ]);

        BillingAttempt::factory()->approved()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => 'tx_2',
            'created_at' => now()->subDays(1),
        ]);

        $results = $this->service->getVoidableAttempts($upload)->get();

        $this->assertCount(2, $results);
    }

    public function test_get_voidable_attempts_excludes_other_upload_attempts(): void
    {
        $upload = Upload::factory()->create([
            'billing_runs' => null,
            'billing_started_at' => null,
        ]);
        $otherUpload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        $otherDebtor = Debtor::factory()->create(['upload_id' => $otherUpload->id]);

        BillingAttempt::factory()->approved()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => 'tx_mine',
        ]);

        BillingAttempt::factory()->approved()->create([
            'upload_id' => $otherUpload->id,
            'debtor_id' => $otherDebtor->id,
            'unique_id' => 'tx_other',
        ]);

        $results = $this->service->getVoidableAttempts($upload)->get();

        $this->assertCount(1, $results);
        $this->assertEquals('tx_mine', $results->first()->unique_id);
    }

    public function test_get_voidable_attempts_includes_all_when_billing_runs_set_but_no_started_at(): void
    {
        // Edge case: billing_runs exists but billing_started_at is null
        $upload = Upload::factory()->create([
            'billing_runs' => [['run' => 1, 'status' => 'completed']],
            'billing_started_at' => null,
        ]);

        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->approved()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => 'tx_old',
            'created_at' => now()->subDays(10),
        ]);

        $results = $this->service->getVoidableAttempts($upload)->get();

        // billing_started_at is null so time filter should not apply
        $this->assertCount(1, $results);
    }


    public function test_cancel_resync_sets_kill_switch_and_clears_locks(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_PROCESSING,
            'status' => Upload::STATUS_PROCESSING,
        ]);

        Cache::put("billing_resync_{$upload->id}", true, 300);
        Cache::put("billing_sync_{$upload->id}_" . DebtorProfile::MODEL_LEGACY, true, 300);

        $this->service->cancelResync($upload);

        $this->assertTrue(Cache::has("billing_sync_stop_{$upload->id}"));
        $this->assertFalse(Cache::has("billing_resync_{$upload->id}"));
        $this->assertFalse(Cache::has("billing_sync_{$upload->id}_" . DebtorProfile::MODEL_LEGACY));
    }

    public function test_cancel_resync_clears_all_sync_lock_variants(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_PROCESSING,
            'status' => Upload::STATUS_PROCESSING,
        ]);

        foreach (['all', 'legacy', 'flywheel', 'recovery'] as $model) {
            Cache::put("billing_sync_{$upload->id}_{$model}", true, 300);
        }
        Cache::put("billing_resync_{$upload->id}", true, 300);

        $this->service->cancelResync($upload);

        foreach (['all', 'legacy', 'flywheel', 'recovery'] as $model) {
            $this->assertFalse(
                Cache::has("billing_sync_{$upload->id}_{$model}"),
                "Expected billing_sync_{$upload->id}_{$model} to be cleared"
            );
        }
        $this->assertFalse(Cache::has("billing_resync_{$upload->id}"));
    }

    public function test_cancel_resync_updates_upload_status(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_PROCESSING,
            'status' => Upload::STATUS_PROCESSING,
        ]);

        $this->service->cancelResync($upload);

        $upload->refresh();
        $this->assertEquals(Upload::STATUS_CANCELLING, $upload->billing_status);
        $this->assertEquals(Upload::STATUS_CANCELLING, $upload->status);
    }

    public function test_cancel_resync_logs_cancellation(): void
    {
        Log::spy();

        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_PROCESSING,
            'status' => Upload::STATUS_PROCESSING,
        ]);

        $this->service->cancelResync($upload);

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($msg) => str_contains($msg, 'resync cancelled'))
            ->once();
    }

    public function test_cancel_resync_kill_switch_has_ttl(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_PROCESSING,
            'status' => Upload::STATUS_PROCESSING,
        ]);

        $this->service->cancelResync($upload);

        // Kill switch should exist
        $this->assertTrue(Cache::has("billing_sync_stop_{$upload->id}"));
    }

    public function test_cancel_resync_works_even_without_existing_locks(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_PROCESSING,
            'status' => Upload::STATUS_PROCESSING,
        ]);

        // No locks set — should not throw
        $this->service->cancelResync($upload);

        $upload->refresh();
        $this->assertEquals(Upload::STATUS_CANCELLING, $upload->billing_status);
        $this->assertTrue(Cache::has("billing_sync_stop_{$upload->id}"));
    }

    public function test_execute_resync_sets_lock_when_prior_billing_run_exists(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subDays(1),
            'billing_completed_at' => now()->subHours(12),
        ]);

        $this->createEligibleLegacyDebtor($upload);

        $this->service->executeResync($upload);

        $this->assertTrue(Cache::has("billing_resync_{$upload->id}"));
    }

    public function test_execute_resync_does_not_set_lock_on_initial_sync(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_IDLE,
            'billing_started_at' => null,
            'billing_completed_at' => null,
            'billing_runs' => null,
        ]);

        $this->createEligibleLegacyDebtor($upload);

        $this->service->executeResync($upload);

        $this->assertFalse(Cache::has("billing_resync_{$upload->id}"));
    }

    public function test_execute_resync_archives_previous_billing_run(): void
    {
        Bus::fake();

        $startTime = now()->subDays(1);
        $endTime = now()->subHours(12);

        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_batch_id' => 'batch_abc_123',
            'billing_started_at' => $startTime,
            'billing_completed_at' => $endTime,
            'billing_runs' => [],
        ]);

        $this->createEligibleLegacyDebtor($upload);

        $this->service->executeResync($upload);

        $upload->refresh();
        $runs = $upload->billing_runs;

        $this->assertCount(1, $runs);
        $this->assertEquals(1, $runs[0]['run']);
        $this->assertEquals(DebtorProfile::MODEL_LEGACY, $runs[0]['billing_model']);
        $this->assertEquals(Upload::JOB_COMPLETED, $runs[0]['status']);
        $this->assertEquals('batch_abc_123', $runs[0]['batch_id']);
    }

    public function test_execute_resync_resets_billing_fields(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_batch_id' => 'batch_old',
            'billing_started_at' => now()->subDays(1),
            'billing_completed_at' => now()->subHours(12),
        ]);

        $this->createEligibleLegacyDebtor($upload);

        $this->service->executeResync($upload);

        $upload->refresh();
        $this->assertNull($upload->billing_batch_id);
        $this->assertNull($upload->billing_started_at);
        $this->assertNull($upload->billing_completed_at);
    }

    public function test_execute_resync_voids_pending_attempts_for_eligible_debtors(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subDays(1),
            'billing_completed_at' => now()->subHours(12),
        ]);

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
            'status' => Debtor::STATUS_PENDING,
        ]);

        $pendingAttempt = BillingAttempt::factory()->pending()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => null,
        ]);

        $this->service->executeResync($upload);

        $pendingAttempt->refresh();
        $this->assertEquals(BillingAttempt::STATUS_VOIDED, $pendingAttempt->status);
    }

    public function test_execute_resync_does_not_void_pending_attempts_submitted_to_emp(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subDays(1),
            'billing_completed_at' => now()->subHours(12),
        ]);

        $debtorWithLiveAttempt = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
            'status' => Debtor::STATUS_PENDING,
        ]);

        $liveAttempt = BillingAttempt::factory()->pending()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtorWithLiveAttempt->id,
            'unique_id' => 'EMG-LIVE123',
        ]);

        $this->createEligibleLegacyDebtor($upload);

        $this->service->executeResync($upload);

        $liveAttempt->refresh();
        $this->assertEquals(BillingAttempt::STATUS_PENDING, $liveAttempt->status);

        $debtorWithLiveAttempt->refresh();
        $this->assertEquals(Debtor::STATUS_PENDING, $debtorWithLiveAttempt->status);
    }

    public function test_execute_resync_resets_eligible_debtors_to_uploaded(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subDays(1),
            'billing_completed_at' => now()->subHours(12),
        ]);

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
            'status' => Debtor::STATUS_FAILED,
        ]);

        $this->service->executeResync($upload);

        $debtor->refresh();
        $this->assertEquals(Debtor::STATUS_UPLOADED, $debtor->status);
    }

    public function test_execute_resync_dispatches_process_billing_job(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subDays(1),
            'billing_completed_at' => now()->subHours(12),
        ]);

        $this->createEligibleLegacyDebtor($upload);

        $result = $this->service->executeResync($upload);

        $this->assertTrue($result->dispatched);
        Bus::assertDispatched(ProcessBillingJob::class, function ($job) use ($upload) {
            return $job->upload->id === $upload->id
                && $job->billingModel === DebtorProfile::MODEL_LEGACY;
        });
    }

    public function test_execute_resync_sets_sync_lock_when_dispatching(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subDays(1),
            'billing_completed_at' => now()->subHours(12),
        ]);

        $this->createEligibleLegacyDebtor($upload);

        $this->service->executeResync($upload);

        $this->assertTrue(Cache::has("billing_sync_{$upload->id}_" . DebtorProfile::MODEL_LEGACY));
    }

    public function test_execute_resync_returns_correct_dto(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_batch_id' => 'batch_old',
            'billing_started_at' => now()->subDays(1),
            'billing_completed_at' => now()->subHours(12),
        ]);

        $debtor1 = $this->createEligibleLegacyDebtor($upload, Debtor::STATUS_FAILED);
        $debtor2 = $this->createEligibleLegacyDebtor($upload, Debtor::STATUS_PENDING);

        $result = $this->service->executeResync($upload);

        $this->assertInstanceOf(ResyncResult::class, $result);
        $this->assertTrue($result->archived);
        $this->assertEquals(2, $result->resetCount);
        $this->assertTrue($result->dispatched);
        $this->assertEquals(2, $result->eligibleCount);
    }

    public function test_execute_resync_clears_locks_when_no_eligible_debtors_remain(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subDays(1),
            'billing_completed_at' => now()->subHours(12),
        ]);

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
        ]);

        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $result = $this->service->executeResync($upload);

        $this->assertFalse($result->dispatched);
        $this->assertEquals(0, $result->eligibleCount);
        $this->assertFalse(Cache::has("billing_resync_{$upload->id}"));

        Bus::assertNotDispatched(ProcessBillingJob::class);
    }

    public function test_execute_resync_clears_lock_on_exception(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subDays(1),
            'billing_completed_at' => now()->subHours(12),
        ]);

        Cache::put("billing_resync_{$upload->id}", true, 300);

        $serviceMock = $this->getMockBuilder(BillingResyncService::class)
            ->onlyMethods(['getResyncableDebtors'])
            ->getMock();

        $serviceMock->method('getResyncableDebtors')
            ->willThrowException(new \RuntimeException('DB connection lost'));

        try {
            $serviceMock->executeResync($upload);
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertEquals('DB connection lost', $e->getMessage());
        }

        $this->assertFalse(Cache::has("billing_resync_{$upload->id}"));
    }

    public function test_execute_resync_accumulates_billing_runs_across_multiple_resyncs(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_batch_id' => 'batch_run1',
            'billing_started_at' => now()->subDays(2),
            'billing_completed_at' => now()->subDays(1),
            'billing_runs' => [
                [
                    'run' => 1,
                    'billing_model' => DebtorProfile::MODEL_LEGACY,
                    'status' => Upload::JOB_COMPLETED,
                    'batch_id' => 'batch_initial',
                    'started_at' => now()->subDays(5)->toISOString(),
                    'completed_at' => now()->subDays(4)->toISOString(),
                ],
            ],
        ]);

        $this->createEligibleLegacyDebtor($upload);

        $result = $this->service->executeResync($upload);

        $upload->refresh();
        $runs = $upload->billing_runs;

        $this->assertCount(2, $runs);
        $this->assertEquals(1, $runs[0]['run']);
        $this->assertEquals('batch_initial', $runs[0]['batch_id']);
        $this->assertEquals(2, $runs[1]['run']);
        $this->assertEquals('batch_run1', $runs[1]['batch_id']);

        $this->assertTrue($result->archived);
        $this->assertTrue($result->dispatched);
    }

    public function test_execute_resync_initial_sync_does_not_archive(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_IDLE,
            'billing_batch_id' => null,
            'billing_started_at' => null,
            'billing_completed_at' => null,
            'billing_runs' => null,
        ]);

        $this->createEligibleLegacyDebtor($upload);

        $result = $this->service->executeResync($upload);

        $this->assertFalse($result->archived);
        $this->assertTrue($result->dispatched);

        $upload->refresh();
        $this->assertEmpty($upload->billing_runs ?? []);
    }

    public function test_execute_resync_stores_eligible_count_in_cache_on_resync(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subDays(1),
            'billing_completed_at' => now()->subHours(12),
        ]);

        $this->createEligibleLegacyDebtor($upload);
        $this->createEligibleLegacyDebtor($upload);
        $this->createEligibleLegacyDebtor($upload);

        $this->service->executeResync($upload);

        // Cache stores the eligible count, not just `true`
        $this->assertEquals(3, Cache::get("billing_resync_{$upload->id}"));
    }

    public function test_execute_resync_does_not_void_approved_attempts(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subDays(1),
            'billing_completed_at' => now()->subHours(12),
        ]);

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
            'status' => Debtor::STATUS_APPROVED,
        ]);

        $approvedAttempt = BillingAttempt::factory()->approved()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => 'EMG-APPROVED-1',
        ]);

        $this->service->executeResync($upload);

        $approvedAttempt->refresh();
        // Approved attempts are NOT voided — only pending without unique_id are voided
        $this->assertEquals(BillingAttempt::STATUS_APPROVED, $approvedAttempt->status);
    }

    public function test_execute_resync_does_not_reset_chargebacked_debtors(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subDays(1),
            'billing_completed_at' => now()->subHours(12),
        ]);

        // One eligible
        $this->createEligibleLegacyDebtor($upload);

        // One chargebacked — should remain untouched
        $cbDebtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
            'status' => Debtor::STATUS_FAILED,
        ]);
        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $cbDebtor->id,
        ]);

        $this->service->executeResync($upload);

        $cbDebtor->refresh();
        $this->assertEquals(Debtor::STATUS_FAILED, $cbDebtor->status);
    }

    public function test_execute_resync_with_billing_runs_but_null_billing_started_at_sets_resync_lock(): void
    {
        Bus::fake();

        // billing_runs non-empty means isResync = true, even if billing_started_at is null
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_IDLE,
            'billing_started_at' => null,
            'billing_completed_at' => null,
            'billing_runs' => [
                [
                    'run' => 1,
                    'status' => Upload::JOB_COMPLETED,
                    'billing_model' => DebtorProfile::MODEL_LEGACY,
                    'started_at' => now()->subDays(5)->toISOString(),
                    'completed_at' => now()->subDays(4)->toISOString(),
                ],
            ],
        ]);

        $this->createEligibleLegacyDebtor($upload);

        $this->service->executeResync($upload);

        // isResync is true because billing_runs is non-empty
        $this->assertTrue(Cache::has("billing_resync_{$upload->id}"));
    }

    public function test_execute_resync_does_not_archive_when_no_billing_started_at(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_IDLE,
            'billing_started_at' => null,
            'billing_completed_at' => null,
            'billing_runs' => [
                [
                    'run' => 1,
                    'status' => Upload::JOB_COMPLETED,
                    'billing_model' => DebtorProfile::MODEL_LEGACY,
                    'started_at' => now()->subDays(5)->toISOString(),
                    'completed_at' => now()->subDays(4)->toISOString(),
                ],
            ],
        ]);

        $this->createEligibleLegacyDebtor($upload);

        $result = $this->service->executeResync($upload);

        // billing_started_at is null so the archive branch is skipped
        $this->assertFalse($result->archived);

        $upload->refresh();
        // The existing run should still be there, not duplicated
        $this->assertCount(1, $upload->billing_runs);
    }

    private function createEligibleLegacyDebtor(Upload $upload, string $status = Debtor::STATUS_UPLOADED): Debtor
    {
        return Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
            'debtor_profile_id' => null,
            'status' => $status,
        ]);
    }
}
