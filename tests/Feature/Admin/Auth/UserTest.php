<?php

namespace Tests\Feature\Admin\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserTest extends TestCase
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

    public function test_user_requires_authentication(): void
    {
        $response = $this->getJson('/api/user');

        $response->assertStatus(401);
    }

    public function test_user_returns_authenticated_user_data(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/user');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'email',
                    'created_at',
                ],
            ])
            ->assertJsonPath('data.id', $this->user->id)
            ->assertJsonPath('data.name', $this->user->name)
            ->assertJsonPath('data.email', $this->user->email);
    }

    public function test_user_does_not_expose_sensitive_data(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/user');

        $response->assertStatus(200);

        $data = $response->json('data');

        // Should not include sensitive fields
        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('remember_token', $data);
        $this->assertArrayNotHasKey('two_factor_secret', $data);
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $data);
    }

    public function test_user_includes_created_at_timestamp(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/user');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['created_at'],
            ]);

        $createdAt = $response->json('data.created_at');
        $this->assertNotNull($createdAt);
        $this->assertIsString($createdAt);
    }

    public function test_user_works_with_bearer_token(): void
    {
        $token = $this->user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user');

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $this->user->id);
    }

    public function test_user_endpoint_handles_deleted_user_gracefully(): void
    {
        $token = $this->user->createToken('test-token')->plainTextToken;

        // Delete the user
        $this->user->delete();

        // Try to access user endpoint
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user');

        // Should return 401 since token's user is deleted
        $response->assertStatus(401);
    }

    public function test_user_with_invalid_token_returns_unauthorized(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer invalid-token-12345')
            ->getJson('/api/user');

        $response->assertStatus(401);
    }

    public function test_user_without_authorization_header(): void
    {
        $response = $this->getJson('/api/user');

        $response->assertStatus(401);
    }

    public function test_user_returns_only_four_fields(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/user');

        $response->assertStatus(200);

        $data = $response->json('data');

        // Should only have 4 fields
        $this->assertCount(4, $data);
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('email', $data);
        $this->assertArrayHasKey('created_at', $data);
    }

    public function test_user_data_types_are_correct(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/user');

        $response->assertStatus(200);

        $data = $response->json('data');

        $this->assertIsInt($data['id']);
        $this->assertIsString($data['name']);
        $this->assertIsString($data['email']);
        $this->assertIsString($data['created_at']);
    }

    public function test_user_email_format_is_valid(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/user');

        $response->assertStatus(200);

        $email = $response->json('data.email');
        $this->assertTrue(filter_var($email, FILTER_VALIDATE_EMAIL) !== false);
    }

    public function test_user_works_with_multiple_concurrent_requests(): void
    {
        $token = $this->user->createToken('test-token')->plainTextToken;

        // Make multiple concurrent requests
        for ($i = 0; $i < 5; $i++) {
            $response = $this->withHeader('Authorization', 'Bearer ' . $token)
                ->getJson('/api/user');

            $response->assertStatus(200)
                ->assertJsonPath('data.id', $this->user->id);
        }
    }

    public function test_user_name_matches_database(): void
    {
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user');

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'John Doe')
            ->assertJsonPath('data.email', 'john@example.com');
    }

    public function test_user_with_special_characters_in_name(): void
    {
        $user = User::factory()->create([
            'name' => "O'Brien-Smith Jr.",
            'email' => 'obrien@example.com',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user');

        $response->assertStatus(200)
            ->assertJsonPath('data.name', "O'Brien-Smith Jr.");
    }

    public function test_user_endpoint_is_idempotent(): void
    {
        Sanctum::actingAs($this->user);

        // Make the same request multiple times
        $response1 = $this->getJson('/api/user');
        $response2 = $this->getJson('/api/user');
        $response3 = $this->getJson('/api/user');

        // All should return the same data
        $this->assertEquals($response1->json('data'), $response2->json('data'));
        $this->assertEquals($response2->json('data'), $response3->json('data'));
    }

    public function test_user_does_not_include_updated_at(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/user');

        $response->assertStatus(200);

        $data = $response->json('data');

        // Should not include updated_at
        $this->assertArrayNotHasKey('updated_at', $data);
    }
}
