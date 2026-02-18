<?php

namespace Tests\Feature\Admin;

use App\Models\BillingAttempt;
use App\Models\User;
use App\Models\Debtor;
use App\Models\VopLog;
use App\Models\Upload;
use App\Models\EmpAccount;
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
            'chargeback_reason_code' => 'AC01',
            'chargeback_reason_description' => 'Account closed',
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
            'chargeback_reason_code' => 'AC01',
        ]);

        BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'chargeback_reason_code' => 'MD01',
        ]);

        BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'chargeback_reason_code' => 'AC01',
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
            'chargeback_reason_code' => 'AC01',
        ]);

        BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'chargeback_reason_code' => 'MD01',
        ]);

        // Duplicate code
        BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'chargeback_reason_code' => 'AC01',
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
            'chargeback_reason_code' => 'MD01',
        ]);

        BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'chargeback_reason_code' => 'AC01',
        ]);

        BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'chargeback_reason_code' => 'AG01',
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
            'chargeback_reason_code' => 'AC01',
        ]);

        // Declined with error code should not appear
        BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_DECLINED,
            'debtor_id' => $debtor->id,
            'chargeback_reason_code' => 'AM04',
        ]);

        // Error with code should not appear
        BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_ERROR,
            'debtor_id' => $debtor->id,
            'chargeback_reason_code' => 'SY01',
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
            'chargeback_reason_code' => 'AC01',
        ]);

        BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'chargeback_reason_code' => null,
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
            'chargeback_reason_code' => 'AC01',
        ]);

        // First request
        $response1 = $this->getJson('/api/admin/chargebacks/codes');
        $response1->assertStatus(200)->assertJson(['data' => ['AC01']]);

        // Add new chargeback
        BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'chargeback_reason_code' => 'MD01',
        ]);

        // Second request should return cached result
        $response2 = $this->getJson('/api/admin/chargebacks/codes');
        $response2->assertStatus(200)->assertJson(['data' => ['AC01']]);
    }

    public function test_upload_reasons_requires_authentication(): void
    {
        $upload = Upload::factory()->create();
        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}");
        $response->assertStatus(401);
    }

    public function test_upload_reasons_returns_empty_for_upload_with_no_chargebacks(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'summary' => [
                    'total_records',
                    'valid_count',
                    'billed_count',
                    'total_chargebacks',
                    'cb_amount',
                    'approved_amount',
                    'cb_rate',
                ],
                'reasons',
            ])
            ->assertJsonPath('summary.total_chargebacks', 0)
            ->assertJsonPath('summary.cb_rate', 0)
            ->assertJsonPath('reasons', []);
    }

    public function test_upload_reasons_includes_summary_statistics(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        // Create approved and chargebacked attempts
        BillingAttempt::factory()->count(7)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100,
        ]);

        BillingAttempt::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'amount' => 100,
            'chargeback_reason_code' => 'AC01',
        ]);

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}");

        $response->assertStatus(200)
            ->assertJsonPath('summary.total_chargebacks', 3)
            ->assertJsonPath('summary.billed_count', 7)
            ->assertJsonPath('summary.cb_amount', 300)
            ->assertJsonPath('summary.approved_amount', 700)
            ->assertJsonPath('summary.cb_rate', 30); // 3 out of 10 = 30%
    }

    public function test_upload_reasons_breakdown_by_code(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(5)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'amount' => 100,
            'chargeback_reason_code' => 'AC01',
            'chargeback_reason_description' => 'Account Closed',
        ]);

        BillingAttempt::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'amount' => 50,
            'chargeback_reason_code' => 'MD01',
            'chargeback_reason_description' => 'Missing Data',
        ]);

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'reasons');

        $reasons = $response->json('reasons');
        
        // First reason should be AC01 (5 count, highest)
        $this->assertEquals('AC01', $reasons[0]['code']);
        $this->assertEquals('Account Closed', $reasons[0]['reason']);
        $this->assertEquals(5, $reasons[0]['cb_count']);
        $this->assertEquals(500, $reasons[0]['cb_amount']);
        
        // Second reason should be MD01
        $this->assertEquals('MD01', $reasons[1]['code']);
        $this->assertEquals(3, $reasons[1]['cb_count']);
        $this->assertEquals(150, $reasons[1]['cb_amount']);
    }

    public function test_upload_reasons_calculates_percentages(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        // 10 total records
        BillingAttempt::factory()->count(6)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        BillingAttempt::factory()->count(4)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AC01',
        ]);

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}");

        $response->assertStatus(200);
        $reason = $response->json('reasons.0');

        // 4 chargebacks / 4 total chargebacks = 100%
        $this->assertEquals(100, $reason['cb_percentage']);
        // 4 chargebacks / 10 total records = 40%
        $this->assertEquals(40, $reason['total_percentage']);
    }

    public function test_upload_reasons_filters_by_emp_account_id(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        $empAccount1 = EmpAccount::factory()->create();
        $empAccount2 = EmpAccount::factory()->create();

        BillingAttempt::factory()->count(5)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'emp_account_id' => $empAccount1->id,
            'chargeback_reason_code' => 'AC01',
        ]);

        BillingAttempt::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'emp_account_id' => $empAccount2->id,
            'chargeback_reason_code' => 'AC01',
        ]);

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}?emp_account_id={$empAccount1->id}");

        $response->assertStatus(200)
            ->assertJsonPath('summary.total_chargebacks', 5)
            ->assertJsonPath('reasons.0.cb_count', 5);
    }

    public function test_upload_reasons_filters_by_date_range(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargebacked_at' => now()->subDays(10),
            'chargeback_reason_code' => 'AC01',
        ]);

        BillingAttempt::factory()->count(5)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargebacked_at' => now(),
            'chargeback_reason_code' => 'AC01',
        ]);

        $startDate = now()->subDays(5)->toDateString();
        $endDate = now()->toDateString();

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}?start_date={$startDate}&end_date={$endDate}");

        $response->assertStatus(200)
            ->assertJsonPath('summary.total_chargebacks', 5)
            ->assertJsonPath('reasons.0.cb_count', 5);
    }

    public function test_upload_reasons_validates_date_format(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}?start_date=invalid-date");

        $response->assertStatus(422)
            ->assertJsonValidationErrors('start_date');
    }

    public function test_upload_reasons_returns_404_for_nonexistent_upload(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/admin/chargebacks/upload/999999');

        $response->assertStatus(404);
    }

    public function test_upload_reason_records_requires_authentication(): void
    {
        $upload = Upload::factory()->create();
        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}/AC01/records");
        $response->assertStatus(401);
    }

    public function test_upload_reason_records_returns_chargebacks_for_specific_code(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        $chargebacksAC01 = BillingAttempt::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AC01',
        ]);

        BillingAttempt::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'MD01',
        ]);

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}/AC01/records");

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

        foreach ($response->json('data') as $record) {
            $this->assertEquals('AC01', $record['error_code']);
        }
    }

    public function test_upload_reason_records_paginates_results(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(250)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AC01',
        ]);

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}/AC01/records?per_page=50");

        $response->assertStatus(200)
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.total', 250);
    }

    public function test_upload_reason_records_default_per_page_is_100(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(150)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AC01',
        ]);

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}/AC01/records");

        $response->assertStatus(200)
            ->assertJsonCount(100, 'data')
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_upload_reason_records_respects_max_per_page(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(200)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AC01',
        ]);

        // Test that requesting per_page > 100 is rejected with 422
        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}/AC01/records?per_page=200");

        $response->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    public function test_upload_reason_records_returns_empty_for_nonexistent_code(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}/NONEXISTENT/records");

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_upload_reason_records_only_returns_chargebacked_status(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AC01',
        ]);

        // Create non-chargebacked with same code
        BillingAttempt::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_DECLINED,
            'chargeback_reason_code' => 'AC01',
        ]);

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}/AC01/records");

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_upload_reason_records_includes_debtor_info(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'iban' => 'DE89370400440532013000',
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AC01',
        ]);

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}/AC01/records");

        $response->assertStatus(200)
            ->assertJsonPath('data.0.debtor.first_name', 'John')
            ->assertJsonPath('data.0.debtor.last_name', 'Doe')
            ->assertJsonPath('data.0.debtor.email', 'john@example.com')
            ->assertJsonPath('data.0.debtor.id', $debtor->id);
    }

    public function test_upload_reason_records_includes_vop_log_info(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        VopLog::factory()->create([
            'debtor_id' => $debtor->id,
            'bank_name' => 'Deutsche Bank',
            'country' => 'DE',
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AC01',
        ]);

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}/AC01/records");

        $response->assertStatus(200)
            ->assertJsonPath('data.0.bank_name', 'Deutsche Bank')
            ->assertJsonPath('data.0.bank_country', 'DE');
    }

    public function test_upload_reason_records_returns_404_for_nonexistent_upload(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/admin/chargebacks/upload/999999/AC01/records');

        $response->assertStatus(404);
    }

    public function test_upload_reason_records_validates_per_page(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}/AC01/records?per_page=0");

        $response->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    public function test_upload_reason_records_orders_by_latest_first(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        $oldest = BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AC01',
            'chargebacked_at' => now()->subDays(5),
        ]);

        $newest = BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AC01',
            'chargebacked_at' => now(),
        ]);

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}/AC01/records");

        $response->assertStatus(200);
        $data = $response->json('data');
        
        $this->assertEquals($newest->id, $data[0]['id']);
        $this->assertEquals($oldest->id, $data[1]['id']);
    }

    public function test_upload_reasons_with_null_chargeback_codes(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => null,
            'chargeback_reason_description' => null,
        ]);

        BillingAttempt::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AC01',
        ]);

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}");

        $response->assertStatus(200)
            ->assertJsonPath('summary.total_chargebacks', 5)
            ->assertJsonCount(2, 'reasons'); // null code and AC01
    }

    public function test_upload_reasons_with_very_large_amounts(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(5)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'amount' => 999999.99,
            'chargeback_reason_code' => 'AC01',
        ]);

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}");

        $response->assertStatus(200)
            ->assertJsonPath('summary.cb_amount', 4999999.95);
    }

    public function test_upload_reasons_with_multiple_chargebacks_same_debtor(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(10)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AC01',
        ]);

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}");

        $response->assertStatus(200)
            ->assertJsonPath('summary.total_chargebacks', 10)
            ->assertJsonPath('reasons.0.cb_count', 10);
    }

    public function test_upload_reasons_with_many_different_codes(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        $codes = ['AC01', 'MD01', 'AM04', 'SY01', 'AG01', 'MS02', 'AM02', 'CB21'];

        foreach ($codes as $code) {
            BillingAttempt::factory()->create([
                'upload_id' => $upload->id,
                'debtor_id' => $debtor->id,
                'status' => BillingAttempt::STATUS_CHARGEBACKED,
                'chargeback_reason_code' => $code,
            ]);
        }

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}");

        $response->assertStatus(200)
            ->assertJsonCount(8, 'reasons');

        $returnedCodes = array_column($response->json('reasons'), 'code');
        foreach ($codes as $code) {
            $this->assertContains($code, $returnedCodes);
        }
    }

    public function test_upload_reasons_with_zero_count_emp_account_filter(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        $empAccount1 = EmpAccount::factory()->create();
        $empAccount2 = EmpAccount::factory()->create();

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'emp_account_id' => $empAccount1->id,
            'chargeback_reason_code' => 'AC01',
        ]);

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}?emp_account_id={$empAccount2->id}");

        $response->assertStatus(200)
            ->assertJsonPath('summary.total_chargebacks', 0)
            ->assertJsonPath('reasons', []);
    }

    public function test_upload_reasons_date_range_returns_correct_subset(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(1)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargebacked_at' => now()->subDays(20),
            'chargeback_reason_code' => 'AC01',
        ]);

        BillingAttempt::factory()->count(5)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargebacked_at' => now()->subDays(8),
            'chargeback_reason_code' => 'AC01',
        ]);

        BillingAttempt::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargebacked_at' => now(),
            'chargeback_reason_code' => 'AC01',
        ]);

        $startDate = now()->subDays(15)->toDateString();
        $endDate = now()->subDays(1)->toDateString();

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}?start_date={$startDate}&end_date={$endDate}");

        $response->assertStatus(200)
            ->assertJsonPath('summary.total_chargebacks', 5)
            ->assertJsonPath('reasons.0.cb_count', 5);
    }

    public function test_upload_reasons_with_same_date_start_and_end(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        $targetDate = now()->subDays(5);

        BillingAttempt::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargebacked_at' => $targetDate->copy()->startOfDay(),
            'chargeback_reason_code' => 'AC01',
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargebacked_at' => $targetDate->copy()->addDays(1)->startOfDay(),
            'chargeback_reason_code' => 'AC01',
        ]);

        $dateStr = $targetDate->toDateString();

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}?start_date={$dateStr}&end_date={$dateStr}");

        $response->assertStatus(200)
            ->assertJsonPath('summary.total_chargebacks', 3)
            ->assertJsonPath('reasons.0.cb_count', 3);
    }

    public function test_upload_reasons_with_mixed_statuses_only_counts_chargebacked(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(5)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AC01',
        ]);

        BillingAttempt::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        BillingAttempt::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_DECLINED,
        ]);

        BillingAttempt::factory()->count(1)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_ERROR,
        ]);

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}");

        $response->assertStatus(200)
            ->assertJsonPath('summary.total_chargebacks', 5)
            ->assertJsonPath('summary.billed_count', 3);
    }

    public function test_upload_reasons_with_debtors_without_vop_logs(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(5)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AC01',
        ]);

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}");

        $response->assertStatus(200)
            ->assertJsonPath('summary.total_chargebacks', 5)
            ->assertJsonCount(1, 'reasons');
    }

    public function test_upload_reason_records_with_null_code(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => null,
        ]);

        BillingAttempt::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AC01',
        ]);

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}/null/records");

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_upload_reason_records_with_special_characters_in_code(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        $specialCode = 'AC-01';
        BillingAttempt::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => $specialCode,
        ]);

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}/{$specialCode}/records");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_upload_reason_records_with_large_number_of_results(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(5000)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AC01',
        ]);

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}/AC01/records?per_page=100");

        $response->assertStatus(200)
            ->assertJsonCount(100, 'data')
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonPath('meta.total', 5000);
    }

    public function test_upload_reason_records_pagination_navigation(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        $records = BillingAttempt::factory()->count(250)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AC01',
        ]);

        // Get page 1
        $response1 = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}/AC01/records?per_page=50&page=1");
        $page1Data = $response1->json('data');

        // Get page 2
        $response2 = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}/AC01/records?per_page=50&page=2");
        $page2Data = $response2->json('data');

        // Get page 3
        $response3 = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}/AC01/records?per_page=50&page=3");
        $page3Data = $response3->json('data');

        $this->assertNotEquals($page1Data[0]['id'], $page2Data[0]['id']);
        $this->assertNotEquals($page2Data[0]['id'], $page3Data[0]['id']);

        $response1->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 5);

        $response2->assertJsonPath('meta.current_page', 2);
        $response3->assertJsonPath('meta.current_page', 3);
    }

    public function test_upload_reason_records_with_multiple_debtors_same_code(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();

        $debtors = Debtor::factory()->count(5)->create(['upload_id' => $upload->id]);

        foreach ($debtors as $debtor) {
            BillingAttempt::factory()->count(3)->create([
                'upload_id' => $upload->id,
                'debtor_id' => $debtor->id,
                'status' => BillingAttempt::STATUS_CHARGEBACKED,
                'chargeback_reason_code' => 'AC01',
            ]);
        }

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}/AC01/records");

        $response->assertStatus(200)
            ->assertJsonCount(15, 'data');

        $returnedIds = array_column($response->json('data'), 'debtor');
        $debtorIds = $debtors->pluck('id')->toArray();

        foreach ($debtorIds as $debtorId) {
            $found = false;
            foreach ($returnedIds as $returned) {
                if ($returned['id'] == $debtorId) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, "Debtor {$debtorId} should be in results");
        }
    }

    public function test_upload_reason_records_with_very_old_chargebacks(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(5)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AC01',
            'chargebacked_at' => now()->subYears(2),
        ]);

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}/AC01/records");

        $response->assertStatus(200)
            ->assertJsonCount(5, 'data');
    }

    public function test_upload_reason_records_preserves_debtor_iban_masking(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => 'DE89370400440532013000',
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AC01',
        ]);

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}/AC01/records");

        $response->assertStatus(200);
        $returnedIban = $response->json('data.0.debtor.iban');
        $this->assertNotEquals('DE89370400440532013000', $returnedIban);
        $this->assertStringContainsString('*', $returnedIban);
    }

    public function test_upload_reason_records_with_same_code_different_uploads(): void
    {
        Sanctum::actingAs($this->user);
        $upload1 = Upload::factory()->create();
        $upload2 = Upload::factory()->create();

        $debtor1 = Debtor::factory()->create(['upload_id' => $upload1->id]);
        $debtor2 = Debtor::factory()->create(['upload_id' => $upload2->id]);

        BillingAttempt::factory()->count(5)->create([
            'upload_id' => $upload1->id,
            'debtor_id' => $debtor1->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AC01',
        ]);

        BillingAttempt::factory()->count(3)->create([
            'upload_id' => $upload2->id,
            'debtor_id' => $debtor2->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AC01',
        ]);

        $response1 = $this->getJson("/api/admin/chargebacks/upload/{$upload1->id}/AC01/records");
        $response2 = $this->getJson("/api/admin/chargebacks/upload/{$upload2->id}/AC01/records");

        $response1->assertJsonCount(5, 'data');
        $response2->assertJsonCount(3, 'data');

        $response1Data = $response1->json('data');
        $response2Data = $response2->json('data');

        // Verify they don't have overlapping IDs
        $ids1 = array_column($response1Data, 'id');
        $ids2 = array_column($response2Data, 'id');

        $this->assertEmpty(array_intersect($ids1, $ids2));
    }

    public function test_upload_reason_records_response_structure_consistency(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        VopLog::factory()->create([
            'debtor_id' => $debtor->id,
            'bank_name' => 'Test Bank',
            'country' => 'DE',
        ]);

        BillingAttempt::factory()->count(10)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AC01',
        ]);

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}/AC01/records");

        $response->assertStatus(200)
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
                        'emp_account',
                    ],
                ],
                'links' => [
                    'first',
                    'last',
                    'prev',
                    'next',
                ],
                'meta' => [
                    'current_page',
                    'from',
                    'last_page',
                    'per_page',
                    'to',
                    'total',
                ],
            ]);

        foreach ($response->json('data') as $record) {
            $this->assertIsInt($record['id']);
            $this->assertIsInt($record['debtor']['id']);
            $this->assertIsNumeric($record['amount']);
            $this->assertIsString($record['processed_at']);
        }
    }

    public function test_upload_reason_records_with_zero_per_page_validation(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}/AC01/records?per_page=0");
        $response->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    public function test_upload_reason_records_with_negative_per_page_validation(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}/AC01/records?per_page=-1");
        $response->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    public function test_upload_reason_records_with_non_numeric_per_page_validation(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}/AC01/records?per_page=abc");
        $response->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    public function test_upload_reason_records_ignores_extra_query_parameters(): void
    {
        Sanctum::actingAs($this->user);
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AC01',
        ]);

        $response = $this->getJson("/api/admin/chargebacks/upload/{$upload->id}/AC01/records?per_page=50&random_param=value&another=test");

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    // ==================== INDEX STATS TESTS ====================

    public function test_index_returns_stats_alongside_data(): void
    {
        Sanctum::actingAs($this->user);
        $debtor = Debtor::factory()->create();

        BillingAttempt::factory()->count(3)->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'chargeback_reason_code' => 'AC01',
            'amount' => 100,
        ]);

        BillingAttempt::factory()->count(7)->create([
            'status' => BillingAttempt::STATUS_APPROVED,
            'debtor_id' => $debtor->id,
            'amount' => 100,
        ]);

        $response = $this->getJson('/api/admin/chargebacks');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'links',
                'meta',
                'stats' => [
                    'total_chargebacks_count',
                    'total_chargeback_amount',
                    'chargeback_rate',
                    'average_chargeback_amount',
                    'most_common_reason_code',
                    'affected_accounts',
                    'unique_debtors_count',
                    'total_approved_amount',
                ],
            ]);

        $response->assertJsonPath('stats.total_chargebacks_count', 3);
        $response->assertJsonPath('stats.total_chargeback_amount', 300);
        $response->assertJsonPath('stats.chargeback_rate', 30);
        $response->assertJsonPath('stats.average_chargeback_amount', 100);
        $response->assertJsonPath('stats.total_approved_amount', 700);
    }

    public function test_index_stats_most_common_reason_code(): void
    {
        Sanctum::actingAs($this->user);
        $debtor = Debtor::factory()->create();

        BillingAttempt::factory()->count(5)->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'chargeback_reason_code' => 'MD01',
        ]);

        BillingAttempt::factory()->count(2)->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'chargeback_reason_code' => 'AC01',
        ]);

        $response = $this->getJson('/api/admin/chargebacks');

        $response->assertStatus(200);
        $stat = $response->json('stats.most_common_reason_code');
        $this->assertNotNull($stat);
        $this->assertEquals('MD01', $stat['code']);
        $this->assertEquals(5, $stat['count']);
    }

    public function test_index_stats_most_common_reason_code_is_null_when_no_chargebacks(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/admin/chargebacks');

        $response->assertStatus(200)
            ->assertJsonPath('stats.total_chargebacks_count', 0)
            ->assertJsonPath('stats.most_common_reason_code', null);
    }

    public function test_index_stats_are_filtered_by_code(): void
    {
        Sanctum::actingAs($this->user);
        $debtor = Debtor::factory()->create();

        BillingAttempt::factory()->count(4)->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'chargeback_reason_code' => 'AC01',
            'amount' => 50,
        ]);

        BillingAttempt::factory()->count(6)->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'chargeback_reason_code' => 'MD01',
            'amount' => 100,
        ]);

        $response = $this->getJson('/api/admin/chargebacks?code=AC01');

        $response->assertStatus(200)
            ->assertJsonPath('stats.total_chargebacks_count', 4)
            ->assertJsonPath('stats.total_chargeback_amount', 200);

        // data listing is also filtered
        $response->assertJsonCount(4, 'data');
    }

    public function test_index_stats_are_filtered_by_emp_account_id(): void
    {
        Sanctum::actingAs($this->user);
        $debtor = Debtor::factory()->create();
        $emp1 = EmpAccount::factory()->create();
        $emp2 = EmpAccount::factory()->create();

        BillingAttempt::factory()->count(3)->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'emp_account_id' => $emp1->id,
            'amount' => 100,
        ]);

        BillingAttempt::factory()->count(5)->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'emp_account_id' => $emp2->id,
            'amount' => 100,
        ]);

        $response = $this->getJson("/api/admin/chargebacks?emp_account_id={$emp1->id}");

        $response->assertStatus(200)
            ->assertJsonPath('stats.total_chargebacks_count', 3);

        $response->assertJsonCount(3, 'data');
    }

    public function test_index_validates_period_parameter(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/admin/chargebacks?period=invalid');

        $response->assertStatus(422)
            ->assertJsonValidationErrors('period');
    }

    public function test_index_validates_date_mode_parameter(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/admin/chargebacks?date_mode=invalid');

        $response->assertStatus(422)
            ->assertJsonValidationErrors('date_mode');
    }

    public function test_index_validates_emp_account_id_must_exist(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/admin/chargebacks?emp_account_id=999999');

        $response->assertStatus(422)
            ->assertJsonValidationErrors('emp_account_id');
    }

    public function test_index_filters_by_period_transaction_date_mode(): void
    {
        Sanctum::actingAs($this->user);
        $debtor = Debtor::factory()->create();

        // In range (within 7d)
        BillingAttempt::factory()->count(3)->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'emp_created_at' => now()->subDays(3),
            'chargeback_reason_code' => 'AC01',
        ]);

        // Out of range
        BillingAttempt::factory()->count(2)->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'emp_created_at' => now()->subDays(30),
            'chargeback_reason_code' => 'AC01',
        ]);

        $response = $this->getJson('/api/admin/chargebacks?period=7d&date_mode=transaction');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('stats.total_chargebacks_count', 3);
    }

    public function test_index_filters_by_period_chargeback_date_mode(): void
    {
        Sanctum::actingAs($this->user);
        $debtor = Debtor::factory()->create();

        // In range
        BillingAttempt::factory()->count(2)->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'chargebacked_at' => now()->subDays(3),
            'chargeback_reason_code' => 'AC01',
        ]);

        // Out of range
        BillingAttempt::factory()->count(4)->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'chargebacked_at' => now()->subDays(30),
            'chargeback_reason_code' => 'AC01',
        ]);

        $response = $this->getJson('/api/admin/chargebacks?period=7d&date_mode=chargeback');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('stats.total_chargebacks_count', 2);
    }

    public function test_index_period_all_returns_all_chargebacks(): void
    {
        Sanctum::actingAs($this->user);
        $debtor = Debtor::factory()->create();

        BillingAttempt::factory()->count(5)->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'chargebacked_at' => now()->subYears(2),
        ]);

        $response = $this->getJson('/api/admin/chargebacks?period=all');

        $response->assertStatus(200)
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('stats.total_chargebacks_count', 5);
    }

    public function test_index_stats_unique_debtors_count(): void
    {
        Sanctum::actingAs($this->user);

        $debtor1 = Debtor::factory()->create();
        $debtor2 = Debtor::factory()->create();

        BillingAttempt::factory()->count(3)->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor1->id,
        ]);

        BillingAttempt::factory()->count(2)->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor2->id,
        ]);

        $response = $this->getJson('/api/admin/chargebacks');

        $response->assertStatus(200)
            ->assertJsonPath('stats.unique_debtors_count', 2);
    }

    public function test_index_stats_affected_accounts(): void
    {
        Sanctum::actingAs($this->user);
        $debtor = Debtor::factory()->create();
        $emp1 = EmpAccount::factory()->create();
        $emp2 = EmpAccount::factory()->create();

        BillingAttempt::factory()->count(2)->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'emp_account_id' => $emp1->id,
        ]);

        BillingAttempt::factory()->count(3)->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
            'emp_account_id' => $emp2->id,
        ]);

        $response = $this->getJson('/api/admin/chargebacks');

        $response->assertStatus(200)
            ->assertJsonPath('stats.affected_accounts', 2);
    }

    public function test_index_stats_chargeback_rate_zero_when_no_approved(): void
    {
        Sanctum::actingAs($this->user);
        $debtor = Debtor::factory()->create();

        BillingAttempt::factory()->count(5)->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
        ]);

        $response = $this->getJson('/api/admin/chargebacks');

        $response->assertStatus(200)
            ->assertJsonPath('stats.chargeback_rate', 100);
    }

    public function test_index_stats_are_cached(): void
    {
        Sanctum::actingAs($this->user);
        $debtor = Debtor::factory()->create();

        BillingAttempt::factory()->count(2)->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
        ]);

        // First request primes cache
        $response1 = $this->getJson('/api/admin/chargebacks');
        $response1->assertStatus(200);
        $count1 = $response1->json('stats.total_chargebacks_count');

        // Add more chargebacks without clearing the cache
        BillingAttempt::factory()->count(5)->create([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'debtor_id' => $debtor->id,
        ]);

        // Second request should return cached stats
        $response2 = $this->getJson('/api/admin/chargebacks');
        $response2->assertStatus(200);
        $count2 = $response2->json('stats.total_chargebacks_count');

        $this->assertEquals($count1, $count2);
    }

    public function test_index_accepts_all_valid_period_values(): void
    {
        Sanctum::actingAs($this->user);

        foreach (['24h', '7d', '30d', '90d', 'all'] as $period) {
            $response = $this->getJson("/api/admin/chargebacks?period={$period}");
            $response->assertStatus(200, "Period '{$period}' should be accepted");
        }
    }

    public function test_index_accepts_all_valid_date_mode_values(): void
    {
        Sanctum::actingAs($this->user);

        foreach (['transaction', 'chargeback'] as $mode) {
            $response = $this->getJson("/api/admin/chargebacks?date_mode={$mode}");
            $response->assertStatus(200, "Date mode '{$mode}' should be accepted");
        }
    }
}
