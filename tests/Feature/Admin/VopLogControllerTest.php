<?php

/**
 * Feature tests for Admin VopLog API endpoints.
 */

namespace Tests\Feature\Admin;

use App\Models\Debtor;
use App\Models\Upload;
use App\Models\User;
use App\Models\VopLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VopLogControllerTest extends TestCase
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

    public function test_index_returns_vop_logs_list(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        VopLog::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/vop-logs');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'debtor_id',
                        'upload_id',
                        'iban_masked',
                        'iban_valid',
                        'vop_score',
                        'result',
                    ]
                ],
                'meta' => ['current_page', 'total'],
            ]);
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/vop-logs');

        $response->assertStatus(401);
    }

    public function test_index_filters_by_result(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        
        VopLog::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'result' => VopLog::RESULT_VERIFIED,
        ]);
        VopLog::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'result' => VopLog::RESULT_REJECTED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/vop-logs?result=verified');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('verified', $data[0]['result']);
    }

    public function test_index_filters_by_upload_id(): void
    {
        $upload1 = Upload::factory()->create();
        $upload2 = Upload::factory()->create();
        $debtor1 = Debtor::factory()->create(['upload_id' => $upload1->id]);
        $debtor2 = Debtor::factory()->create(['upload_id' => $upload2->id]);
        
        VopLog::factory()->count(2)->create([
            'upload_id' => $upload1->id,
            'debtor_id' => $debtor1->id,
        ]);
        VopLog::factory()->create([
            'upload_id' => $upload2->id,
            'debtor_id' => $debtor2->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/vop-logs?upload_id=' . $upload1->id);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(2, $data);
    }

    public function test_index_filters_by_debtor_id(): void
    {
        $upload = Upload::factory()->create();
        $debtor1 = Debtor::factory()->create(['upload_id' => $upload->id]);
        $debtor2 = Debtor::factory()->create(['upload_id' => $upload->id]);
        
        VopLog::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor1->id,
        ]);
        VopLog::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor2->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/vop-logs?debtor_id=' . $debtor1->id);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);
    }

    public function test_index_filters_by_bav_verified(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        VopLog::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bav_verified' => true,
            'result' => VopLog::RESULT_VERIFIED,
        ]);
        VopLog::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bav_verified' => false,
            'result' => VopLog::RESULT_REJECTED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/vop-logs?bav_verified=true');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertTrue($data[0]['bav_verified']);
    }

    public function test_show_returns_single_vop_log(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        $vopLog = VopLog::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'vop_score' => 85,
            'result' => VopLog::RESULT_VERIFIED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/vop-logs/' . $vopLog->id);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $vopLog->id)
            ->assertJsonPath('data.vop_score', 85)
            ->assertJsonPath('data.result', 'verified');
    }

    public function test_show_returns_iban_masked(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        $vopLog = VopLog::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'iban_masked' => 'DE89****013000',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/vop-logs/' . $vopLog->id);

        $response->assertStatus(200)
            ->assertJsonPath('data.iban_masked', 'DE89****013000');
    }

    public function test_show_returns_404_for_nonexistent_vop_log(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/vop-logs/99999');

        $response->assertStatus(404);
    }

    public function test_index_pagination(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        VopLog::factory()->count(35)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/vop-logs?per_page=20');

        $response->assertStatus(200)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.total', 35);

        $data = $response->json('data');
        $this->assertCount(20, $data);
    }

    public function test_index_pagination_second_page(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        VopLog::factory()->count(35)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/vop-logs?per_page=20&page=2');

        $response->assertStatus(200)
            ->assertJsonPath('meta.current_page', 2);

        $data = $response->json('data');
        $this->assertCount(15, $data);
    }

    public function test_index_filters_by_multiple_results(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        VopLog::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'result' => VopLog::RESULT_VERIFIED,
        ]);
        VopLog::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'result' => VopLog::RESULT_LIKELY_VERIFIED,
        ]);
        VopLog::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'result' => VopLog::RESULT_REJECTED,
        ]);

        // Filter by verified - should return 1
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/vop-logs?result=verified');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_index_filters_by_combined_result_and_upload(): void
    {
        $upload1 = Upload::factory()->create();
        $upload2 = Upload::factory()->create();
        $debtor1 = Debtor::factory()->create(['upload_id' => $upload1->id]);
        $debtor2 = Debtor::factory()->create(['upload_id' => $upload2->id]);

        VopLog::factory()->count(2)->create([
            'upload_id' => $upload1->id,
            'debtor_id' => $debtor1->id,
            'result' => VopLog::RESULT_VERIFIED,
        ]);
        VopLog::factory()->create([
            'upload_id' => $upload1->id,
            'debtor_id' => $debtor1->id,
            'result' => VopLog::RESULT_REJECTED,
        ]);
        VopLog::factory()->create([
            'upload_id' => $upload2->id,
            'debtor_id' => $debtor2->id,
            'result' => VopLog::RESULT_VERIFIED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/vop-logs?upload_id=' . $upload1->id . '&result=verified');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertTrue(collect($data)->every(fn($item) => $item['result'] === 'verified'));
    }

    public function test_show_loads_relationships(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        $vopLog = VopLog::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/vop-logs/' . $vopLog->id);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'debtor_id',
                    'upload_id',
                    'debtor',
                    'iban_masked',
                    'result',
                    'vop_score',
                    'is_positive',
                    'is_negative',
                ]
            ]);
    }
}
