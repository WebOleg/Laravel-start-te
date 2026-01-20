<?php

/**
 * Feature tests for Admin BillingAttempt API endpoints.
 */

namespace Tests\Feature\Admin;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\DebtorProfile;
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

    public function test_index_filters_by_flywheel_model(): void
    {
        // 1. Flywheel Attempt (Target)
        $flywheel = BillingAttempt::factory()->create([
            'billing_model' => DebtorProfile::MODEL_FLYWHEEL,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        // 2. Recovery Attempt (Should skip)
        $recovery = BillingAttempt::factory()->create([
            'billing_model' => DebtorProfile::MODEL_RECOVERY,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts?model=' . DebtorProfile::MODEL_FLYWHEEL);

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(1, $data);

        // Assert by ID since Resource might not expose billing_model
        $this->assertEquals($flywheel->id, $data[0]['id']);
    }

    public function test_index_filters_by_legacy_model_includes_null_profiles(): void
    {
        // 1. Explicit Legacy
        $legacy = BillingAttempt::factory()->create([
            'billing_model' => DebtorProfile::MODEL_LEGACY,
        ]);

        // 2. Null Profile + Null Model (Implied Legacy)
        $impliedLegacy = BillingAttempt::factory()->create([
            'billing_model' => null,
            'debtor_profile_id' => null,
        ]);

        // 3. Flywheel (Should skip)
        // FIX: Must have a profile ID, otherwise 'orWhereNull(debtor_profile_id)' will catch it
        $flywheelProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_FLYWHEEL]);
        $flywheel = BillingAttempt::factory()->create([
            'billing_model' => DebtorProfile::MODEL_FLYWHEEL,
            'debtor_profile_id' => $flywheelProfile->id, // Critical: Ensure ID is present
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts?model=' . DebtorProfile::MODEL_LEGACY);

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(2, $data);

        $ids = collect($data)->pluck('id');
        $this->assertContains($legacy->id, $ids);
        $this->assertContains($impliedLegacy->id, $ids);
        $this->assertNotContains($flywheel->id, $ids);
    }

    // --- NEW: Search Functionality ---

    public function test_index_search_finds_by_transaction_id(): void
    {
        BillingAttempt::factory()->create(['transaction_id' => 'TX_FIND_ME']);
        BillingAttempt::factory()->create(['transaction_id' => 'TX_OTHER']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts?search=FIND_ME');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals('TX_FIND_ME', $data[0]['transaction_id']);
    }

    public function test_index_search_finds_by_debtor_name(): void
    {
        $debtor = Debtor::factory()->create(['first_name' => 'Waldo', 'last_name' => 'Smith']);
        BillingAttempt::factory()->create(['debtor_id' => $debtor->id]);

        BillingAttempt::factory()->create(); // Random other

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts?search=Waldo');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }
}
