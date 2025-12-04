<?php

/**
 * Feature tests for Admin Debtor API endpoints.
 */

namespace Tests\Feature\Admin;

use App\Models\Debtor;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebtorControllerTest extends TestCase
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

    public function test_index_returns_debtors_list(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->count(3)->create(['upload_id' => $upload->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/debtors');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'upload_id',
                        'iban_masked',
                        'first_name',
                        'last_name',
                        'full_name',
                        'amount',
                        'status',
                    ]
                ],
                'meta' => ['current_page', 'total'],
            ]);
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/debtors');

        $response->assertStatus(401);
    }

    public function test_index_filters_by_status(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->create(['upload_id' => $upload->id, 'status' => Debtor::STATUS_PENDING]);
        Debtor::factory()->create(['upload_id' => $upload->id, 'status' => Debtor::STATUS_RECOVERED]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/debtors?status=pending');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('pending', $data[0]['status']);
    }

    public function test_index_filters_by_country(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->create(['upload_id' => $upload->id, 'country' => 'DE']);
        Debtor::factory()->create(['upload_id' => $upload->id, 'country' => 'AT']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/debtors?country=DE');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('DE', $data[0]['country']);
    }

    public function test_index_filters_by_upload_id(): void
    {
        $upload1 = Upload::factory()->create();
        $upload2 = Upload::factory()->create();
        Debtor::factory()->count(2)->create(['upload_id' => $upload1->id]);
        Debtor::factory()->count(3)->create(['upload_id' => $upload2->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/debtors?upload_id=' . $upload1->id);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(2, $data);
    }

    public function test_show_returns_single_debtor(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'Hans',
            'last_name' => 'Mueller',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/debtors/' . $debtor->id);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $debtor->id)
            ->assertJsonPath('data.first_name', 'Hans')
            ->assertJsonPath('data.full_name', 'Hans Mueller');
    }

    public function test_show_returns_masked_iban(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => 'DE89370400440532013000',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/debtors/' . $debtor->id);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertStringStartsWith('DE89', $data['iban_masked']);
        $this->assertStringEndsWith('3000', $data['iban_masked']);
        $this->assertStringContainsString('****', $data['iban_masked']);
    }

    public function test_show_returns_404_for_nonexistent_debtor(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/debtors/99999');

        $response->assertStatus(404);
    }
}
