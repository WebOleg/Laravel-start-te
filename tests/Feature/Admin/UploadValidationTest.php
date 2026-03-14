<?php

/**
 * Feature tests for Upload validation endpoints.
 */

namespace Tests\Feature\Admin;

use App\Models\Debtor;
use App\Models\Upload;
use App\Models\User;
use App\Jobs\ProcessValidationJob;
use App\Models\BillingAttempt;
use App\Models\DebtorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UploadValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test')->plainTextToken;
    }

    public function test_debtors_endpoint_returns_upload_debtors(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->count(3)->create(['upload_id' => $upload->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/debtors');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'validation_status',
                        'validation_errors',
                    ]
                ],
            ]);
    }

    public function test_debtors_endpoint_filters_by_validation_status(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_INVALID,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/debtors?validation_status=invalid');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.validation_status', 'invalid');
    }

    public function test_debtors_endpoint_supports_search(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'Hans',
            'last_name' => 'Mueller',
            'email' => 'hans.mueller@example.com',
            'iban' => 'DE89370400440532013000',
        ]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'Peter',
            'last_name' => 'Schmidt',
            'email' => 'peter.schmidt@example.com',
            'iban' => 'DE89370400440532013001',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/debtors?search=Hans');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Hans');
    }

    public function test_validate_endpoint_dispatches_validation_job(): void
    {
        Queue::fake();

        $upload = Upload::factory()->create(['status' => Upload::STATUS_COMPLETED]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => 'DE89370400440532013000',
            'first_name' => 'Hans',
            'last_name' => 'Mueller',
            'amount' => 100,
            'validation_status' => Debtor::VALIDATION_PENDING,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads/' . $upload->id . '/validate');

        $response->assertStatus(202)
            ->assertJsonPath('message', 'Validation started')
            ->assertJsonPath('status', 'processing');

        Queue::assertPushed(ProcessValidationJob::class);
    }

    public function test_validate_endpoint_validates_all_debtors(): void
    {
        $upload = Upload::factory()->create(['status' => Upload::STATUS_COMPLETED]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => 'DE89370400440532013000',
            'first_name' => 'Hans',
            'last_name' => 'Mueller',
            'amount' => 100,
            'validation_status' => Debtor::VALIDATION_PENDING,
        ]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => '',
            'first_name' => 'Peter',
            'last_name' => 'Schmidt',
            'amount' => 100,
            'validation_status' => Debtor::VALIDATION_PENDING,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads/' . $upload->id . '/validate');

        $response->assertStatus(202);

        $this->assertDatabaseHas('debtors', [
            'upload_id' => $upload->id,
            'first_name' => 'Hans',
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);
        $this->assertDatabaseHas('debtors', [
            'upload_id' => $upload->id,
            'first_name' => 'Peter',
            'validation_status' => Debtor::VALIDATION_INVALID,
        ]);
    }

    public function test_validate_endpoint_rejects_processing_upload(): void
    {
        $upload = Upload::factory()->create(['status' => Upload::STATUS_PROCESSING]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads/' . $upload->id . '/validate');

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Upload is still processing. Please wait.');
    }

    public function test_validation_stats_returns_correct_counts(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'status' => Debtor::STATUS_UPLOADED,
        ]);
        Debtor::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_INVALID,
        ]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_PENDING,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/validation-stats');

        $response->assertStatus(200)
            ->assertJsonPath('data.total', 6)
            ->assertJsonPath('data.valid', 3)
            ->assertJsonPath('data.invalid', 2)
            ->assertJsonPath('data.pending', 1)
            ->assertJsonPath('data.ready_for_sync', 3);
    }

    public function test_validation_stats_requires_authentication(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->getJson('/api/admin/uploads/' . $upload->id . '/validation-stats');

        $response->assertStatus(401);
    }

    public function test_debtors_endpoint_filters_by_debtor_type_legacy(): void
    {
        $upload = Upload::factory()->create();

        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'Legacy',
            'debtor_profile_id' => null,
        ]);

        $flywheelProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_FLYWHEEL]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'Flywheel',
            'debtor_profile_id' => $flywheelProfile->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/debtors?debtor_type=legacy');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Legacy');
    }

    public function test_debtors_endpoint_filters_by_debtor_type_flywheel(): void
    {
        $upload = Upload::factory()->create();

        $flywheelProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_FLYWHEEL]);
        Debtor::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => $flywheelProfile->id,
        ]);

        $recoveryProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_RECOVERY]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => $recoveryProfile->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/debtors?debtor_type=flywheel');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_debtors_endpoint_filters_by_debtor_type_recovery(): void
    {
        $upload = Upload::factory()->create();

        $recoveryProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_RECOVERY]);
        Debtor::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => $recoveryProfile->id,
        ]);

        $flywheelProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_FLYWHEEL]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => $flywheelProfile->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/debtors?debtor_type=recovery');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_debtors_endpoint_exclude_chargebacked(): void
    {
        $upload = Upload::factory()->create();

        $debtor1 = Debtor::factory()->create(['upload_id' => $upload->id, 'first_name' => 'Chargebacked']);
        $debtor2 = Debtor::factory()->create(['upload_id' => $upload->id, 'first_name' => 'Clean']);

        $debtor1->billingAttempts()->create([
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'amount' => 100,
            'currency' => 'EUR',
            'transaction_id' => 'TXN1',
            'reference' => 'REF1',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/debtors?exclude_chargebacked=true');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Clean');
    }

    public function test_debtors_endpoint_search_by_first_name(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'Alexander',
            'last_name' => 'Schmidt',
        ]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'Benjamin',
            'last_name' => 'Mueller',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/debtors?search=Alexander');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Alexander');
    }

    public function test_debtors_endpoint_search_by_iban(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => 'DE89370400440532013000',
            'first_name' => 'John',
        ]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => 'FR7630006000011234567890189',
            'first_name' => 'Jane',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/debtors?search=DE89');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'John');
    }

    public function test_debtors_endpoint_multiple_filters(): void
    {
        $upload = Upload::factory()->create();

        $flywheelProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_FLYWHEEL]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'Valid Flywheel',
            'debtor_profile_id' => $flywheelProfile->id,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'Invalid Flywheel',
            'debtor_profile_id' => $flywheelProfile->id,
            'validation_status' => Debtor::VALIDATION_INVALID,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/debtors?debtor_type=flywheel&validation_status=valid');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Valid Flywheel');
    }

    public function test_debtors_endpoint_pagination(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->count(60)->create(['upload_id' => $upload->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/debtors?per_page=30');

        $response->assertStatus(200)
            ->assertJsonCount(30, 'data')
            ->assertJsonPath('meta.total', 60)
            ->assertJsonPath('meta.current_page', 1);
    }

    public function test_validate_endpoint_already_processing_returns_200(): void
    {
        $upload = Upload::factory()->create(['status' => Upload::STATUS_COMPLETED]);
        $upload->validation_status = Upload::JOB_PROCESSING;
        $upload->validation_batch_id = 'batch-123';
        $upload->save();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads/' . $upload->id . '/validate');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'processing');
    }

    public function test_validate_persists_skip_bic_blacklist_flag(): void
    {
        Queue::fake();

        $upload = Upload::factory()->create([
            'status' => Upload::STATUS_COMPLETED,
            'skip_bic_blacklist' => false,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads/' . $upload->id . '/validate', [
                'skip_bic_blacklist' => true,
            ]);

        $response->assertStatus(202);

        $upload->refresh();
        $this->assertTrue($upload->skip_bic_blacklist);
    }

    public function test_validate_persists_skip_chargeback_check_flag(): void
    {
        Queue::fake();

        $upload = Upload::factory()->create([
            'status' => Upload::STATUS_COMPLETED,
            'skip_chargeback_check' => false,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads/' . $upload->id . '/validate', [
                'skip_chargeback_check' => true,
            ]);

        $response->assertStatus(202);

        $upload->refresh();
        $this->assertTrue($upload->skip_chargeback_check);
    }

    public function test_validate_resets_non_billed_debtors_to_pending(): void
    {
        Queue::fake();

        $upload = Upload::factory()->create(['status' => Upload::STATUS_COMPLETED]);

        // Previously validated debtor with no billing attempts — should be reset
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'validated_at' => now()->subDay(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads/' . $upload->id . '/validate');

        $response->assertStatus(202);

        $debtor->refresh();
        $this->assertEquals(Debtor::VALIDATION_PENDING, $debtor->validation_status);
        $this->assertNull($debtor->validated_at);
    }

    public function test_validate_does_not_reset_debtor_with_approved_billing(): void
    {
        Queue::fake();

        $upload = Upload::factory()->create(['status' => Upload::STATUS_COMPLETED]);

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'validated_at' => now()->subDay(),
        ]);

        BillingAttempt::factory()->approved()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads/' . $upload->id . '/validate');

        $response->assertStatus(202);

        $debtor->refresh();
        // Should NOT be reset — has an approved billing attempt
        $this->assertEquals(Debtor::VALIDATION_VALID, $debtor->validation_status);
        $this->assertNotNull($debtor->validated_at);
    }

    public function test_validate_does_not_reset_debtor_with_pending_billing(): void
    {
        Queue::fake();

        $upload = Upload::factory()->create(['status' => Upload::STATUS_COMPLETED]);

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'validated_at' => now()->subDay(),
        ]);

        BillingAttempt::factory()->pending()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads/' . $upload->id . '/validate');

        $response->assertStatus(202);

        $debtor->refresh();
        // Should NOT be reset — has a pending billing attempt
        $this->assertEquals(Debtor::VALIDATION_VALID, $debtor->validation_status);
    }

    public function test_validate_requires_authentication(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->postJson('/api/admin/uploads/' . $upload->id . '/validate');

        $response->assertStatus(401);
    }

    public function test_validate_returns_404_for_nonexistent_upload(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads/99999/validate');

        $response->assertStatus(404);
    }

    public function test_debtors_endpoint_search_by_email(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'Hans',
            'email' => 'unique.email.search@example.com',
        ]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'Peter',
            'email' => 'other@example.com',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/debtors?search=unique.email.search');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Hans');
    }

    public function test_debtors_endpoint_search_by_last_name(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'Hans',
            'last_name' => 'Zimmermann',
        ]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'Peter',
            'last_name' => 'Mueller',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/debtors?search=Zimmermann');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Hans');
    }

    public function test_debtors_endpoint_debtor_type_all_returns_all(): void
    {
        $upload = Upload::factory()->create();

        $flywheelProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_FLYWHEEL]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => $flywheelProfile->id,
        ]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => null,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/debtors?debtor_type=all');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_validation_stats_filters_by_debtor_type(): void
    {
        $upload = Upload::factory()->create();

        $fwProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_FLYWHEEL]);
        Debtor::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => $fwProfile->id,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        // Legacy debtors should be excluded when filtering by flywheel
        Debtor::factory()->count(5)->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => null,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/validation-stats?debtor_type=flywheel');

        $response->assertStatus(200)
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.valid', 3);
    }

    public function test_validation_stats_includes_skip_flags(): void
    {
        $upload = Upload::factory()->create([
            'skip_bic_blacklist' => true,
            'skip_chargeback_check' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/validation-stats');

        $response->assertStatus(200)
            ->assertJsonPath('data.skip_bic_blacklist', true)
            ->assertJsonPath('data.skip_chargeback_check', true);
    }

    public function test_validation_stats_includes_price_breakdown(): void
    {
        $upload = Upload::factory()->create();

        Debtor::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'amount' => 100,
        ]);
        Debtor::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'amount' => 200,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/validation-stats');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['price_breakdown']])
            ->assertJsonPath('data.valid_total_amount', 700);
    }

    public function test_validation_stats_empty_upload(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/validation-stats');

        $response->assertStatus(200)
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.valid', 0)
            ->assertJsonPath('data.invalid', 0)
            ->assertJsonPath('data.pending', 0);
    }

    public function test_debtors_endpoint_requires_authentication(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->getJson('/api/admin/uploads/' . $upload->id . '/debtors');

        $response->assertStatus(401);
    }
}
