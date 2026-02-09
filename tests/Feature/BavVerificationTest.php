<?php
/**
 * Tests for BAV verification functionality.
 */
namespace Tests\Feature;

use App\Models\BavCredit;
use App\Models\Upload;
use App\Models\User;
use App\Services\IbanBavService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BavVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Upload $upload;

    protected function setUp(): void
    {
        parent::setUp();
        
        config(['services.iban.mock' => true]);
        
        $this->user = User::factory()->create();
        $this->upload = Upload::factory()->create([
            'status' => 'completed',
            'vop_status' => 'completed',
        ]);

        // Create initial BAV credits record
        BavCredit::create([
            'credits_total' => 2500,
            'credits_used' => 2420,
            'expires_at' => now()->addYear(),
        ]);
    }

    public function test_get_bav_balance(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/bav/balance');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'credits_remaining',
                    'credits_total',
                    'credits_used',
                    'is_expired',
                ],
            ]);

        $this->assertEquals(80, $response->json('data.credits_remaining'));
        $this->assertEquals(2500, $response->json('data.credits_total'));
    }

    public function test_get_bav_stats_for_upload(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/admin/uploads/{$this->upload->id}/bav/stats");

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'upload_id',
                    'eligible_count',
                    'credits_remaining',
                    'credits_total',
                    'bav_status',
                    'can_start',
                ],
            ]);
    }

    public function test_start_bav_fails_with_no_eligible_debtors(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/admin/uploads/{$this->upload->id}/bav/start", [
                'limit' => 10,
            ]);

        $response->assertStatus(422);
        
        $json = $response->json();
        $this->assertFalse($json['success']);
        $this->assertNotEmpty($json['error']);
    }

    public function test_start_bav_fails_with_insufficient_credits(): void
    {
        // Set credits to 5
        BavCredit::query()->update(['credits_used' => 2495]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/admin/uploads/{$this->upload->id}/bav/start", [
                'limit' => 100,
            ]);

        $response->assertStatus(422);
        $this->assertStringContains('Insufficient BAV credits', $response->json('error'));
    }

    public function test_start_bav_fails_with_expired_credits(): void
    {
        // Set credits as expired
        BavCredit::query()->update(['expires_at' => now()->subDay()]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/admin/uploads/{$this->upload->id}/bav/start", [
                'limit' => 10,
            ]);

        $response->assertStatus(422);
        $this->assertStringContains('expired', $response->json('error'));
    }

    public function test_get_bav_status(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/admin/uploads/{$this->upload->id}/bav/status");

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'status',
                    'total',
                    'processed',
                    'percentage',
                ],
            ]);
    }

    public function test_cancel_fails_when_not_processing(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/admin/uploads/{$this->upload->id}/bav/cancel");

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => 'No BAV verification in progress',
            ]);
    }

    public function test_adjust_credits(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/bav/adjust', [
                'credits_total' => 5000,
                'credits_used' => 1000,
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Credits adjusted successfully',
            ]);

        $this->assertEquals(4000, $response->json('data.credits_remaining'));
        
        // Verify in DB
        $credit = BavCredit::getInstance()->fresh();
        $this->assertEquals(5000, $credit->credits_total);
        $this->assertEquals(1000, $credit->credits_used);
    }

    public function test_bav_credit_consume(): void
    {
        // Initial: 80 remaining (2500 - 2420)
        $this->assertTrue(BavCredit::consume(1));
        
        $credit = BavCredit::getInstance()->fresh();
        $this->assertEquals(2421, $credit->credits_used);
        $this->assertEquals(79, $credit->getRemaining());
    }

    public function test_bav_credit_consume_fails_when_insufficient(): void
    {
        // Try to consume more than available
        $this->assertFalse(BavCredit::consume(100));
        
        // Credits unchanged
        $credit = BavCredit::getInstance()->fresh();
        $this->assertEquals(2420, $credit->credits_used);
    }

    public function test_bav_credit_refill(): void
    {
        $credit = BavCredit::getInstance();
        $credit->refill(2500, 'admin@test.com');

        $credit->refresh();
        $this->assertEquals(5000, $credit->credits_total);
        $this->assertEquals(2580, $credit->getRemaining());
        $this->assertNotNull($credit->last_refill_at);
    }

    public function test_bav_auto_select_config(): void
    {
        config(['services.iban.bav_auto_select' => false]);
        $this->assertFalse(config('services.iban.bav_auto_select'));

        config(['services.iban.bav_auto_select' => true]);
        $this->assertTrue(config('services.iban.bav_auto_select'));
    }

    public function test_upload_bav_progress(): void
    {
        $progress = $this->upload->getBavProgress();

        $this->assertArrayHasKey('status', $progress);
        $this->assertArrayHasKey('total', $progress);
        $this->assertArrayHasKey('processed', $progress);
        $this->assertArrayHasKey('percentage', $progress);
        $this->assertEquals('idle', $progress['status']);
    }

    public function test_bav_supported_countries_config(): void
    {
        $countries = config('services.iban.bav_supported_countries');
        
        $this->assertIsArray($countries);
        $this->assertContains('DE', $countries);
        $this->assertContains('FR', $countries);
        $this->assertContains('NL', $countries);
        $this->assertNotContains('GB', $countries);
        $this->assertNotContains('US', $countries);
    }

    private function assertStringContains(string $needle, ?string $haystack): void
    {
        $this->assertNotNull($haystack);
        $this->assertStringContainsString($needle, $haystack);
    }
}
