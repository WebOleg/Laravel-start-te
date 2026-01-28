<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\TransactionDescriptor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DescriptorControllerTest extends TestCase
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

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    public function test_descriptors_require_authentication(): void
    {
        $this->getJson('/api/admin/billing/descriptors')->assertStatus(401);
    }

    public function test_index_returns_ordered_descriptors(): void
    {
        TransactionDescriptor::factory()->create(['year' => 2024, 'month' => 1, 'is_default' => false]);
        TransactionDescriptor::factory()->create(['year' => 2025, 'month' => 1, 'is_default' => true]);

        $response = $this->withHeaders($this->authHeaders())->getJson('/api/admin/billing/descriptors');

        $response->assertStatus(200);

        $this->assertTrue((bool) $response->json('data.0.is_default'));
        $this->assertFalse((bool) $response->json('data.1.is_default'));
    }

    public function test_store_creates_descriptor_and_handles_default_toggle(): void
    {
        $existingDefault = TransactionDescriptor::factory()->create(['is_default' => true]);

        $payload = [
            'descriptor_name'    => 'TETHER TEST',
            'descriptor_city'    => 'London',
            'descriptor_country' => 'GBR',
            'year'               => 2026,
            'month'              => 12,
            'is_default'         => true,
        ];

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/admin/billing/descriptors', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.descriptor_name', 'TETHER TEST')
            ->assertJsonPath('data.is_default', true);

        $this->assertDatabaseHas('transaction_descriptors', [
            'id' => $existingDefault->id,
            'is_default' => false,
        ]);
    }

    public function test_update_modifies_descriptor_and_can_take_over_default(): void
    {
        $existingDefault = TransactionDescriptor::factory()->create(['is_default' => true]);
        $target = TransactionDescriptor::factory()->create(['is_default' => false]);

        $payload = [
            'descriptor_name' => 'UPDATED NAME',
            'year'            => $target->year,
            'month'           => $target->month,
            'is_default'      => true,
        ];

        $response = $this->withHeaders($this->authHeaders())
            ->putJson("/api/admin/billing/descriptors/{$target->id}", $payload);

        $response->assertStatus(200);

        $this->assertDatabaseHas('transaction_descriptors', [
            'id' => $existingDefault->id,
            'is_default' => false,
        ]);
    }

    public function test_destroy_deletes_descriptor(): void
    {
        $descriptor = TransactionDescriptor::factory()->create();

        $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/admin/billing/descriptors/{$descriptor->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('transaction_descriptors', ['id' => $descriptor->id]);
    }
}
