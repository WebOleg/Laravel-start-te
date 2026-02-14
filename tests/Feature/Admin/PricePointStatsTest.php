<?php

namespace Tests\Feature\Admin;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\DebtorProfile;
use App\Models\EmpAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricePointStatsTest extends TestCase
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

    public function test_price_points_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/stats/price-points');
        $response->assertStatus(401);
    }

    public function test_price_points_returns_stats_grouped_by_amount(): void
    {
        $profile = DebtorProfile::factory()->create();
        $debtor = Debtor::factory()->create(['debtor_profile_id' => $profile->id]);

        BillingAttempt::factory()->count(3)->create([
            'debtor_id' => $debtor->id,
            'debtor_profile_id' => $profile->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 9.99,
            'created_at' => now()->subHour(),
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'debtor_profile_id' => $profile->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'amount' => 9.99,
            'created_at' => now()->subHour(),
            'chargebacked_at' => now(),
        ]);

        BillingAttempt::factory()->count(2)->create([
            'debtor_id' => $debtor->id,
            'debtor_profile_id' => $profile->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 19.99,
            'created_at' => now()->subHour(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/stats/price-points?period=all');

        $response->assertStatus(200);

        $data = $response->json('data');
        $pricePoints = collect($data['price_points']);

        $this->assertCount(2, $pricePoints);

        $pp999 = $pricePoints->firstWhere('price_point', 9.99);
        $this->assertNotNull($pp999);
        $this->assertEquals(4, $pp999['total']);
        $this->assertEquals(3, $pp999['approved']);
        $this->assertEquals(1, $pp999['chargebacks']);

        $pp1999 = $pricePoints->firstWhere('price_point', 19.99);
        $this->assertNotNull($pp1999);
        $this->assertEquals(2, $pp1999['total']);
        $this->assertEquals(2, $pp1999['approved']);
        $this->assertEquals(0, $pp1999['chargebacks']);
    }

    public function test_price_points_calculates_cb_rate_correctly(): void
    {
        $profile = DebtorProfile::factory()->create();
        $debtor = Debtor::factory()->create(['debtor_profile_id' => $profile->id]);

        BillingAttempt::factory()->count(4)->create([
            'debtor_id' => $debtor->id,
            'debtor_profile_id' => $profile->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 29.99,
            'created_at' => now()->subHour(),
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'debtor_profile_id' => $profile->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'amount' => 29.99,
            'created_at' => now()->subHour(),
            'chargebacked_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/stats/price-points?period=all');

        $response->assertStatus(200);

        $data = $response->json('data');
        $pp = collect($data['price_points'])->firstWhere('price_point', 29.99);

        $this->assertEquals(25.0, $pp['cb_rate']);
        $this->assertFalse($pp["alert"]);
    }

    public function test_price_points_filters_by_emp_account(): void
    {
        $account1 = EmpAccount::create(["name" => "Test1", "slug" => "test1", "endpoint" => "https://test1.com", "username" => "u1", "password" => "p1", "terminal_token" => "t1", "is_active" => true, "sort_order" => 1]);
        $account2 = EmpAccount::create(["name" => "Test2", "slug" => "test2", "endpoint" => "https://test2.com", "username" => "u2", "password" => "p2", "terminal_token" => "t2", "is_active" => false, "sort_order" => 2]);
        $profile = DebtorProfile::factory()->create();
        $debtor = Debtor::factory()->create(['debtor_profile_id' => $profile->id]);

        BillingAttempt::factory()->count(3)->create([
            'debtor_id' => $debtor->id,
            'debtor_profile_id' => $profile->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 9.99,
            'emp_account_id' => $account1->id,
            'created_at' => now()->subHour(),
        ]);

        BillingAttempt::factory()->count(2)->create([
            'debtor_id' => $debtor->id,
            'debtor_profile_id' => $profile->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 9.99,
            'emp_account_id' => $account2->id,
            'created_at' => now()->subHour(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/stats/price-points?period=all&emp_account_id=' . $account1->id);

        $response->assertStatus(200);

        $data = $response->json('data');
        $pp = collect($data['price_points'])->firstWhere('price_point', 9.99);

        $this->assertEquals(3, $pp['total']);
    }

    public function test_price_points_excludes_xt33_and_xt73(): void
    {
        $profile = DebtorProfile::factory()->create();
        $debtor = Debtor::factory()->create(['debtor_profile_id' => $profile->id]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'debtor_profile_id' => $profile->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AM04',
            'amount' => 9.99,
            'created_at' => now()->subHour(),
            'chargebacked_at' => now(),
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'debtor_profile_id' => $profile->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'XT33',
            'amount' => 9.99,
            'created_at' => now()->subHour(),
            'chargebacked_at' => now(),
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'debtor_profile_id' => $profile->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'XT73',
            'amount' => 9.99,
            'created_at' => now()->subHour(),
            'chargebacked_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/stats/price-points?period=all');

        $response->assertStatus(200);

        $data = $response->json('data');
        $pp = collect($data['price_points'])->firstWhere('price_point', 9.99);

        $this->assertEquals(1, $pp['chargebacks'], 'Should exclude XT33 and XT73');
    }

    public function test_price_points_totals_are_correct(): void
    {
        $profile = DebtorProfile::factory()->create();
        $debtor = Debtor::factory()->create(['debtor_profile_id' => $profile->id]);

        BillingAttempt::factory()->count(2)->create([
            'debtor_id' => $debtor->id,
            'debtor_profile_id' => $profile->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 9.99,
            'created_at' => now()->subHour(),
        ]);

        BillingAttempt::factory()->count(3)->create([
            'debtor_id' => $debtor->id,
            'debtor_profile_id' => $profile->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 19.99,
            'created_at' => now()->subHour(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/stats/price-points?period=all');

        $response->assertStatus(200);

        $totals = $response->json('data.totals');
        $this->assertEquals(5, $totals['total']);
        $this->assertEquals(5, $totals['approved']);
        $this->assertEquals(0, $totals['chargebacks']);
    }

    public function test_price_points_response_structure(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/stats/price-points?period=30d');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'period',
                    'date_mode',
                    'threshold',
                    'price_points',
                    'totals' => [
                        'total',
                        'approved',
                        'declined',
                        'errors',
                        'chargebacks',
                        'approved_volume',
                        'chargeback_volume',
                        'cb_rate',
                        'alert',
                    ],
                ],
            ]);
    }
}
