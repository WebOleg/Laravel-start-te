<?php

/**
 * Feature tests for WebhookRelayController.
 */

namespace Tests\Feature\Admin;

use App\Models\EmpAccount;
use App\Models\User;
use App\Models\WebhookRelay;
use App\Services\WebhookRelayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class WebhookRelayControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;
    private MockInterface $mockService;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup Auth
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test')->plainTextToken;

        // Mock the WebhookRelayService to prevent actual Nginx deployments during testing
        $this->mockService = Mockery::mock(WebhookRelayService::class);
        $this->app->instance(WebhookRelayService::class, $this->mockService);
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/webhook-relays');

        $response->assertStatus(401);
    }

    public function test_index_returns_paginated_relays_with_accounts(): void
    {
        $account = EmpAccount::factory()->create();
        $relay = WebhookRelay::factory()->create();
        $relay->empAccounts()->attach($account->id);

        // Create extra relays to test pagination counts
        WebhookRelay::factory()->count(2)->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
                         ->getJson('/api/admin/webhook-relays');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $relay->id)
            ->assertJsonPath('data.0.domain', $relay->domain)
            ->assertJsonIsArray('data.0.emp_accounts');
    }

    public function test_store_requires_authentication(): void
    {
        $response = $this->postJson('/api/admin/webhook-relays', [
            'domain' => 'https://relay.com',
            'target' => 'https://target.com',
        ]);

        $response->assertStatus(401);
    }

    public function test_store_creates_relay_and_syncs_accounts(): void
    {
        $account1 = EmpAccount::factory()->create();
        $account2 = EmpAccount::factory()->create();

        $payload = [
            'domain' => 'https://new-relay.com',
            'target' => 'https://internal-target.com',
            'emp_account_ids' => [$account1->id, $account2->id],
        ];

        // Expect the service validation and deployment methods to be called
        $this->mockService->shouldReceive('ensureUniqueDomain')
            ->with('https://new-relay.com')
            ->once();

        $this->mockService->shouldReceive('deployProxies')
            ->once();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/webhook-relays', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.domain', 'https://new-relay.com');

        // Verify the database record was created
        $this->assertDatabaseHas('webhook_relays', [
            'domain' => 'https://new-relay.com',
            'target' => 'https://internal-target.com',
        ]);

        $relay = WebhookRelay::where('domain', 'https://new-relay.com')->first();

        // Verify the pivot table relationships were established
        $this->assertDatabaseHas('emp_account_webhook_relay', [
            'webhook_relay_id' => $relay->id,
            'emp_account_id' => $account1->id,
        ]);

        $this->assertDatabaseHas('emp_account_webhook_relay', [
            'webhook_relay_id' => $relay->id,
            'emp_account_id' => $account2->id,
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/webhook-relays', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['domain', 'target']);
    }

    public function test_update_returns_404_for_nonexistent_relay(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson('/api/admin/webhook-relays/99999', [
                'domain' => 'https://updated.com',
                'target' => 'https://target.com',
            ]);

        $response->assertStatus(404);
    }

    public function test_update_modifies_relay_and_syncs_accounts(): void
    {
        $originalAccount = EmpAccount::factory()->create();
        $newAccount = EmpAccount::factory()->create();

        $relay = WebhookRelay::factory()->create([
            'domain' => 'https://old-relay.com',
            'target' => 'https://old-target.com',
        ]);

        $relay->empAccounts()->attach($originalAccount->id);

        $payload = [
            'domain' => 'https://updated-relay.com',
            'target' => 'https://updated-target.com',
            'emp_account_ids' => [$newAccount->id], // Replacing the old account with the new one
        ];

        // Service expectations
        $this->mockService->shouldReceive('ensureUniqueDomain')
            ->with('https://updated-relay.com', $relay->id)
            ->once();

        $this->mockService->shouldReceive('deployProxies')
            ->once();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/admin/webhook-relays/{$relay->id}", $payload);

        $response->assertStatus(200)
            ->assertJsonPath('data.domain', 'https://updated-relay.com');

        // Verify the database record was updated
        $this->assertDatabaseHas('webhook_relays', [
            'id' => $relay->id,
            'domain' => 'https://updated-relay.com',
            'target' => 'https://updated-target.com',
        ]);

        // Verify the pivot table dropped the old account and added the new one
        $this->assertDatabaseMissing('emp_account_webhook_relay', [
            'webhook_relay_id' => $relay->id,
            'emp_account_id' => $originalAccount->id,
        ]);

        $this->assertDatabaseHas('emp_account_webhook_relay', [
            'webhook_relay_id' => $relay->id,
            'emp_account_id' => $newAccount->id,
        ]);
    }

    public function test_destroy_requires_authentication(): void
    {
        $relay = WebhookRelay::factory()->create();

        $response = $this->deleteJson("/api/admin/webhook-relays/{$relay->id}");

        $response->assertStatus(401);
    }

    public function test_destroy_removes_relay_and_redeploys(): void
    {
        $relay = WebhookRelay::factory()->create();

        $this->mockService->shouldReceive('deployProxies')
            ->once();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson("/api/admin/webhook-relays/{$relay->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Deleted successfully');

        $this->assertDatabaseMissing('webhook_relays', [
            'id' => $relay->id,
        ]);
    }
}
