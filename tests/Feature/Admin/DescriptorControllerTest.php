<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\TransactionDescriptor;
use App\Models\EmpAccount;
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

    public function test_index_includes_emp_account_relationship(): void
    {
        $empAccount = EmpAccount::factory()->create(['name' => 'Test Account']);
        TransactionDescriptor::factory()->create([
            'emp_account_id' => $empAccount->id,
            'descriptor_name' => 'EMP DESC',
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/admin/billing/descriptors');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.emp_account.name', 'Test Account');
    }

    public function test_store_creates_global_default_descriptor(): void
    {
        $payload = [
            'descriptor_name'    => 'TETHER TEST',
            'descriptor_city'    => 'London',
            'descriptor_country' => 'GBR',
            'is_default'         => true,
        ];

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/admin/billing/descriptors', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.descriptor_name', 'TETHER TEST')
            ->assertJsonPath('data.is_default', true)
            ->assertJsonPath('data.year', null)
            ->assertJsonPath('data.month', null);
    }

    public function test_store_creates_specific_month_descriptor_for_emp_account(): void
    {
        $empAccount = EmpAccount::factory()->create();

        $payload = [
            'descriptor_name'    => 'MAY-2026',
            'year'               => 2026,
            'month'              => 5,
            'is_default'         => false,
            'emp_account_id'     => $empAccount->id,
        ];

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/admin/billing/descriptors', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.descriptor_name', 'MAY-2026')
            ->assertJsonPath('data.year', 2026)
            ->assertJsonPath('data.month', 5)
            ->assertJsonPath('data.emp_account_id', $empAccount->id);
    }

    public function test_store_prevents_duplicate_specific_month_for_same_account(): void
    {
        $empAccount = EmpAccount::factory()->create();
        
        TransactionDescriptor::factory()->create([
            'year' => 2026,
            'month' => 6,
            'is_default' => false,
            'emp_account_id' => $empAccount->id,
        ]);

        $payload = [
            'descriptor_name' => 'DUPLICATE-JUNE',
            'year'            => 2026,
            'month'           => 6,
            'is_default'      => false,
            'emp_account_id'  => $empAccount->id,
        ];

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/admin/billing/descriptors', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('errors.emp_account_id.0', 'A descriptor for 2026-6 already exists for this account.');
    }

    public function test_store_requires_year_and_month_for_non_default(): void
    {
        $payload = [
            'descriptor_name' => 'MISSING-DATE',
            'is_default'      => false,
        ];

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/admin/billing/descriptors', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['year', 'month']);
    }

    public function test_store_validates_descriptor_name_max_length(): void
    {
        $payload = [
            'descriptor_name' => str_repeat('A', 26), // 26 chars, max is 25
            'is_default'      => true,
        ];

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/admin/billing/descriptors', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['descriptor_name']);
    }

    public function test_store_handles_emp_and_global_defaults_independently(): void
    {
        // Create an EMP account default (not global)
        $empAccount = EmpAccount::factory()->create();
        $existingEmpDefault = TransactionDescriptor::factory()->create([
            'is_default' => true,
            'emp_account_id' => $empAccount->id,
        ]);

        // Create a new global default (no conflict with EMP default)
        $payload = [
            'descriptor_name'    => 'TETHER TEST',
            'descriptor_city'    => 'London',
            'descriptor_country' => 'GBR',
            'is_default'         => true,
            'emp_account_id'     => null,
        ];

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/admin/billing/descriptors', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.descriptor_name', 'TETHER TEST')
            ->assertJsonPath('data.is_default', true);

        // The EMP default should remain unchanged
        $this->assertDatabaseHas('transaction_descriptors', [
            'id' => $existingEmpDefault->id,
            'is_default' => true,
        ]);
    }

    public function test_update_modifies_descriptor(): void
    {
        $descriptor = TransactionDescriptor::factory()->create([
            'descriptor_name' => 'ORIGINAL',
            'is_default' => true,
        ]);

        $payload = [
            'descriptor_name' => 'UPDATED',
            'is_default'      => true,
        ];

        $response = $this->withHeaders($this->authHeaders())
            ->putJson("/api/admin/billing/descriptors/{$descriptor->id}", $payload);

        $response->assertStatus(200)
            ->assertJsonPath('data.descriptor_name', 'UPDATED');

        $this->assertDatabaseHas('transaction_descriptors', [
            'id' => $descriptor->id,
            'descriptor_name' => 'UPDATED',
        ]);
    }

    public function test_update_maintains_independent_defaults(): void
    {
        // Create an EMP account
        $empAccount = EmpAccount::factory()->create();
        
        // Create two defaults - one EMP and one global
        $empDefault = TransactionDescriptor::factory()->create([
            'is_default' => true,
            'emp_account_id' => $empAccount->id,
            'descriptor_name' => 'OLD EMP DEFAULT',
        ]);
        
        $globalDefault = TransactionDescriptor::factory()->create([
            'is_default' => true,
            'emp_account_id' => null,
            'descriptor_name' => 'OLD GLOBAL DEFAULT',
        ]);

        // Update the EMP default descriptor
        $payload = [
            'descriptor_name' => 'UPDATED EMP DEFAULT',
            'is_default'      => true,
            'emp_account_id'  => $empAccount->id,
        ];

        $response = $this->withHeaders($this->authHeaders())
            ->putJson("/api/admin/billing/descriptors/{$empDefault->id}", $payload);

        $response->assertStatus(200)
            ->assertJsonPath('data.descriptor_name', 'UPDATED EMP DEFAULT');

        // The global default should remain unchanged
        $this->assertDatabaseHas('transaction_descriptors', [
            'id' => $globalDefault->id,
            'is_default' => true,
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

    public function test_destroy_allows_deleting_default_descriptor(): void
    {
        $descriptor = TransactionDescriptor::factory()->create(['is_default' => true]);

        $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/admin/billing/descriptors/{$descriptor->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('transaction_descriptors', ['id' => $descriptor->id]);
    }
}
