<?php

/**
 * Feature tests for Admin Upload API endpoints.
 */

namespace Tests\Feature\Admin;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\DebtorProfile;
use App\Models\Upload;
use App\Models\User;
use App\Models\EmpAccount;
use App\Models\TetherInstance;
use App\Enums\BillingModel;
use App\Jobs\ProcessValidationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadControllerTest extends TestCase
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

    public function test_index_returns_uploads_list(): void
    {
        Upload::factory()->count(3)->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'filename',
                        'original_filename',
                        'status',
                        'total_records',
                        'processed_records',
                    ]
                ],
                'meta' => ['current_page', 'total'],
            ]);
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/uploads');

        $response->assertStatus(401);
    }

    public function test_index_filters_by_status(): void
    {
        Upload::factory()->create(['status' => Upload::STATUS_COMPLETED]);
        Upload::factory()->create(['status' => Upload::STATUS_PENDING]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads?status=completed');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('completed', $data[0]['status']);
    }

    public function test_index_paginates_results(): void
    {
        Upload::factory()->count(25)->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads?per_page=10');

        $response->assertStatus(200)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonCount(10, 'data');
    }

    public function test_index_rejects_invalid_status_filter(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads?status=nonexistent');

        $response->assertStatus(422);
    }

    public function test_index_filters_by_emp_account_id(): void
    {
        $account = EmpAccount::factory()->create();
        Upload::factory()->create(['emp_account_id' => $account->id]);
        Upload::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads?emp_account_id={$account->id}");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_show_returns_single_upload(): void
    {
        $upload = Upload::factory()->create([
            'original_filename' => 'test_file.csv',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $upload->id)
            ->assertJsonPath('data.original_filename', 'test_file.csv');
    }

    public function test_show_returns_404_for_nonexistent_upload(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/99999');

        $response->assertStatus(404);
    }

    public function test_store_persists_global_lock_flag_when_enabled(): void
    {
        Storage::fake('s3');

        $content = "name,iban,amount\nJohn Doe,DE123456789,100";
        $file = UploadedFile::fake()->createWithContent('test_lock.csv', $content);

        $account = EmpAccount::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', [
                'file' => $file,
                'emp_account_id' => $account->id,
                'apply_global_lock' => true,
                'billing_model' => 'legacy'
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('uploads', [
            'original_filename' => 'test_lock.csv',
            'emp_account_id' => $account->id,
        ]);

        $upload = Upload::where('original_filename', 'test_lock.csv')->first();
        $this->assertTrue($upload->meta['apply_global_lock']);
    }

    public function test_store_defaults_global_lock_to_false_when_missing(): void
    {
        Storage::fake('s3');

        $content = "name,iban,amount\nJane Doe,DE987654321,50";
        $file = UploadedFile::fake()->createWithContent('test_default.csv', $content);

        $account = EmpAccount::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', [
                'file' => $file,
                'emp_account_id' => $account->id,
            ]);

        $response->assertStatus(201);

        $upload = Upload::where('original_filename', 'test_default.csv')->first();
        $this->assertFalse($upload->meta['apply_global_lock']);
    }

    public function test_store_requires_file(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', []);

        $response->assertStatus(422);
    }

    public function test_store_requires_authentication(): void
    {
        $response = $this->postJson('/api/admin/uploads', []);

        $response->assertStatus(401);
    }

    public function test_status_returns_upload_progress(): void
    {
        $upload = Upload::factory()->create([
            'status' => Upload::STATUS_PROCESSING,
            'total_records' => 100,
            'processed_records' => 50,
            'failed_records' => 5,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $upload->id)
            ->assertJsonPath('data.status', Upload::STATUS_PROCESSING)
            ->assertJsonPath('data.total_records', 100)
            ->assertJsonPath('data.processed_records', 50)
            ->assertJsonPath('data.failed_records', 5)
            ->assertJsonPath('data.is_complete', false);
    }

    public function test_status_returns_complete_for_finished_upload(): void
    {
        $upload = Upload::factory()->create([
            'status' => Upload::STATUS_COMPLETED,
            'total_records' => 10,
            'processed_records' => 10,
            'failed_records' => 0,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_complete', true);
    }

    public function test_status_returns_complete_for_failed_upload(): void
    {
        $upload = Upload::factory()->create([
            'status' => Upload::STATUS_FAILED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_complete', true);
    }

    public function test_status_includes_progress_percentage(): void
    {
        $upload = Upload::factory()->create([
            'status' => Upload::STATUS_PROCESSING,
            'total_records' => 200,
            'processed_records' => 100,
            'failed_records' => 0,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.progress', 50);
    }

    public function test_status_handles_zero_total_records(): void
    {
        $upload = Upload::factory()->create([
            'total_records' => 0,
            'processed_records' => 0,
            'failed_records' => 0,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.progress', 0);
    }

    public function test_debtors_returns_paginated_list(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->count(5)->create(['upload_id' => $upload->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/debtors");

        $response->assertStatus(200)
            ->assertJsonCount(5, 'data');
    }

    public function test_debtors_filters_by_validation_status(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);
        Debtor::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_INVALID,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/debtors?validation_status=" . Debtor::VALIDATION_VALID);

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_debtors_filters_by_debtor_type(): void
    {
        $upload = Upload::factory()->create();

        $fwProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_FLYWHEEL]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => $fwProfile->id,
        ]);

        // Legacy debtor (no profile)
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => null,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/debtors?debtor_type=" . DebtorProfile::MODEL_FLYWHEEL);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_debtors_exclude_chargebacked(): void
    {
        $upload = Upload::factory()->create();

        $normalDebtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        $cbDebtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $cbDebtor->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/debtors?exclude_chargebacked=1");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_debtors_search_by_name(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'UniqueSearchName',
        ]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'OtherPerson',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/debtors?search=UniqueSearchName");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_validate_dispatches_validation_job(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'status' => Upload::STATUS_COMPLETED,
        ]);

        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_PENDING,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/validate");

        $response->assertStatus(202)
            ->assertJsonPath('status', 'processing');

        Bus::assertDispatched(ProcessValidationJob::class);
    }

    public function test_validate_rejects_while_upload_is_processing(): void
    {
        $upload = Upload::factory()->create([
            'status' => Upload::STATUS_PROCESSING,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/validate");

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Upload is still processing. Please wait.']);
    }

    public function test_validate_accepts_skip_flags(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'status' => Upload::STATUS_COMPLETED,
            'skip_bic_blacklist' => false,
            'skip_chargeback_check' => false,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/validate", [
                'skip_bic_blacklist' => true,
                'skip_chargeback_check' => true,
            ]);

        $response->assertStatus(202);

        $upload->refresh();
        $this->assertTrue($upload->skip_bic_blacklist);
        $this->assertTrue($upload->skip_chargeback_check);
    }

    public function test_validation_stats_returns_counts(): void
    {
        $upload = Upload::factory()->create();

        Debtor::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
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
            ->getJson("/api/admin/uploads/{$upload->id}/validation-stats");

        $response->assertStatus(200)
            ->assertJsonPath('data.total', 6)
            ->assertJsonPath('data.valid', 3)
            ->assertJsonPath('data.pending', 1);
    }

    public function test_validation_stats_includes_model_counts(): void
    {
        $upload = Upload::factory()->create();

        $fwProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_FLYWHEEL]);
        Debtor::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => $fwProfile->id,
        ]);

        // Legacy debtors (no profile)
        Debtor::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => null,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/validation-stats");

        $response->assertStatus(200)
            ->assertJsonPath('data.model_counts.all', 5)
            ->assertJsonPath('data.model_counts.flywheel', 2)
            ->assertJsonPath('data.model_counts.legacy', 3);
    }

    public function test_set_cooldown_on_legacy_upload_with_true(): void
    {
        $upload = Upload::factory()->create([
            'billing_model' => BillingModel::Legacy->value,
            'is_30d_cool' => false,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->patchJson('/api/admin/uploads/' . $upload->id . '/cooldown', [
                'is_30d_cool' => true,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_30d_cool', true);

        $upload->refresh();
        $this->assertTrue($upload->is_30d_cool);
    }

    public function test_set_cooldown_on_legacy_upload_with_false(): void
    {
        $upload = Upload::factory()->create([
            'billing_model' => BillingModel::Legacy->value,
            'is_30d_cool' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->patchJson('/api/admin/uploads/' . $upload->id . '/cooldown', [
                'is_30d_cool' => false,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_30d_cool', false);

        $upload->refresh();
        $this->assertFalse($upload->is_30d_cool);
    }

    public function test_set_cooldown_rejects_flywheel_upload(): void
    {
        $upload = Upload::factory()->create([
            'billing_model' => BillingModel::Flywheel->value,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->patchJson('/api/admin/uploads/' . $upload->id . '/cooldown', [
                'is_30d_cool' => true,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', fn($msg) => str_contains($msg, 'only applicable to Legacy'));
    }

    public function test_set_cooldown_rejects_recovery_upload(): void
    {
        $upload = Upload::factory()->create([
            'billing_model' => BillingModel::Recovery->value,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->patchJson('/api/admin/uploads/' . $upload->id . '/cooldown', [
                'is_30d_cool' => true,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', fn($msg) => str_contains($msg, 'only applicable to Legacy'));
    }

    public function test_set_cooldown_validates_required_field(): void
    {
        $upload = Upload::factory()->create([
            'billing_model' => BillingModel::Legacy->value,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->patchJson('/api/admin/uploads/' . $upload->id . '/cooldown', []);

        $response->assertStatus(422)
            ->assertJsonPath('errors.is_30d_cool', fn($errors) => in_array('The is 30d cool field is required.', $errors));
    }

    public function test_set_cooldown_validates_boolean_field(): void
    {
        $upload = Upload::factory()->create([
            'billing_model' => BillingModel::Legacy->value,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->patchJson('/api/admin/uploads/' . $upload->id . '/cooldown', [
                'is_30d_cool' => 'invalid',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.is_30d_cool.0', 'The is 30d cool field must be true or false.');
    }

    public function test_set_cooldown_returns_404_for_nonexistent_upload(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->patchJson('/api/admin/uploads/99999/cooldown', [
                'is_30d_cool' => true,
            ]);

        $response->assertStatus(404);
    }

    public function test_set_cooldown_requires_authentication(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->patchJson('/api/admin/uploads/' . $upload->id . '/cooldown', [
            'is_30d_cool' => true,
        ]);

        $response->assertStatus(401);
    }

    public function test_set_cooldown_allows_false_on_any_model(): void
    {
        foreach ([BillingModel::Legacy, BillingModel::Flywheel, BillingModel::Recovery] as $model) {
            $upload = Upload::factory()->create([
                'billing_model' => $model->value,
                'is_30d_cool' => true,
            ]);

            $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
                ->patchJson('/api/admin/uploads/' . $upload->id . '/cooldown', [
                    'is_30d_cool' => false,
                ]);

            $response->assertStatus(200)
                ->assertJsonPath('data.is_30d_cool', false);
        }
    }

    public function test_reassign_moves_upload_to_new_account(): void
    {
        $oldAccount = EmpAccount::factory()->create();
        $newAccount = EmpAccount::factory()->create(['is_active' => true]);

        $upload = Upload::factory()->create(['emp_account_id' => $oldAccount->id]);
        Debtor::factory()->count(3)->create(['upload_id' => $upload->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/reassign", [
                'emp_account_id' => $newAccount->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.debtors_updated', 3);

        $upload->refresh();
        $this->assertEquals($newAccount->id, $upload->emp_account_id);
    }

    public function test_reassign_rejects_inactive_account(): void
    {
        $inactiveAccount = EmpAccount::factory()->create(['is_active' => false]);
        $upload = Upload::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/reassign", [
                'emp_account_id' => $inactiveAccount->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Target EMP account is not active.']);
    }

    public function test_reassign_rejects_same_account(): void
    {
        $account = EmpAccount::factory()->create(['is_active' => true]);
        $upload = Upload::factory()->create(['emp_account_id' => $account->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/reassign", [
                'emp_account_id' => $account->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Upload is already assigned to this account.']);
    }

    public function test_reassign_requires_emp_account_id(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/reassign", []);

        $response->assertStatus(422);
    }

    public function test_update_settings_sets_max_billing_amount(): void
    {
        $upload = Upload::factory()->create(['max_billing_amount' => null]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->patchJson("/api/admin/uploads/{$upload->id}/settings", [
                'max_billing_amount' => 5000.00,
            ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'Upload settings updated.']);

        $upload->refresh();
        $this->assertEquals(5000.00, (float) $upload->max_billing_amount);
    }

    public function test_update_settings_clears_max_billing_amount(): void
    {
        $upload = Upload::factory()->create(['max_billing_amount' => 5000]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->patchJson("/api/admin/uploads/{$upload->id}/settings", [
                'max_billing_amount' => null,
            ]);

        $response->assertStatus(200);

        $upload->refresh();
        $this->assertNull($upload->max_billing_amount);
    }

    public function test_update_settings_rejects_negative_amount(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->patchJson("/api/admin/uploads/{$upload->id}/settings", [
                'max_billing_amount' => -100,
            ]);

        $response->assertStatus(422);
    }

    public function test_billing_cycles_returns_empty_for_new_upload(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/billing-cycles");

        $response->assertStatus(200)
            ->assertJsonPath('data.total_cycles', 0)
            ->assertJsonPath('data.cycles', []);
    }

    public function test_billing_cycles_groups_by_attempt_number(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'attempt_number' => 1,
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100,
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'attempt_number' => 2,
            'status' => BillingAttempt::STATUS_DECLINED,
            'amount' => 50,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/billing-cycles");

        $response->assertStatus(200)
            ->assertJsonPath('data.total_cycles', 2);
    }

    public function test_billing_cycles_includes_cap_info(): void
    {
        $upload = Upload::factory()->create(['max_billing_amount' => 500]);
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 200,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/billing-cycles");

        $response->assertStatus(200)
            ->assertJsonPath('data.max_billing_amount', 500);
    }

    public function test_destroy_requires_authentication(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->deleteJson("/api/admin/uploads/{$upload->id}");

        $response->assertStatus(401);
    }

    public function test_filter_chargebacks_removes_chargebacked_debtors(): void
    {
        $upload = Upload::factory()->create();
        $normalDebtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        $cbDebtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $cbDebtor->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/filter-chargebacks");

        $response->assertStatus(200)
            ->assertJsonPath('data.removed', 1);
    }

    public function test_filter_chargebacks_returns_zero_when_none(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->create(['upload_id' => $upload->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/filter-chargebacks");

        $response->assertStatus(200)
            ->assertJsonPath('data.removed', 0);
    }

    public function test_search_finds_uploads_by_filename(): void
    {
        Upload::factory()->create(['original_filename' => 'quarterly_report.csv']);
        Upload::factory()->create(['original_filename' => 'other_file.csv']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/search?query=quarterly');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_search_returns_empty_for_no_match(): void
    {
        Upload::factory()->create(['original_filename' => 'data.csv']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/search?query=nonexistent_xyz');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }
}
