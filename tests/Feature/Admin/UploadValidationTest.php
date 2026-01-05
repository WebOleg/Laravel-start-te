<?php

/**
 * Feature tests for Upload validation endpoints.
 */

namespace Tests\Feature\Admin;

use App\Models\Debtor;
use App\Models\Upload;
use App\Models\User;
use App\Jobs\ProcessValidationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UploadValidationTest extends TestCase
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

    public function test_debtors_endpoint_returns_upload_debtors(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->count(3)->create(['upload_id' => $upload->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/debtors');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'validation_status',
                        'validation_errors',
                    ]
                ],
            ]);
    }

    public function test_debtors_endpoint_filters_by_validation_status(): void
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
            ->getJson('/api/admin/uploads/' . $upload->id . '/debtors?validation_status=invalid');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.validation_status', 'invalid');
    }

    public function test_debtors_endpoint_supports_search(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'Hans',
            'last_name' => 'Mueller',
            'email' => 'hans.mueller@example.com',
            'iban' => 'DE89370400440532013000',
        ]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'Peter',
            'last_name' => 'Schmidt',
            'email' => 'peter.schmidt@example.com',
            'iban' => 'DE89370400440532013001',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/debtors?search=Hans');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Hans');
    }

    public function test_validate_endpoint_dispatches_validation_job(): void
    {
        Queue::fake();

        $upload = Upload::factory()->create(['status' => Upload::STATUS_COMPLETED]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => 'DE89370400440532013000',
            'first_name' => 'Hans',
            'last_name' => 'Mueller',
            'amount' => 100,
            'validation_status' => Debtor::VALIDATION_PENDING,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads/' . $upload->id . '/validate');

        $response->assertStatus(202)
            ->assertJsonPath('message', 'Validation started')
            ->assertJsonPath('status', 'processing');

        Queue::assertPushed(ProcessValidationJob::class);
    }

    public function test_validate_endpoint_validates_all_debtors(): void
    {
        // Don't fake queue - execute jobs synchronously
        $upload = Upload::factory()->create(['status' => Upload::STATUS_COMPLETED]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => 'DE89370400440532013000',
            'first_name' => 'Hans',
            'last_name' => 'Mueller',
            'amount' => 100,
            'validation_status' => Debtor::VALIDATION_PENDING,
        ]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => '',
            'first_name' => 'Peter',
            'last_name' => 'Schmidt',
            'amount' => 100,
            'validation_status' => Debtor::VALIDATION_PENDING,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads/' . $upload->id . '/validate');

        $response->assertStatus(202);

        // Check debtors were validated (job runs synchronously in tests)
        $this->assertDatabaseHas('debtors', [
            'upload_id' => $upload->id,
            'first_name' => 'Hans',
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);
        $this->assertDatabaseHas('debtors', [
            'upload_id' => $upload->id,
            'first_name' => 'Peter',
            'validation_status' => Debtor::VALIDATION_INVALID,
        ]);
    }

    public function test_validate_endpoint_rejects_processing_upload(): void
    {
        $upload = Upload::factory()->create(['status' => Upload::STATUS_PROCESSING]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads/' . $upload->id . '/validate');

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Upload is still processing. Please wait.');
    }

    public function test_validation_stats_returns_correct_counts(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'status' => Debtor::STATUS_PENDING,
        ]);
        Debtor::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_INVALID,
        ]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_PENDING,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/validation-stats');

        $response->assertStatus(200)
            ->assertJsonPath('data.total', 6)
            ->assertJsonPath('data.valid', 3)
            ->assertJsonPath('data.invalid', 2)
            ->assertJsonPath('data.pending', 1)
            ->assertJsonPath('data.ready_for_sync', 3);
    }

    public function test_validation_stats_requires_authentication(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->getJson('/api/admin/uploads/' . $upload->id . '/validation-stats');

        $response->assertStatus(401);
    }
}
