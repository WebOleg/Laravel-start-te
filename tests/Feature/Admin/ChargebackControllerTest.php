<?php

namespace Tests\Feature\Admin;

use App\Models\BillingAttempt;
use App\Models\User;
use App\Models\Debtor;
use App\Models\VopLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Illuminate\Support\Facades\Cache;

class ChargebackControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->user = User::factory()->create();
    }

    public function test_chargebacks_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/chargebacks');
        $response->assertStatus(401);
    }

    public function test_chargebacks_codes_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/chargebacks/codes');
        $response->assertStatus(401);
    }

    public function test_can_list_chargebacks(): void
    {
        Sanctum::actingAs($this->user);

        $debtor = Debtor::factory()->create();
        
        // Create VOP log for bank info
        VopLog::factory()->create([
            'debtor_id' => $debtor->id,
            'bank_name' => 'Test Bank',
            'country' => 'DE',
        ]);

        // Create chargebacks
        BillingAttempt::factory()->count(3)->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'error_code' => 'AC01',
            'error_message' => 'Account closed',
        ]);

        // Create non-chargebacks (should not appear)
        BillingAttempt::factory()->count(2)->create([
            'status' => BillingAttempt::STATUS_APPROVED,
            'debtor_id' => $debtor->id,
        ]);

        $response = $this->getJson('/api/admin/chargebacks');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'error_code',
                        'error_message',
                        'amount',
                        'currency',
                        'bank_name',
                        'bank_country',
                        'processed_at',
                        'debtor' => [
                            'id',
                            'first_name',
                            'last_name',
                            'email',
                            'iban',
                        ],
                    ],
                ],
                'links',
                'meta',
            ]);
    }

    public function test_chargebacks_are_ordered_by_latest_first(): void
    {
        Sanctum::actingAs($this->user);

        $debtor = Debtor::factory()->create();

        $oldest = BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'created_at' => now()->subDays(2),
        ]);

        $newest = BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'created_at' => now(),
        ]);

        $response = $this->getJson('/api/admin/chargebacks');

        $response->assertStatus(200);
        $data = $response->json('data');
        
        $this->assertEquals($newest->id, $data[0]['id']);
        $this->assertEquals($oldest->id, $data[1]['id']);
    }

    public function test_can_filter_chargebacks_by_error_code(): void
    {
        Sanctum::actingAs($this->user);
        $debtor = Debtor::factory()->create();

        BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'error_code' => 'AC01',
        ]);

        BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'error_code' => 'MD01',
        ]);

        BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'error_code' => 'AC01',
        ]);

        $response = $this->getJson('/api/admin/chargebacks?code=AC01');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');

        foreach ($response->json('data') as $chargeback) {
            $this->assertEquals('AC01', $chargeback['error_code']);
        }
    }

    public function test_chargebacks_pagination_respects_per_page(): void
    {
        Sanctum::actingAs($this->user);
        $debtor = Debtor::factory()->create();

        BillingAttempt::factory()->count(15)->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
        ]);

        $response = $this->getJson('/api/admin/chargebacks?per_page=5');

        $response->assertStatus(200)
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 15);
    }

    public function test_chargebacks_default_per_page_is_50(): void
    {
        Sanctum::actingAs($this->user);
        $debtor = Debtor::factory()->create();

        BillingAttempt::factory()->count(60)->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
        ]);

        $response = $this->getJson('/api/admin/chargebacks');

        $response->assertStatus(200)
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.per_page', 50);
    }

    public function test_chargebacks_returns_empty_when_none_exist(): void
    {
        Sanctum::actingAs($this->user);

        // Create only approved billing attempts
        BillingAttempt::factory()->count(3)->create([
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        $response = $this->getJson('/api/admin/chargebacks');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_chargebacks_include_debtor_with_masked_iban(): void
    {
        Sanctum::actingAs($this->user);

        $debtor = Debtor::factory()->create([
            'iban' => 'DE89370400440532013000',
        ]);

        BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
        ]);

        $response = $this->getJson('/api/admin/chargebacks');

        $response->assertStatus(200);
        
        $returnedIban = $response->json('data.0.debtor.iban');
        $this->assertNotEquals('DE89370400440532013000', $returnedIban);
        $this->assertStringContainsString('*', $returnedIban);
    }

    public function test_chargebacks_include_bank_info_from_vop_log(): void
    {
        Sanctum::actingAs($this->user);

        $debtor = Debtor::factory()->create();
        
        VopLog::factory()->create([
            'debtor_id' => $debtor->id,
            'bank_name' => 'Deutsche Bank',
            'country' => 'DE',
        ]);

        BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
        ]);

        $response = $this->getJson('/api/admin/chargebacks');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.bank_name', 'Deutsche Bank')
            ->assertJsonPath('data.0.bank_country', 'DE');
    }

    // ==================== CODES ROUTE TESTS ====================

    public function test_can_list_unique_chargeback_codes(): void
    {
        Sanctum::actingAs($this->user);
        $debtor = Debtor::factory()->create();

        BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'error_code' => 'AC01',
        ]);

        BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'error_code' => 'MD01',
        ]);

        // Duplicate code
        BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'error_code' => 'AC01',
        ]);

        $response = $this->getJson('/api/admin/chargebacks/codes');

        $response->assertStatus(200)
            ->assertJsonStructure(['data'])
            ->assertJsonCount(2, 'data');

        $codes = $response->json('data');
        $this->assertContains('AC01', $codes);
        $this->assertContains('MD01', $codes);
    }

    public function test_chargeback_codes_are_ordered_alphabetically(): void
    {
        Sanctum::actingAs($this->user);
        $debtor = Debtor::factory()->create();

        BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'error_code' => 'MD01',
        ]);

        BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'error_code' => 'AC01',
        ]);

        BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'error_code' => 'AG01',
        ]);

        $response = $this->getJson('/api/admin/chargebacks/codes');

        $response->assertStatus(200);
        $codes = $response->json('data');
        
        $this->assertEquals(['AC01', 'AG01', 'MD01'], $codes);
    }

    public function test_chargeback_codes_excludes_non_chargebacked_statuses(): void
    {
        Sanctum::actingAs($this->user);
        $debtor = Debtor::factory()->create();

        BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'error_code' => 'AC01',
        ]);

        // Declined with error code should not appear
        BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_DECLINED,
            'debtor_id' => $debtor->id,
            'error_code' => 'AM04',
        ]);

        // Error with code should not appear
        BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_ERROR,
            'debtor_id' => $debtor->id,
            'error_code' => 'SY01',
        ]);

        $response = $this->getJson('/api/admin/chargebacks/codes');

        $response->assertStatus(200)
            ->assertJson(['data' => ['AC01']]);
    }

    public function test_chargeback_codes_excludes_null_error_codes(): void
    {
        Sanctum::actingAs($this->user);
        $debtor = Debtor::factory()->create();

        BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'error_code' => 'AC01',
        ]);

        BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'error_code' => null,
        ]);

        $response = $this->getJson('/api/admin/chargebacks/codes');

        $response->assertStatus(200)
            ->assertJson(['data' => ['AC01']]);
    }

    public function test_chargeback_codes_returns_empty_array_when_none_exist(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/admin/chargebacks/codes');

        $response->assertStatus(200)
            ->assertJson(['data' => []]);
    }

    public function test_chargeback_codes_are_cached(): void
    {
        Sanctum::actingAs($this->user);
        $debtor = Debtor::factory()->create();

        BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'error_code' => 'AC01',
        ]);

        // First request
        $response1 = $this->getJson('/api/admin/chargebacks/codes');
        $response1->assertStatus(200)->assertJson(['data' => ['AC01']]);

        // Add new chargeback
        BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'error_code' => 'MD01',
        ]);

        // Second request should return cached result
        $response2 = $this->getJson('/api/admin/chargebacks/codes');
        $response2->assertStatus(200)->assertJson(['data' => ['AC01']]);
    }
}
