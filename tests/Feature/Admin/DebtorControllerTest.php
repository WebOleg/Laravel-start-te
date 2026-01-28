<?php

/**
 * Feature tests for Admin Debtor API endpoints.
 */

namespace Tests\Feature\Admin;

use App\Models\Debtor;
use App\Models\DebtorProfile;
use App\Models\Upload;
use App\Models\User;
use App\Models\Blacklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebtorControllerTest extends TestCase
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

    public function test_index_returns_debtors_list(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->count(3)->create(['upload_id' => $upload->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/debtors');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'upload_id',
                        'iban_masked',
                        'first_name',
                        'last_name',
                        'full_name',
                        'amount',
                        'status',
                        'validation_status',
                        'validation_errors',
                    ]
                ],
                'meta' => ['current_page', 'total'],
            ]);
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/debtors');

        $response->assertStatus(401);
    }

    public function test_index_filters_by_status(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->create(['upload_id' => $upload->id, 'status' => Debtor::STATUS_PENDING]);
        Debtor::factory()->create(['upload_id' => $upload->id, 'status' => Debtor::STATUS_RECOVERED]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/debtors?status=pending');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('pending', $data[0]['status']);
    }

    public function test_index_filters_by_validation_status(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_INVALID,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/debtors?validation_status=valid');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('valid', $data[0]['validation_status']);
    }

    public function test_index_filters_by_country(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->create(['upload_id' => $upload->id, 'country' => 'DE']);
        Debtor::factory()->create(['upload_id' => $upload->id, 'country' => 'AT']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/debtors?country=DE');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('DE', $data[0]['country']);
    }

    public function test_index_filters_by_upload_id(): void
    {
        $upload1 = Upload::factory()->create();
        $upload2 = Upload::factory()->create();
        Debtor::factory()->count(2)->create(['upload_id' => $upload1->id]);
        Debtor::factory()->count(3)->create(['upload_id' => $upload2->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/debtors?upload_id=' . $upload1->id);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(2, $data);
    }

    public function test_show_returns_single_debtor(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'Hans',
            'last_name' => 'Mueller',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/debtors/' . $debtor->id);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $debtor->id)
            ->assertJsonPath('data.first_name', 'Hans')
            ->assertJsonPath('data.full_name', 'Hans Mueller');
    }

    public function test_show_returns_masked_iban(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => 'DE89370400440532013000',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/debtors/' . $debtor->id);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertStringStartsWith('DE89', $data['iban_masked']);
        $this->assertStringEndsWith('3000', $data['iban_masked']);
        $this->assertStringContainsString('****', $data['iban_masked']);
    }

    public function test_show_returns_404_for_nonexistent_debtor(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/debtors/99999');

        $response->assertStatus(404);
    }

    public function test_update_modifies_debtor_via_raw_data(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'Hans',
            'last_name' => 'Mueller',
            'iban' => 'DE89370400440532013000',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson('/api/admin/debtors/' . $debtor->id, [
                'raw_data' => [
                    'first_name' => 'Johann',
                    'last_name' => 'Mueller',
                    'email' => 'johann@example.com',
                    'iban' => 'DE89370400440532013000',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.first_name', 'Johann')
            ->assertJsonPath('data.email', 'johann@example.com')
            ->assertJsonPath('data.last_name', 'Mueller');
    }

    public function test_update_revalidates_debtor(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => 'DE89370400440532013000',
            'first_name' => 'Hans',
            'last_name' => 'Mueller',
            'amount' => 100,
            'city' => 'Berlin',
            'postcode' => '10115',
            'address' => 'Main St 1',
            'validation_status' => Debtor::VALIDATION_PENDING,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson('/api/admin/debtors/' . $debtor->id, [
                'raw_data' => [
                    'first_name' => 'Hans',
                    'last_name' => 'Mueller',
                    'iban' => 'DE89370400440532013000',
                    'amount' => '200',
                    'city' => 'Berlin',
                    'postcode' => '10115',
                    'address' => 'Main St 1',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.amount', 200)
            ->assertJsonPath('data.validation_status', 'valid');
    }

    public function test_update_saves_raw_data(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        $rawData = [
            'first_name' => 'Hans',
            'custom_field' => 'custom_value',
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson('/api/admin/debtors/' . $debtor->id, [
                'raw_data' => $rawData,
            ]);

        $response->assertStatus(200);

        $debtor->refresh();
        $this->assertEquals('custom_value', $debtor->raw_data['custom_field']);
    }

    public function test_validate_endpoint_validates_debtor(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => 'DE89370400440532013000',
            'first_name' => 'Hans',
            'last_name' => 'Mueller',
            'amount' => 100,
            'city' => 'Berlin',
            'postcode' => '10115',
            'address' => 'Main St 1',
            'validation_status' => Debtor::VALIDATION_PENDING,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/debtors/' . $debtor->id . '/validate');

        $response->assertStatus(200)
            ->assertJsonPath('data.validation_status', 'valid')
            ->assertJsonPath('data.validation_errors', null);
    }

    public function test_validate_returns_errors_for_invalid_debtor(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => '',
            'first_name' => '',
            'last_name' => '',
            'amount' => 100,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/debtors/' . $debtor->id . '/validate');

        $response->assertStatus(200)
            ->assertJsonPath('data.validation_status', 'invalid');

        $errors = $response->json('data.validation_errors');
        $this->assertContains('IBAN is required', $errors);
        $this->assertContains('Name is required', $errors);
    }

    public function test_validate_detects_blacklisted_iban(): void
    {
        $upload = Upload::factory()->create();
        $iban = 'DE89370400440532013000';

        Blacklist::create([
            'iban' => $iban,
            'iban_hash' => hash('sha256', $iban),
            'reason' => 'Fraud',
        ]);

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => $iban,
            'first_name' => 'Hans',
            'last_name' => 'Mueller',
            'amount' => 100,
            'city' => 'Berlin',
            'postcode' => '10115',
            'address' => 'Main St 1',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/debtors/' . $debtor->id . '/validate');

        $response->assertStatus(200)
            ->assertJsonPath('data.validation_status', 'invalid');

        $errors = $response->json('data.validation_errors');
        $this->assertContains('IBAN is blacklisted', $errors);
    }

    public function test_destroy_deletes_debtor(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson('/api/admin/debtors/' . $debtor->id);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Debtor deleted successfully');

        $this->assertSoftDeleted('debtors', ['id' => $debtor->id]);
    }

    public function test_destroy_returns_404_for_nonexistent_debtor(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson('/api/admin/debtors/99999');

        $response->assertStatus(404);
    }

    public function test_index_filters_by_legacy_model(): void
    {
        $upload = Upload::factory()->create();

        // 1. Pure Legacy (No Profile) - Should match
        $legacy1 = Debtor::factory()->create(['upload_id' => $upload->id]);

        // 2. Explicit Legacy Profile - Should match
        $legacyProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_LEGACY]);
        $legacy2 = Debtor::factory()->create(['upload_id' => $upload->id, 'debtor_profile_id' => $legacyProfile->id]);

        // 3. Flywheel Profile - Should NOT match
        $flywheelProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_FLYWHEEL]);
        $flywheel = Debtor::factory()->create(['upload_id' => $upload->id, 'debtor_profile_id' => $flywheelProfile->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/debtors?model=legacy');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $legacy1->id])
            ->assertJsonFragment(['id' => $legacy2->id])
            ->assertJsonMissing(['id' => $flywheel->id]);
    }

    public function test_index_filters_by_flywheel_model(): void
    {
        $upload = Upload::factory()->create();

        // 1. Flywheel Profile - Should Match
        $profile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_FLYWHEEL]);
        $match = Debtor::factory()->create(['upload_id' => $upload->id, 'debtor_profile_id' => $profile->id]);

        // 2. Recovery Profile - Should NOT Match
        $otherProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_RECOVERY]);
        $noMatch = Debtor::factory()->create(['upload_id' => $upload->id, 'debtor_profile_id' => $otherProfile->id]);

        // 3. Legacy - Should NOT Match
        $legacy = Debtor::factory()->create(['upload_id' => $upload->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/debtors?model=flywheel');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['id' => $match->id])
            ->assertJsonMissing(['id' => $noMatch->id])
            ->assertJsonMissing(['id' => $legacy->id]);
    }

    public function test_update_creates_profile_when_switching_to_flywheel(): void
    {
        // Debtor starts as Legacy (No Profile)
        $debtor = Debtor::factory()->create([
            'iban' => 'DE123456789',
            'iban_hash' => 'hash_DE123456789',
            'debtor_profile_id' => null
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/admin/debtors/{$debtor->id}", [
                'model' => DebtorProfile::MODEL_FLYWHEEL
            ]);

        $response->assertStatus(200);

        // Verify Profile Created
        $debtor->refresh();
        $this->assertNotNull($debtor->debtorProfile);
        $this->assertEquals(DebtorProfile::MODEL_FLYWHEEL, $debtor->debtorProfile->billing_model);
        $this->assertEquals($debtor->iban_hash, $debtor->debtorProfile->iban_hash);
    }

    public function test_update_deletes_profile_when_switching_to_legacy(): void
    {
        // Debtor starts with Flywheel Profile
        $profile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_FLYWHEEL]);
        $debtor = Debtor::factory()->create(['debtor_profile_id' => $profile->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/admin/debtors/{$debtor->id}", [
                'model' => DebtorProfile::MODEL_LEGACY
            ]);

        $response->assertStatus(200);

        // Verify Profile Deleted and Dissociated
        $debtor->refresh();
        $this->assertNull($debtor->debtor_profile_id);
        $this->assertDatabaseMissing('debtor_profiles', ['id' => $profile->id]);
    }

    public function test_update_preserves_cycle_date_when_switching_models(): void
    {
        // User paid 30 days ago
        $lastSuccess = now()->subDays(30);

        // Current: Flywheel (90 day cycle) -> Next bill was ~60 days from now
        $profile = DebtorProfile::factory()->create([
            'billing_model' => DebtorProfile::MODEL_FLYWHEEL,
            'last_success_at' => $lastSuccess,
            'next_bill_at' => $lastSuccess->copy()->addDays(90)
        ]);

        $debtor = Debtor::factory()->create(['debtor_profile_id' => $profile->id]);

        // Switch to Recovery (6 month cycle)
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/admin/debtors/{$debtor->id}", [
                'model' => DebtorProfile::MODEL_RECOVERY
            ]);

        $response->assertStatus(200);

        $debtor->refresh();
        $this->assertEquals(DebtorProfile::MODEL_RECOVERY, $debtor->debtorProfile->billing_model);

        // Assert date was recalculated: Last Success + 6 Months
        $expectedDate = $lastSuccess->copy()->addMonths(6);

        // Check matching Y-m-d H:i (ignore seconds)
        $this->assertEquals(
            $expectedDate->format('Y-m-d H:i'),
            $debtor->debtorProfile->next_bill_at->format('Y-m-d H:i')
        );
    }

    public function test_update_sets_immediate_billing_if_cycle_passed(): void
    {
        // User paid 1 year ago (cycle definitely passed)
        $lastSuccess = now()->subYear();

        $profile = DebtorProfile::factory()->create([
            'billing_model' => DebtorProfile::MODEL_FLYWHEEL,
            'last_success_at' => $lastSuccess,
            'next_bill_at' => now()->subMonths(9) // Was due long ago
        ]);

        $debtor = Debtor::factory()->create(['debtor_profile_id' => $profile->id]);

        // Update Model
        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/admin/debtors/{$debtor->id}", [
                'model' => DebtorProfile::MODEL_RECOVERY
            ]);

        $debtor->refresh();

        // Should be NULL (Bill Immediately), because calculated date (Last Success + 6mo) is in the past
        $this->assertNull($debtor->debtorProfile->next_bill_at);
    }

    public function test_index_search_finds_by_debtor_name(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'Markus',
            'last_name' => 'Weber',
        ]);
        Debtor::factory()->create(['upload_id' => $upload->id]); // Random other

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/debtors?search=Markus');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('Markus', $data[0]['first_name']);
    }

    public function test_index_search_finds_by_email(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'email' => 'testuser@example.com',
        ]);
        Debtor::factory()->create(['upload_id' => $upload->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/debtors?search=testuser@example.com');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('testuser@example.com', $data[0]['email']);
    }

    public function test_index_search_finds_by_iban(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => 'AT611904300234573201',
        ]);
        Debtor::factory()->create(['upload_id' => $upload->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/debtors?search=AT611904300234573201');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
    }

    public function test_index_pagination(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->count(75)->create(['upload_id' => $upload->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/debtors?per_page=50');

        $response->assertStatus(200)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.total', 75);

        $data = $response->json('data');
        $this->assertCount(50, $data);
    }

    public function test_index_pagination_second_page(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->count(75)->create(['upload_id' => $upload->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/debtors?per_page=50&page=2');

        $response->assertStatus(200)
            ->assertJsonPath('meta.current_page', 2);

        $data = $response->json('data');
        $this->assertCount(25, $data);
    }

    public function test_index_filters_by_risk_class(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->create(['upload_id' => $upload->id, 'risk_class' => 'high']);
        Debtor::factory()->create(['upload_id' => $upload->id, 'risk_class' => 'low']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/debtors?risk_class=high');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('high', $data[0]['risk_class']);
    }

    public function test_index_filters_by_combined_status_and_country(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'status' => Debtor::STATUS_PENDING,
            'country' => 'DE',
        ]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'status' => Debtor::STATUS_RECOVERED,
            'country' => 'DE',
        ]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'status' => Debtor::STATUS_PENDING,
            'country' => 'AT',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/debtors?status=pending&country=DE');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('DE', $data[0]['country']);
        $this->assertEquals('pending', $data[0]['status']);
    }

    public function test_update_requires_authentication(): void
    {
        $debtor = Debtor::factory()->create();

        $response = $this->putJson('/api/admin/debtors/' . $debtor->id, [
            'raw_data' => ['first_name' => 'Updated'],
        ]);

        $response->assertStatus(401);
    }

    public function test_validate_requires_authentication(): void
    {
        $debtor = Debtor::factory()->create();

        $response = $this->postJson('/api/admin/debtors/' . $debtor->id . '/validate');

        $response->assertStatus(401);
    }

    public function test_destroy_requires_authentication(): void
    {
        $debtor = Debtor::factory()->create();

        $response = $this->deleteJson('/api/admin/debtors/' . $debtor->id);

        $response->assertStatus(401);
    }

    public function test_update_modifies_email(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'email' => 'old@example.com',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson('/api/admin/debtors/' . $debtor->id, [
                'email' => 'new@example.com',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.email', 'new@example.com');
    }

    public function test_update_modifies_status(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'status' => Debtor::STATUS_PENDING,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson('/api/admin/debtors/' . $debtor->id, [
                'status' => Debtor::STATUS_RECOVERED,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'recovered');
    }

    public function test_update_modifies_risk_class(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'risk_class' => 'low',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson('/api/admin/debtors/' . $debtor->id, [
                'risk_class' => 'high',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.risk_class', 'high');
    }
}
