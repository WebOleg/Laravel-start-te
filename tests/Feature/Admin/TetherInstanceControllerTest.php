<?php

/**
 * Feature tests for TetherInstance admin API endpoint.
 */

namespace Tests\Feature\Admin;

use App\Models\TetherInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TetherInstanceControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user  = User::factory()->create();
        $this->token = $this->user->createToken('test')->plainTextToken;

        // The create_tether_instances_table migration seeds one record; delete it
        // so count assertions start from a clean state.
        TetherInstance::query()->delete();
    }

    public function test_index_returns_all_instances(): void
    {
        TetherInstance::factory()->active()->create(['name' => 'EMP', 'slug' => 'emp']);
        TetherInstance::factory()->inactive()->create(['name' => 'FinXP', 'slug' => 'finxp']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/tether-instances');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'slug', 'acquirer_type', 'is_active'],
                ],
            ]);
    }

    public function test_index_active_only_returns_only_active_instances(): void
    {
        TetherInstance::factory()->active()->create(['slug' => 'emp']);
        TetherInstance::factory()->inactive()->create(['slug' => 'finxp']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/tether-instances?active_only=true');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');

        $this->assertTrue($response->json('data.0.is_active'));
    }

    public function test_index_active_only_false_returns_all_instances(): void
    {
        TetherInstance::factory()->active()->create(['slug' => 'emp']);
        TetherInstance::factory()->inactive()->create(['slug' => 'finxp']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/tether-instances?active_only=false');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/tether-instances');

        $response->assertStatus(401);
    }
}
