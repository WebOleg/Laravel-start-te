<?php

/**
 * Feature tests for Admin BillingAttempt API endpoints.
 */

namespace Tests\Feature\Admin;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingAttemptControllerTest extends TestCase
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

    public function test_index_returns_billing_attempts_list(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        BillingAttempt::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'debtor_id',
                        'upload_id',
                        'transaction_id',
                        'amount',
                        'status',
                        'attempt_number',
                    ]
                ],
                'meta' => ['current_page', 'total'],
            ]);
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/billing-attempts');

        $response->assertStatus(401);
    }

    public function test_index_filters_by_status(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        
        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);
        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_DECLINED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts?status=approved');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('approved', $data[0]['status']);
    }

    public function test_index_filters_by_debtor_id(): void
    {
        $upload = Upload::factory()->create();
        $debtor1 = Debtor::factory()->create(['upload_id' => $upload->id]);
        $debtor2 = Debtor::factory()->create(['upload_id' => $upload->id]);
        
        BillingAttempt::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor1->id,
        ]);
        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor2->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts?debtor_id=' . $debtor1->id);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(2, $data);
    }

    public function test_show_returns_single_billing_attempt(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        $attempt = BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'amount' => 150.00,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/' . $attempt->id);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $attempt->id)
            ->assertJsonPath('data.status', 'approved');

        $data = $response->json('data');
        $this->assertEquals(150.00, $data['amount']);
    }

    public function test_show_returns_404_for_nonexistent_billing_attempt(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/99999');

        $response->assertStatus(404);
    }
}
