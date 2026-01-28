<?php

namespace Tests\Feature\Admin;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UploadChargebackFilterTest extends TestCase
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

    public function test_filter_chargebacks_removes_chargebacked_debtors(): void
    {
        $upload = Upload::factory()->create();
        
        $debtor1 = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
        ]);
        $debtor2 = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'Jane',
        ]);
        
        // Add chargeback to debtor1
        $debtor1->billingAttempts()->create([
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'amount' => 100,
            'currency' => 'EUR',
            'transaction_id' => 'TXN1',
            'reference' => 'REF1',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads/' . $upload->id . '/filter-chargebacks');

        $response->assertStatus(200)
            ->assertJsonPath('data.removed', 1);

        // Use assertSoftDeleted since Debtor uses SoftDeletes
        $this->assertSoftDeleted('debtors', ['id' => $debtor1->id]);
        $this->assertDatabaseHas('debtors', ['id' => $debtor2->id]);
    }

    public function test_filter_chargebacks_returns_zero_when_no_chargebacks(): void
    {
        $upload = Upload::factory()->create();
        
        Debtor::factory()->count(3)->create(['upload_id' => $upload->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads/' . $upload->id . '/filter-chargebacks');

        $response->assertStatus(200)
            ->assertJsonPath('data.removed', 0)
            ->assertJsonPath('message', 'No chargebacked records found');
    }

    public function test_filter_chargebacks_removes_multiple_chargebacked_debtors(): void
    {
        $upload = Upload::factory()->create();
        
        $debtors = Debtor::factory()->count(5)->create(['upload_id' => $upload->id]);
        
        // Add chargebacks to debtors 0, 2, and 4
        foreach ([0, 2, 4] as $index) {
            $debtors[$index]->billingAttempts()->create([
                'upload_id' => $upload->id,
                'status' => BillingAttempt::STATUS_CHARGEBACKED,
                'amount' => 100,
                'currency' => 'EUR',
                'transaction_id' => 'TXN' . $index,
                'reference' => 'REF' . $index,
            ]);
        }

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads/' . $upload->id . '/filter-chargebacks');

        $response->assertStatus(200)
            ->assertJsonPath('data.removed', 3);

        // Use assertSoftDeleted for soft-deleted records
        $this->assertSoftDeleted('debtors', ['id' => $debtors[0]->id]);
        $this->assertSoftDeleted('debtors', ['id' => $debtors[2]->id]);
        $this->assertSoftDeleted('debtors', ['id' => $debtors[4]->id]);
        
        $this->assertDatabaseHas('debtors', ['id' => $debtors[1]->id]);
        $this->assertDatabaseHas('debtors', ['id' => $debtors[3]->id]);
    }

    public function test_filter_chargebacks_ignores_non_chargebacked_statuses(): void
    {
        $upload = Upload::factory()->create();
        
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        
        // Add approved billing attempt (not chargebacked)
        $debtor->billingAttempts()->create([
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100,
            'currency' => 'EUR',
            'transaction_id' => 'TXN1',
            'reference' => 'REF1',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads/' . $upload->id . '/filter-chargebacks');

        $response->assertStatus(200)
            ->assertJsonPath('data.removed', 0);

        $this->assertDatabaseHas('debtors', ['id' => $debtor->id]);
    }

    public function test_filter_chargebacks_only_removes_from_specified_upload(): void
    {
        $upload1 = Upload::factory()->create();
        $upload2 = Upload::factory()->create();
        
        $debtor1 = Debtor::factory()->create(['upload_id' => $upload1->id]);
        $debtor2 = Debtor::factory()->create(['upload_id' => $upload2->id]);
        
        $debtor1->billingAttempts()->create([
            'upload_id' => $upload1->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'amount' => 100,
            'currency' => 'EUR',
            'transaction_id' => 'TXN1',
            'reference' => 'REF1',
        ]);
        
        $debtor2->billingAttempts()->create([
            'upload_id' => $upload2->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'amount' => 100,
            'currency' => 'EUR',
            'transaction_id' => 'TXN2',
            'reference' => 'REF2',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads/' . $upload1->id . '/filter-chargebacks');

        $response->assertStatus(200)
            ->assertJsonPath('data.removed', 1);

        // Use assertSoftDeleted for soft-deleted records
        $this->assertSoftDeleted('debtors', ['id' => $debtor1->id]);
        $this->assertDatabaseHas('debtors', ['id' => $debtor2->id]);
    }

    public function test_filter_chargebacks_requires_authentication(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->postJson('/api/admin/uploads/' . $upload->id . '/filter-chargebacks');

        $response->assertStatus(401);
    }

    public function test_filter_chargebacks_returns_404_for_nonexistent_upload(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads/99999/filter-chargebacks');

        $response->assertStatus(404);
    }

    public function test_filter_chargebacks_soft_deletes_debtors(): void
    {
        $upload = Upload::factory()->create();
        
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        
        $debtor->billingAttempts()->create([
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'amount' => 100,
            'currency' => 'EUR',
            'transaction_id' => 'TXN1',
            'reference' => 'REF1',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads/' . $upload->id . '/filter-chargebacks');

        $response->assertStatus(200);

        $this->assertSoftDeleted('debtors', ['id' => $debtor->id]);
    }

    public function test_filter_chargebacks_response_structure(): void
    {
        $upload = Upload::factory()->create();
        
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        $debtor->billingAttempts()->create([
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'amount' => 100,
            'currency' => 'EUR',
            'transaction_id' => 'TXN1',
            'reference' => 'REF1',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads/' . $upload->id . '/filter-chargebacks');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => ['removed']
            ]);
    }
}
