<?php

namespace Tests\Feature\Admin;

use App\Models\EmpAccount;
use App\Models\User;
use App\Models\WebhookRelay;
use App\Services\WebhookRelayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WebhookRelayControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test')->plainTextToken;

        // Mock deployProxies so tests don't trigger real Nginx deploys
        $mock = Mockery::mock(WebhookRelayService::class);
        $mock->shouldReceive('deployProxies')->andReturn(null);
        $mock->shouldReceive('ensureUniqueDomain')->andReturn(null);
        $this->app->instance(WebhookRelayService::class, $mock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function authHeader(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    // ──────────────────────────────────────────────
    // index()
    // ──────────────────────────────────────────────

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/webhook-relays');

        $response->assertStatus(401);
    }

    public function test_index_returns_paginated_relays(): void
    {
        WebhookRelay::factory()->count(3)->create();

        $response = $this->withHeaders($this->authHeader())
            ->getJson('/api/admin/webhook-relays');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'domain', 'target', 'created_at', 'updated_at'],
                ],
                'meta' => ['current_page', 'total'],
            ]);
    }

    public function test_index_returns_empty_when_no_relays(): void
    {
        $response = $this->withHeaders($this->authHeader())
            ->getJson('/api/admin/webhook-relays');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_index_respects_per_page_parameter(): void
    {
        WebhookRelay::factory()->count(15)->create();

        $response = $this->withHeaders($this->authHeader())
            ->getJson('/api/admin/webhook-relays?per_page=5');

        $response->assertStatus(200)
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.total', 15);
    }

    public function test_index_caps_per_page_at_100(): void
    {
        WebhookRelay::factory()->count(5)->create();

        $response = $this->withHeaders($this->authHeader())
            ->getJson('/api/admin/webhook-relays?per_page=500');

        $response->assertStatus(200);
        // Should not error — capped internally to 100
    }

    public function test_index_includes_emp_accounts_relationship(): void
    {
        $account = EmpAccount::factory()->create();
        $relay = WebhookRelay::factory()->create();
        $relay->empAccounts()->attach($account->id);

        $response = $this->withHeaders($this->authHeader())
            ->getJson('/api/admin/webhook-relays');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.emp_accounts.0.id', $account->id);
    }

    public function test_index_orders_by_newest_first(): void
    {
        $older = WebhookRelay::factory()->create(['created_at' => now()->subDays(2)]);
        $newer = WebhookRelay::factory()->create(['created_at' => now()]);

        $response = $this->withHeaders($this->authHeader())
            ->getJson('/api/admin/webhook-relays');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals($newer->id, $data[0]['id']);
        $this->assertEquals($older->id, $data[1]['id']);
    }

    // ──────────────────────────────────────────────
    // store()
    // ──────────────────────────────────────────────

    public function test_store_requires_authentication(): void
    {
        $response = $this->postJson('/api/admin/webhook-relays', []);

        $response->assertStatus(401);
    }

    public function test_store_creates_relay_with_emp_accounts(): void
    {
        $accounts = EmpAccount::factory()->count(2)->create();

        $response = $this->withHeaders($this->authHeader())
            ->postJson('/api/admin/webhook-relays', [
                'domain' => 'webhook.example.com',
                'target' => 'https://target.example.com/hook',
                'emp_account_ids' => $accounts->pluck('id')->toArray(),
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.domain', 'webhook.example.com')
            ->assertJsonPath('data.target', 'https://target.example.com/hook');

        $this->assertDatabaseHas('webhook_relays', [
            'domain' => 'webhook.example.com',
            'target' => 'https://target.example.com/hook',
        ]);

        $relay = WebhookRelay::where('domain', 'webhook.example.com')->first();
        $this->assertCount(2, $relay->empAccounts);
    }

    public function test_store_returns_emp_accounts_in_response(): void
    {
        $account = EmpAccount::factory()->create();

        $response = $this->withHeaders($this->authHeader())
            ->postJson('/api/admin/webhook-relays', [
                'domain' => 'hook.test.com',
                'target' => 'https://target.test.com/wh',
                'emp_account_ids' => [$account->id],
            ]);

        $response->assertStatus(201)
            ->assertJsonCount(1, 'data.emp_accounts')
            ->assertJsonPath('data.emp_accounts.0.id', $account->id);
    }

    public function test_store_requires_domain(): void
    {
        $account = EmpAccount::factory()->create();

        $response = $this->withHeaders($this->authHeader())
            ->postJson('/api/admin/webhook-relays', [
                'target' => 'https://target.example.com/hook',
                'emp_account_ids' => [$account->id],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['domain']);
    }

    public function test_store_requires_target(): void
    {
        $account = EmpAccount::factory()->create();

        $response = $this->withHeaders($this->authHeader())
            ->postJson('/api/admin/webhook-relays', [
                'domain' => 'webhook.example.com',
                'emp_account_ids' => [$account->id],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['target']);
    }

    public function test_store_requires_valid_url_for_target(): void
    {
        $account = EmpAccount::factory()->create();

        $response = $this->withHeaders($this->authHeader())
            ->postJson('/api/admin/webhook-relays', [
                'domain' => 'webhook.example.com',
                'target' => 'not-a-url',
                'emp_account_ids' => [$account->id],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['target']);
    }

    public function test_store_requires_emp_account_ids(): void
    {
        $response = $this->withHeaders($this->authHeader())
            ->postJson('/api/admin/webhook-relays', [
                'domain' => 'webhook.example.com',
                'target' => 'https://target.example.com/hook',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['emp_account_ids']);
    }

    public function test_store_requires_at_least_one_emp_account(): void
    {
        $response = $this->withHeaders($this->authHeader())
            ->postJson('/api/admin/webhook-relays', [
                'domain' => 'webhook.example.com',
                'target' => 'https://target.example.com/hook',
                'emp_account_ids' => [],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['emp_account_ids']);
    }

    public function test_store_rejects_nonexistent_emp_account_ids(): void
    {
        $response = $this->withHeaders($this->authHeader())
            ->postJson('/api/admin/webhook-relays', [
                'domain' => 'webhook.example.com',
                'target' => 'https://target.example.com/hook',
                'emp_account_ids' => [99999],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['emp_account_ids.0']);
    }

    public function test_store_rejects_domain_exceeding_max_length(): void
    {
        $account = EmpAccount::factory()->create();

        $response = $this->withHeaders($this->authHeader())
            ->postJson('/api/admin/webhook-relays', [
                'domain' => str_repeat('a', 256),
                'target' => 'https://target.example.com/hook',
                'emp_account_ids' => [$account->id],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['domain']);
    }

    // ──────────────────────────────────────────────
    // update()
    // ──────────────────────────────────────────────

    public function test_update_requires_authentication(): void
    {
        $relay = WebhookRelay::factory()->create();

        $response = $this->putJson("/api/admin/webhook-relays/{$relay->id}", []);

        $response->assertStatus(401);
    }

    public function test_update_modifies_relay_fields(): void
    {
        $account = EmpAccount::factory()->create();
        $relay = WebhookRelay::factory()->create([
            'domain' => 'old.example.com',
            'target' => 'https://old.example.com/hook',
        ]);
        $relay->empAccounts()->attach($account->id);

        $response = $this->withHeaders($this->authHeader())
            ->putJson("/api/admin/webhook-relays/{$relay->id}", [
                'domain' => 'new.example.com',
                'target' => 'https://new.example.com/hook',
                'emp_account_ids' => [$account->id],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.domain', 'new.example.com')
            ->assertJsonPath('data.target', 'https://new.example.com/hook');

        $relay->refresh();
        $this->assertEquals('new.example.com', $relay->domain);
        $this->assertEquals('https://new.example.com/hook', $relay->target);
    }

    public function test_update_syncs_emp_accounts(): void
    {
        $account1 = EmpAccount::factory()->create();
        $account2 = EmpAccount::factory()->create();
        $account3 = EmpAccount::factory()->create();

        $relay = WebhookRelay::factory()->create();
        $relay->empAccounts()->attach([$account1->id, $account2->id]);

        // Replace account1+account2 with account2+account3
        $response = $this->withHeaders($this->authHeader())
            ->putJson("/api/admin/webhook-relays/{$relay->id}", [
                'domain' => $relay->domain,
                'target' => $relay->target,
                'emp_account_ids' => [$account2->id, $account3->id],
            ]);

        $response->assertStatus(200);

        $relay->refresh();
        $attachedIds = $relay->empAccounts->pluck('id')->sort()->values()->toArray();
        $this->assertEquals([$account2->id, $account3->id], $attachedIds);
    }

    public function test_update_returns_404_for_nonexistent_relay(): void
    {
        $account = EmpAccount::factory()->create();

        $response = $this->withHeaders($this->authHeader())
            ->putJson('/api/admin/webhook-relays/99999', [
                'domain' => 'new.example.com',
                'target' => 'https://new.example.com/hook',
                'emp_account_ids' => [$account->id],
            ]);

        $response->assertStatus(404);
    }

    public function test_update_validates_required_fields(): void
    {
        $relay = WebhookRelay::factory()->create();

        $response = $this->withHeaders($this->authHeader())
            ->putJson("/api/admin/webhook-relays/{$relay->id}", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['domain', 'target', 'emp_account_ids']);
    }

    public function test_update_returns_emp_accounts_in_response(): void
    {
        $account = EmpAccount::factory()->create();
        $relay = WebhookRelay::factory()->create();

        $response = $this->withHeaders($this->authHeader())
            ->putJson("/api/admin/webhook-relays/{$relay->id}", [
                'domain' => $relay->domain,
                'target' => $relay->target,
                'emp_account_ids' => [$account->id],
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data.emp_accounts')
            ->assertJsonPath('data.emp_accounts.0.id', $account->id);
    }

    // ──────────────────────────────────────────────
    // destroy()
    // ──────────────────────────────────────────────

    public function test_destroy_requires_authentication(): void
    {
        $relay = WebhookRelay::factory()->create();

        $response = $this->deleteJson("/api/admin/webhook-relays/{$relay->id}");

        $response->assertStatus(401);
    }

    public function test_destroy_deletes_relay(): void
    {
        $relay = WebhookRelay::factory()->create();

        $response = $this->withHeaders($this->authHeader())
            ->deleteJson("/api/admin/webhook-relays/{$relay->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Deleted successfully');

        $this->assertDatabaseMissing('webhook_relays', ['id' => $relay->id]);
    }

    public function test_destroy_returns_404_for_nonexistent_relay(): void
    {
        $response = $this->withHeaders($this->authHeader())
            ->deleteJson('/api/admin/webhook-relays/99999');

        $response->assertStatus(404);
    }

    public function test_destroy_removes_pivot_records(): void
    {
        $account = EmpAccount::factory()->create();
        $relay = WebhookRelay::factory()->create();
        $relay->empAccounts()->attach($account->id);

        $response = $this->withHeaders($this->authHeader())
            ->deleteJson("/api/admin/webhook-relays/{$relay->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('emp_account_webhook_relay', [
            'webhook_relay_id' => $relay->id,
        ]);
    }

    // ──────────────────────────────────────────────
    // Service Integration
    // ──────────────────────────────────────────────

    public function test_store_calls_deploy_proxies(): void
    {
        $mock = Mockery::mock(WebhookRelayService::class);
        $mock->shouldReceive('ensureUniqueDomain')->once();
        $mock->shouldReceive('deployProxies')->once();
        $this->app->instance(WebhookRelayService::class, $mock);

        $account = EmpAccount::factory()->create();

        $this->withHeaders($this->authHeader())
            ->postJson('/api/admin/webhook-relays', [
                'domain' => 'deploy.test.com',
                'target' => 'https://deploy.test.com/hook',
                'emp_account_ids' => [$account->id],
            ]);
    }

    public function test_update_calls_deploy_proxies(): void
    {
        $mock = Mockery::mock(WebhookRelayService::class);
        $mock->shouldReceive('ensureUniqueDomain')->once();
        $mock->shouldReceive('deployProxies')->once();
        $this->app->instance(WebhookRelayService::class, $mock);

        $account = EmpAccount::factory()->create();
        $relay = WebhookRelay::factory()->create();

        $this->withHeaders($this->authHeader())
            ->putJson("/api/admin/webhook-relays/{$relay->id}", [
                'domain' => 'updated.test.com',
                'target' => 'https://updated.test.com/hook',
                'emp_account_ids' => [$account->id],
            ]);
    }

    public function test_destroy_calls_deploy_proxies(): void
    {
        $mock = Mockery::mock(WebhookRelayService::class);
        $mock->shouldReceive('deployProxies')->once();
        $this->app->instance(WebhookRelayService::class, $mock);

        $relay = WebhookRelay::factory()->create();

        $this->withHeaders($this->authHeader())
            ->deleteJson("/api/admin/webhook-relays/{$relay->id}");
    }

    public function test_store_calls_ensure_unique_domain(): void
    {
        $mock = Mockery::mock(WebhookRelayService::class);
        $mock->shouldReceive('ensureUniqueDomain')->once()->with('unique.test.com');
        $mock->shouldReceive('deployProxies')->once();
        $this->app->instance(WebhookRelayService::class, $mock);

        $account = EmpAccount::factory()->create();

        $this->withHeaders($this->authHeader())
            ->postJson('/api/admin/webhook-relays', [
                'domain' => 'unique.test.com',
                'target' => 'https://unique.test.com/hook',
                'emp_account_ids' => [$account->id],
            ]);
    }

    public function test_update_calls_ensure_unique_domain_with_relay_id(): void
    {
        $relay = WebhookRelay::factory()->create();

        $mock = Mockery::mock(WebhookRelayService::class);
        $mock->shouldReceive('ensureUniqueDomain')->once()->with('changed.test.com', $relay->id);
        $mock->shouldReceive('deployProxies')->once();
        $this->app->instance(WebhookRelayService::class, $mock);

        $account = EmpAccount::factory()->create();

        $this->withHeaders($this->authHeader())
            ->putJson("/api/admin/webhook-relays/{$relay->id}", [
                'domain' => 'changed.test.com',
                'target' => 'https://changed.test.com/hook',
                'emp_account_ids' => [$account->id],
            ]);
    }
}
