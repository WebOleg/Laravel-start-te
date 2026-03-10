<?php

/**
 * Feature tests for Admin Upload API endpoints.
 */

namespace Tests\Feature\Admin;

use App\Models\Upload;
use App\Models\User;
use App\Models\EmpAccount;
use App\Enums\BillingModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

        // Create a valid CSV content
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

        $response->assertStatus(201); // Assuming sync processing for small files

        $this->assertDatabaseHas('uploads', [
            'original_filename' => 'test_lock.csv',
            'emp_account_id' => $account->id,
        ]);

        // Verify the meta JSON column contains the flag
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
                // 'apply_global_lock' is omitted
            ]);

        $response->assertStatus(201);

        $upload = Upload::where('original_filename', 'test_default.csv')->first();
        $this->assertFalse($upload->meta['apply_global_lock']);
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
        // Setting cooldown to false should work on any billing model
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
}
