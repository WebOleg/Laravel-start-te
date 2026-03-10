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

        // Create a non-Legacy debtor only
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

    public function test_can_resync_rejects_when_resync_cap_reached(): void
    {
        $upload = Upload::factory()->create([
            'billing_model' => DebtorProfile::MODEL_LEGACY,
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_runs' => [
                ['run' => 1, 'status' => 'completed'],
                ['run' => 2, 'status' => 'completed'],
                ['run' => 3, 'status' => 'completed'],
            ],
        ]);

        $result = $this->service->canResync($upload);

        $this->assertFalse($result->allowed);
        $this->assertStringContainsString('Resync limit reached', $result->reason);
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
        // After archive, billing_completed_at is nulled — cooldown must check billing_runs
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
        // Archived run completed_at is beyond cooldown period
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

    public function test_can_resync_rejects_when_all_debtors_have_terminal_attempts(): void
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

        $this->assertFalse($result->allowed);
        $this->assertStringContainsString('No eligible Legacy debtors', $result->reason);
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

        // Pending attempts without unique_id are treated as stuck/retriable — should still be eligible
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

    // ──────────────────────────────────────────────
    // getResyncableDebtors()
    // ──────────────────────────────────────────────

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

    public function test_get_resyncable_debtors_excludes_debtors_with_approved_attempts(): void
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

        $this->assertCount(0, $results);
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

        // Pending attempt without unique_id (not yet submitted to EMP) — eligible
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

        // Pending attempt with unique_id (already submitted to EMP) — excluded from resync
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

    // ──────────────────────────────────────────────
    // isResyncInProgress()
    // ──────────────────────────────────────────────

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

    // ──────────────────────────────────────────────
    // getVoidableAttempts()
    // ──────────────────────────────────────────────

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

        // Old attempt from before the latest run — should be excluded
        BillingAttempt::factory()->approved()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => 'tx_old_1',
            'created_at' => $billingStartedAt->copy()->subHours(5),
        ]);

        // New attempt from latest run — should be included
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

    // ──────────────────────────────────────────────
    // cancelResync()
    // ──────────────────────────────────────────────

    public function test_cancel_resync_sets_kill_switch_and_clears_locks(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_PROCESSING,
            'status' => Upload::STATUS_PROCESSING,
        ]);

        // Pre-populate locks to verify they get cleared
        Cache::put("billing_resync_{$upload->id}", true, 300);
        Cache::put("billing_sync_{$upload->id}_" . DebtorProfile::MODEL_LEGACY, true, 300);

        $this->service->cancelResync($upload);

        // Kill switch should be set
        $this->assertTrue(Cache::has("billing_sync_stop_{$upload->id}"));

        // Resync locks should be cleared
        $this->assertFalse(Cache::has("billing_resync_{$upload->id}"));
        $this->assertFalse(Cache::has("billing_sync_{$upload->id}_" . DebtorProfile::MODEL_LEGACY));
    }

    public function test_cancel_resync_clears_all_sync_lock_variants(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_PROCESSING,
            'status' => Upload::STATUS_PROCESSING,
        ]);

        // Pre-populate all possible sync lock variants
        foreach (['all', 'legacy', 'flywheel', 'recovery'] as $model) {
            Cache::put("billing_sync_{$upload->id}_{$model}", true, 300);
        }
        Cache::put("billing_resync_{$upload->id}", true, 300);

        $this->service->cancelResync($upload);

        // All sync lock variants should be cleared
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

    // ──────────────────────────────────────────────
    // executeResync()
    // ──────────────────────────────────────────────

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
        // After resync dispatch, the ProcessBillingJob will call startBilling
        // But the service itself resets these in the DB transaction before dispatching
        // However, the billing_status gets set to idle in the transaction
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

        // Pending attempt without unique_id (not yet submitted to EMP) — should be voided
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

        // Debtor with pending attempt already submitted to EMP (unique_id set)
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

        // Another debtor that IS eligible (no live pending attempt)
        $this->createEligibleLegacyDebtor($upload);

        $this->service->executeResync($upload);

        // The live attempt should remain pending — not voided
        $liveAttempt->refresh();
        $this->assertEquals(BillingAttempt::STATUS_PENDING, $liveAttempt->status);

        // The debtor with a live attempt should NOT be reset to uploaded
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

        // Create a debtor that will NOT be eligible after reset (approved attempt makes it terminal)
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
        ]);

        BillingAttempt::factory()->approved()->create([
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

        // Force an exception by mocking DB::transaction to throw
        $this->mock(BillingResyncService::class, function ($mock) use ($upload) {
            // We can't easily mock internal behavior, so let's use a different approach
        });

        // Instead, test this property: if no debtors exist at all, the transaction works fine
        // but we can verify the exception path by sabotaging the DB temporarily.
        // Let's verify the simpler case: that the lock IS cleared after a failed resync.
        // We'll manually set the lock and verify the catch block behavior.

        // Actually, let's create a real scenario that demonstrates the behavior:
        // We can assert that if we call executeResync and it throws, the lock is cleaned up
        Cache::put("billing_resync_{$upload->id}", true, 300);

        // The cleanest way is to test with a partial mock
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

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

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
