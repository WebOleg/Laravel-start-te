<?php

/**
 * Feature tests for Admin Upload API endpoints.
 */

namespace Tests\Feature\Admin;

use App\Models\Upload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
