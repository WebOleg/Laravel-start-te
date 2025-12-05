<?php

/**
 * Feature tests for Admin Dashboard endpoint.
 */

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\Upload;
use App\Models\Debtor;
use App\Models\VopLog;
use App\Models\BillingAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
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

    public function test_dashboard_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/dashboard');

        $response->assertStatus(401);
    }

    public function test_dashboard_returns_all_sections(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'uploads',
                    'debtors',
                    'vop',
                    'billing',
                    'recent_activity',
                    'trends',
                ],
            ]);
    }

    public function test_dashboard_returns_upload_stats(): void
    {
        Upload::factory()->count(3)->create(['status' => Upload::STATUS_COMPLETED]);
        Upload::factory()->count(2)->create(['status' => Upload::STATUS_PENDING]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.uploads.total', 5)
            ->assertJsonPath('data.uploads.completed', 3)
            ->assertJsonPath('data.uploads.pending', 2);
    }

    public function test_dashboard_returns_debtor_stats(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->count(5)->create([
            'upload_id' => $upload->id,
            'status' => Debtor::STATUS_PENDING,
            'amount' => 100,
        ]);
        Debtor::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'status' => Debtor::STATUS_RECOVERED,
            'amount' => 100,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.debtors.total', 8)
            ->assertJsonPath('data.debtors.by_status.pending', 5)
            ->assertJsonPath('data.debtors.by_status.recovered', 3)
            ->assertJsonPath('data.debtors.total_amount', 800)
            ->assertJsonPath('data.debtors.recovered_amount', 300)
            ->assertJsonPath('data.debtors.recovery_rate', 37.5);
    }

    public function test_dashboard_returns_trends(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonCount(7, 'data.trends');
    }

    public function test_dashboard_returns_recent_activity(): void
    {
        Upload::factory()->count(10)->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonCount(5, 'data.recent_activity.recent_uploads');
    }
}
