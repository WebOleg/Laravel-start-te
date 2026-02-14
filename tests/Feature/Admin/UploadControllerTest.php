<?php

/**
 * Feature tests for Admin Upload API endpoints.
 */

namespace Tests\Feature\Admin;

use App\Models\Upload;
use App\Models\User;
use App\Models\EmpAccount;
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
}
