<?php

namespace Tests\Feature\Admin;

use App\Models\Debtor;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UploadStatusTest extends TestCase
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

    public function test_status_endpoint_returns_upload_status(): void
    {
        $upload = Upload::factory()->create([
            'status' => Upload::STATUS_COMPLETED,
            'total_records' => 10,
            'processed_records' => 8,
            'failed_records' => 2,
        ]);
        Debtor::factory()->count(8)->create(['upload_id' => $upload->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/status');

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $upload->id)
            ->assertJsonPath('data.status', Upload::STATUS_COMPLETED)
            ->assertJsonPath('data.total_records', 10)
            ->assertJsonPath('data.processed_records', 8)
            ->assertJsonPath('data.failed_records', 2)
            ->assertJsonPath('data.debtors_count', 8)
            ->assertJsonPath('data.is_complete', true);
    }

    public function test_status_endpoint_calculates_progress_correctly(): void
    {
        $upload = Upload::factory()->create([
            'status' => Upload::STATUS_PROCESSING,
            'total_records' => 100,
            'processed_records' => 50,
            'failed_records' => 0,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/status');

        $response->assertStatus(200);
        
        // Use loose equality for numeric comparison (50.0 == 50 in JSON)
        $progress = $response->json('data.progress');
        $this->assertEquals(50, $progress);
    }

    public function test_status_endpoint_progress_includes_failed_records(): void
    {
        $upload = Upload::factory()->create([
            'status' => Upload::STATUS_PROCESSING,
            'total_records' => 100,
            'processed_records' => 40,
            'failed_records' => 10,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/status');

        $response->assertStatus(200);
        
        $progress = $response->json('data.progress');
        $this->assertEquals(50, $progress);
    }

    public function test_status_endpoint_progress_zero_when_no_records(): void
    {
        $upload = Upload::factory()->create([
            'total_records' => 0,
            'processed_records' => 0,
            'failed_records' => 0,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/status');

        $response->assertStatus(200);
        
        $progress = $response->json('data.progress');
        $this->assertEquals(0, $progress);
    }

    public function test_status_endpoint_marks_completed_as_complete(): void
    {
        $upload = Upload::factory()->create(['status' => Upload::STATUS_COMPLETED]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/status');

        $response->assertStatus(200)
            ->assertJsonPath('data.is_complete', true);
    }

    public function test_status_endpoint_marks_failed_as_complete(): void
    {
        $upload = Upload::factory()->create(['status' => Upload::STATUS_FAILED]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/status');

        $response->assertStatus(200)
            ->assertJsonPath('data.is_complete', true);
    }

    public function test_status_endpoint_marks_pending_as_incomplete(): void
    {
        $upload = Upload::factory()->create(['status' => Upload::STATUS_PENDING]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/status');

        $response->assertStatus(200)
            ->assertJsonPath('data.is_complete', false);
    }

    public function test_status_endpoint_marks_processing_as_incomplete(): void
    {
        $upload = Upload::factory()->create(['status' => Upload::STATUS_PROCESSING]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/status');

        $response->assertStatus(200)
            ->assertJsonPath('data.is_complete', false);
    }

    public function test_status_endpoint_requires_authentication(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->getJson('/api/admin/uploads/' . $upload->id . '/status');

        $response->assertStatus(401);
    }

    public function test_status_endpoint_returns_404_for_nonexistent_upload(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/99999/status');

        $response->assertStatus(404);
    }

    public function test_status_endpoint_progress_rounds_to_two_decimals(): void
    {
        $upload = Upload::factory()->create([
            'total_records' => 3,
            'processed_records' => 1,
            'failed_records' => 0,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/status');

        $response->assertStatus(200);
        $progress = $response->json('data.progress');
        // 1/3 * 100 = 33.33...
        $this->assertEquals(33.33, $progress);
    }

    public function test_status_endpoint_debtors_count_is_loaded(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->count(5)->create(['upload_id' => $upload->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/status');

        $response->assertStatus(200)
            ->assertJsonPath('data.debtors_count', 5);
    }

    public function test_status_endpoint_progress_100_when_all_processed(): void
    {
        $upload = Upload::factory()->create([
            'total_records' => 50,
            'processed_records' => 45,
            'failed_records' => 5,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/status');

        $response->assertStatus(200);
        
        $progress = $response->json('data.progress');
        $this->assertEquals(100, $progress);
    }
}
