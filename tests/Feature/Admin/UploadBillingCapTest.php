<?php

namespace Tests\Feature\Admin;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\Upload;
use App\Models\User;
use App\Models\EmpAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UploadBillingCapTest extends TestCase
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

    public function test_update_settings_sets_max_billing_amount(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->patchJson("/api/admin/uploads/{$upload->id}/settings", [
                'max_billing_amount' => 10.00,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Upload settings updated.');

        $this->assertDatabaseHas('uploads', [
            'id' => $upload->id,
            'max_billing_amount' => 10.00,
        ]);
    }

    public function test_update_settings_clears_max_billing_amount_with_null(): void
    {
        $upload = Upload::factory()->create(['max_billing_amount' => 50.00]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->patchJson("/api/admin/uploads/{$upload->id}/settings", [
                'max_billing_amount' => null,
            ]);

        $response->assertStatus(200);

        $upload->refresh();
        $this->assertNull($upload->max_billing_amount);
    }

    public function test_update_settings_rejects_negative_amount(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->patchJson("/api/admin/uploads/{$upload->id}/settings", [
                'max_billing_amount' => -5.00,
            ]);

        $response->assertStatus(422);
    }

    public function test_update_settings_requires_authentication(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->patchJson("/api/admin/uploads/{$upload->id}/settings", [
            'max_billing_amount' => 10.00,
        ]);

        $response->assertStatus(401);
    }

    public function test_billing_cycles_returns_grouped_data(): void
    {
        $upload = Upload::factory()->create();
        $account = EmpAccount::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'emp_account_id' => $account->id,
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'emp_account_id' => $account->id,
            'attempt_number' => 1,
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 1.99,
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'emp_account_id' => $account->id,
            'attempt_number' => 2,
            'status' => BillingAttempt::STATUS_PENDING,
            'amount' => 1.99,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/billing-cycles");

        $response->assertStatus(200)
            ->assertJsonPath('data.total_cycles', 2)
            ->assertJsonCount(2, 'data.cycles');
    }

    public function test_billing_cycles_returns_empty_for_no_attempts(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/billing-cycles");

        $response->assertStatus(200)
            ->assertJsonPath('data.total_cycles', 0)
            ->assertJsonPath('data.cycles', []);
    }

    public function test_billing_cycles_includes_cap_info(): void
    {
        $upload = Upload::factory()->create(['max_billing_amount' => 5.00]);
        $account = EmpAccount::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'emp_account_id' => $account->id,
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'emp_account_id' => $account->id,
            'attempt_number' => 1,
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 2.00,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/billing-cycles");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals(5.00, (float) $data['max_billing_amount']);
        $this->assertEquals(2.00, (float) $data['total_billed_amount']);
        $this->assertEquals(3.00, (float) $data['cap_remaining']);
    }

    public function test_billing_cycles_cap_remaining_zero_when_exceeded(): void
    {
        $upload = Upload::factory()->create(['max_billing_amount' => 2.00]);
        $account = EmpAccount::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'emp_account_id' => $account->id,
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'emp_account_id' => $account->id,
            'attempt_number' => 1,
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 3.00,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/billing-cycles");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals(0, (float) $data['cap_remaining']);
    }

    public function test_billing_cycles_no_cap_returns_null(): void
    {
        $upload = Upload::factory()->create(['max_billing_amount' => null]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/billing-cycles");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertNull($data['max_billing_amount']);
        $this->assertNull($data['cap_remaining']);
    }

    public function test_update_settings_rejects_too_large_amount(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->patchJson("/api/admin/uploads/{$upload->id}/settings", [
                'max_billing_amount' => 9999999.99,
            ]);

        $response->assertStatus(422);
    }
}
