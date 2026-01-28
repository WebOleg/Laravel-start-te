<?php

namespace Tests\Feature\Admin\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }

    public function test_logout_requires_authentication(): void
    {
        $response = $this->postJson('/api/logout');

        $response->assertStatus(401);
    }

    public function test_logout_revokes_current_token(): void
    {
        // Create token for user
        $token = $this->user->createToken('test-token');
        $plainTextToken = $token->plainTextToken;

        // Logout
        $response = $this->withHeader('Authorization', 'Bearer ' . $plainTextToken)
            ->postJson('/api/logout');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Logged out']);

        // Verify token is deleted from database
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token->accessToken->id,
        ]);
    }

    public function test_logout_only_revokes_current_token_not_all_tokens(): void
    {
        // Create two tokens
        $token1 = $this->user->createToken('token-1');
        $token2 = $this->user->createToken('token-2');

        // Logout with token1
        $this->withHeader('Authorization', 'Bearer ' . $token1->plainTextToken)
            ->postJson('/api/logout')
            ->assertStatus(200);

        // Verify token1 is deleted but token2 still exists
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token1->accessToken->id,
        ]);

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $token2->accessToken->id,
        ]);
    }

    public function test_logout_returns_correct_json_structure(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/logout');

        $response->assertStatus(200)
            ->assertJsonStructure(['message'])
            ->assertJson(['message' => 'Logged out']);
    }

    public function test_logout_deletes_token_from_database(): void
    {
        $token = $this->user->createToken('test-token');
        $tokenId = $token->accessToken->id;

        // Verify token exists
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $tokenId,
        ]);

        // Logout
        $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/logout');

        // Verify token is deleted
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $tokenId,
        ]);
    }

    public function test_logout_with_bearer_token(): void
    {
        $token = $this->user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/logout');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Logged out']);
    }

    public function test_logout_maintains_user_in_database(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/logout');

        $response->assertStatus(200);

        // User should still exist in database
        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'email' => $this->user->email,
        ]);
    }

    public function test_logout_works_for_different_users(): void
    {
        $user1 = User::factory()->create(['email' => 'user1@example.com']);
        $user2 = User::factory()->create(['email' => 'user2@example.com']);

        $token1 = $user1->createToken('token-1');
        $token2 = $user2->createToken('token-2');

        // User1 logs out
        $this->withHeader('Authorization', 'Bearer ' . $token1->plainTextToken)
            ->postJson('/api/logout')
            ->assertStatus(200);

        // Verify user1's token is deleted
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token1->accessToken->id,
        ]);

        // Verify user2's token still exists
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $token2->accessToken->id,
        ]);
    }

    public function test_logout_with_invalid_token_returns_unauthorized(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer invalid-token-12345')
            ->postJson('/api/logout');

        $response->assertStatus(401);
    }

    public function test_logout_without_authorization_header(): void
    {
        $response = $this->postJson('/api/logout');

        $response->assertStatus(401);
    }

    public function test_logout_multiple_sessions(): void
    {
        // Create 3 tokens (3 sessions)
        $token1 = $this->user->createToken('session-1');
        $token2 = $this->user->createToken('session-2');
        $token3 = $this->user->createToken('session-3');

        // Logout from session 2
        $this->withHeader('Authorization', 'Bearer ' . $token2->plainTextToken)
            ->postJson('/api/logout')
            ->assertStatus(200);

        // Session 1 and 3 should still exist in database, session 2 should not
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $token1->accessToken->id,
        ]);
        
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token2->accessToken->id,
        ]);
        
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $token3->accessToken->id,
        ]);
    }

    public function test_logout_token_count_decreases(): void
    {
        // Create multiple tokens
        $this->user->createToken('token-1');
        $this->user->createToken('token-2');
        $token3 = $this->user->createToken('token-3');

        // Should have 3 tokens
        $this->assertEquals(3, $this->user->tokens()->count());

        // Logout with token3
        $this->withHeader('Authorization', 'Bearer ' . $token3->plainTextToken)
            ->postJson('/api/logout');

        // Should now have 2 tokens
        $this->assertEquals(2, $this->user->fresh()->tokens()->count());
    }
}
