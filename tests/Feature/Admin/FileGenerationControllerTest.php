<?php

/**
 * Feature tests for Admin FileGeneration API endpoints.
 */

namespace Tests\Feature\Admin;

use App\Jobs\ProcessFileGenerationJob;
use App\Models\FileGenerationBatch;
use App\Models\User;
use App\Services\FileClearanceService;
use App\Services\FileGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FileGenerationControllerTest extends TestCase
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

    /**
     * Helper to create a FileGenerationBatch record manually (no factory).
     */
    private function createBatch(array $overrides = []): FileGenerationBatch
    {
        return FileGenerationBatch::create(array_merge([
            'token' => Str::uuid()->toString(),
            'admin_id' => $this->user->id,
            'source_file' => 'test.csv',
            's3_path_source' => 'uploads/test.csv',
            'status' => 'completed',
            'target_amount' => 5000,
            'tolerance' => 500,
            'pricing_strategy' => 'default',
            'total_input_rows' => 200,
        ], $overrides));
    }

    // ──────────────────────────────────────────────
    // store() — Validation
    // ──────────────────────────────────────────────

    public function test_store_requires_authentication(): void
    {
        $response = $this->postJson('/api/admin/file-generation');

        $response->assertStatus(401);
    }

    public function test_store_requires_file(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'target_amount' => 5000,
            ]);

        $response->assertStatus(422);
    }

    public function test_store_requires_target_amount(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
            ]);

        $response->assertStatus(422);
    }

    public function test_store_rejects_target_amount_below_minimum(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'target_amount' => 0,
            ]);

        $response->assertStatus(422);
    }

    public function test_store_rejects_target_amount_above_maximum(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'target_amount' => 1000001,
            ]);

        $response->assertStatus(422);
    }

    public function test_store_rejects_invalid_file_type(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'target_amount' => 5000,
            ]);

        $response->assertStatus(422);
    }

    public function test_store_rejects_file_exceeding_max_size(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->create('test.csv', 51201, 'text/csv');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'target_amount' => 5000,
            ]);

        $response->assertStatus(422);
    }

    public function test_store_rejects_tolerance_below_zero(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'target_amount' => 5000,
                'tolerance' => -1,
            ]);

        $response->assertStatus(422);
    }

    public function test_store_rejects_tolerance_above_maximum(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'target_amount' => 5000,
                'tolerance' => 10001,
            ]);

        $response->assertStatus(422);
    }

    public function test_store_rejects_empty_custom_amounts_array(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'target_amount' => 5000,
                'custom_amounts' => [],
            ]);

        $response->assertStatus(422);
    }

    public function test_store_rejects_custom_amounts_with_invalid_values(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'target_amount' => 5000,
                'custom_amounts' => [0.001],
            ]);

        $response->assertStatus(422);
    }

    public function test_store_rejects_custom_amounts_exceeding_max(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'target_amount' => 5000,
                'custom_amounts' => [1000.00],
            ]);

        $response->assertStatus(422);
    }

    // ──────────────────────────────────────────────
    // store() — Successful Upload & Job Dispatch
    // ──────────────────────────────────────────────

    public function test_store_accepts_valid_csv_and_dispatches_job(): void
    {
        Bus::fake();
        Storage::fake('s3');

        $this->mock(FileClearanceService::class, function ($mock) {
            $mock->shouldReceive('validateAndStore')->once()->andReturn([
                's3_path' => 'uploads/test-file.csv',
                'headers' => ['first_name', 'last_name', 'iban'],
                'header_meta' => ['col_count' => 3],
                'total_rows' => 150,
            ]);
        });

        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'target_amount' => 5000,
            ]);

        $response->assertStatus(202)
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'status',
                    'original_file',
                    'total_rows',
                    'target_amount',
                    'tolerance',
                    'pricing_strategy',
                    'pricing_amounts',
                    'headers',
                    'message',
                ],
            ])
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.total_rows', 150)
            ->assertJsonPath('data.target_amount', 5000);

        Bus::assertDispatched(ProcessFileGenerationJob::class);
    }

    public function test_store_creates_batch_record(): void
    {
        Bus::fake();
        Storage::fake('s3');

        $this->mock(FileClearanceService::class, function ($mock) {
            $mock->shouldReceive('validateAndStore')->once()->andReturn([
                's3_path' => 'uploads/batch-test.csv',
                'headers' => ['first_name', 'last_name', 'iban'],
                'header_meta' => ['col_count' => 3],
                'total_rows' => 200,
            ]);
        });

        $file = UploadedFile::fake()->create('batch-test.csv', 100, 'text/csv');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'target_amount' => 10000,
                'tolerance' => 250,
            ]);

        $response->assertStatus(202);

        $batchToken = $response->json('data.token');

        $this->assertDatabaseHas('file_generation_batches', [
            'token' => $batchToken,
            'status' => 'queued',
            'target_amount' => 10000,
            'tolerance' => 250,
            'total_input_rows' => 200,
            'source_file' => 'batch-test.csv',
        ]);
    }

    public function test_store_sets_cache_entry(): void
    {
        Bus::fake();
        Storage::fake('s3');

        $this->mock(FileClearanceService::class, function ($mock) {
            $mock->shouldReceive('validateAndStore')->once()->andReturn([
                's3_path' => 'uploads/cache-test.csv',
                'headers' => ['first_name', 'last_name', 'iban'],
                'header_meta' => ['col_count' => 3],
                'total_rows' => 50,
            ]);
        });

        $file = UploadedFile::fake()->create('cache-test.csv', 100, 'text/csv');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'target_amount' => 3000,
            ]);

        $response->assertStatus(202);

        $batchToken = $response->json('data.token');
        $cached = Cache::get("file_generation:{$batchToken}");

        $this->assertNotNull($cached);
        $this->assertEquals('queued', $cached['status']);
        $this->assertEquals(50, $cached['total_rows']);
        $this->assertEquals(3000, $cached['target_amount']);
    }

    public function test_store_uses_default_tolerance_when_not_provided(): void
    {
        Bus::fake();
        Storage::fake('s3');

        $this->mock(FileClearanceService::class, function ($mock) {
            $mock->shouldReceive('validateAndStore')->once()->andReturn([
                's3_path' => 'uploads/default-tol.csv',
                'headers' => ['first_name', 'last_name', 'iban'],
                'header_meta' => ['col_count' => 3],
                'total_rows' => 100,
            ]);
        });

        $file = UploadedFile::fake()->create('default-tol.csv', 100, 'text/csv');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'target_amount' => 5000,
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.tolerance', 500);
    }

    public function test_store_uses_default_strategy_when_not_provided(): void
    {
        Bus::fake();
        Storage::fake('s3');

        $this->mock(FileClearanceService::class, function ($mock) {
            $mock->shouldReceive('validateAndStore')->once()->andReturn([
                's3_path' => 'uploads/default-strategy.csv',
                'headers' => ['first_name', 'last_name', 'iban'],
                'header_meta' => ['col_count' => 3],
                'total_rows' => 100,
            ]);
        });

        $file = UploadedFile::fake()->create('default-strategy.csv', 100, 'text/csv');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'target_amount' => 5000,
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.pricing_strategy', FileGenerationService::DEFAULT_STRATEGY);
    }

    public function test_store_accepts_xlsx_file(): void
    {
        Bus::fake();
        Storage::fake('s3');

        $this->mock(FileClearanceService::class, function ($mock) {
            $mock->shouldReceive('validateAndStore')->once()->andReturn([
                's3_path' => 'uploads/test-file.xlsx',
                'headers' => ['first_name', 'last_name', 'iban'],
                'header_meta' => ['col_count' => 3],
                'total_rows' => 75,
            ]);
        });

        $file = UploadedFile::fake()->create('test-file.xlsx', 100, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'target_amount' => 8000,
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.total_rows', 75);
    }

    public function test_store_accepts_valid_custom_amounts(): void
    {
        Bus::fake();
        Storage::fake('s3');

        $this->mock(FileClearanceService::class, function ($mock) {
            $mock->shouldReceive('validateAndStore')->once()->andReturn([
                's3_path' => 'uploads/custom-amounts.csv',
                'headers' => ['first_name', 'last_name', 'iban'],
                'header_meta' => ['col_count' => 3],
                'total_rows' => 100,
            ]);
        });

        $file = UploadedFile::fake()->create('custom-amounts.csv', 100, 'text/csv');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'target_amount' => 5000,
                'custom_amounts' => [9.99, 19.99, 49.99],
            ]);

        $response->assertStatus(202);
    }

    // ──────────────────────────────────────────────
    // store() — Error Handling
    // ──────────────────────────────────────────────

    public function test_store_returns_422_when_clearance_validation_fails(): void
    {
        Storage::fake('s3');

        $this->mock(FileClearanceService::class, function ($mock) {
            $mock->shouldReceive('validateAndStore')
                ->once()
                ->andThrow(new \InvalidArgumentException('Invalid CSV headers'));
        });

        $file = UploadedFile::fake()->create('bad-headers.csv', 100, 'text/csv');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'target_amount' => 5000,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Invalid CSV headers');
    }

    public function test_store_returns_500_on_unexpected_error(): void
    {
        Storage::fake('s3');

        $this->mock(FileClearanceService::class, function ($mock) {
            $mock->shouldReceive('validateAndStore')
                ->once()
                ->andThrow(new \RuntimeException('S3 connection failed'));
        });

        $file = UploadedFile::fake()->create('error-test.csv', 100, 'text/csv');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'target_amount' => 5000,
            ]);

        $response->assertStatus(500)
            ->assertJsonStructure(['message', 'error']);
    }

    // ──────────────────────────────────────────────
    // status()
    // ──────────────────────────────────────────────

    public function test_status_returns_queued_state(): void
    {
        $genToken = Str::uuid()->toString();

        Cache::put("file_generation:{$genToken}", [
            'status' => 'queued',
            'phase' => 'queued',
            'total_rows' => 200,
            'processed' => 0,
            'target_amount' => 5000,
            'tolerance' => 500,
            'pricing_strategy' => 'default',
            'original_file' => 'test.csv',
        ], 7200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.phase', 'queued')
            ->assertJsonPath('data.total_rows', 200)
            ->assertJsonPath('data.processed', 0)
            ->assertJsonPath('data.has_download', false);

        $this->assertEquals(0, $response->json('data.progress'));
    }

    public function test_status_returns_progress_during_processing(): void
    {
        $genToken = Str::uuid()->toString();

        Cache::put("file_generation:{$genToken}", [
            'status' => 'processing',
            'phase' => 'filtering',
            'total_rows' => 200,
            'processed' => 100,
            'target_amount' => 5000,
            'tolerance' => 500,
            'eligible_rows' => 80,
            'selected_rows' => 0,
            'achieved_amount' => 0,
            'pricing_strategy' => 'default',
            'original_file' => 'test.csv',
        ], 7200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.phase', 'filtering')
            ->assertJsonPath('data.eligible_rows', 80);

        $this->assertEquals(50, $response->json('data.progress'));
    }

    public function test_status_returns_completed_state_with_download(): void
    {
        $genToken = Str::uuid()->toString();

        Cache::put("file_generation:{$genToken}", [
            'status' => 'completed',
            'phase' => 'completed',
            'total_rows' => 200,
            'processed' => 200,
            'target_amount' => 5000,
            'tolerance' => 500,
            'eligible_rows' => 150,
            'selected_rows' => 120,
            'achieved_amount' => 4850,
            'pricing_strategy' => 'default',
            'original_file' => 'test.csv',
            's3_path_result' => 'results/generated-output.csv',
            'completed_at' => now()->toISOString(),
        ], 7200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.selected_rows', 120)
            ->assertJsonPath('data.achieved_amount', 4850)
            ->assertJsonPath('data.has_download', true);

        $this->assertEquals(100, $response->json('data.progress'));
    }

    public function test_status_returns_404_for_expired_token(): void
    {
        $genToken = Str::uuid()->toString();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/status");

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Token expired or not found.');
    }

    public function test_status_returns_error_information(): void
    {
        $genToken = Str::uuid()->toString();

        Cache::put("file_generation:{$genToken}", [
            'status' => 'failed',
            'phase' => 'error',
            'total_rows' => 200,
            'processed' => 50,
            'target_amount' => 5000,
            'error' => 'Insufficient eligible rows to reach target amount.',
            'original_file' => 'test.csv',
        ], 7200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', 'Insufficient eligible rows to reach target amount.');
    }

    public function test_status_returns_warning_information(): void
    {
        $genToken = Str::uuid()->toString();

        Cache::put("file_generation:{$genToken}", [
            'status' => 'completed',
            'phase' => 'completed',
            'total_rows' => 200,
            'processed' => 200,
            'target_amount' => 5000,
            'tolerance' => 500,
            'achieved_amount' => 4600,
            'warning' => 'Achieved amount is within tolerance but below target.',
            's3_path_result' => 'results/output.csv',
            'original_file' => 'test.csv',
        ], 7200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.warning', 'Achieved amount is within tolerance but below target.');
    }

    public function test_status_returns_exclusion_counts(): void
    {
        $genToken = Str::uuid()->toString();

        Cache::put("file_generation:{$genToken}", [
            'status' => 'completed',
            'phase' => 'completed',
            'total_rows' => 500,
            'processed' => 500,
            'target_amount' => 10000,
            'tolerance' => 500,
            'excluded_blacklist_rows' => 20,
            'excluded_billing_rows' => 15,
            'excluded_previously_used_rows' => 30,
            'eligible_rows' => 435,
            'selected_rows' => 300,
            'achieved_amount' => 9800,
            's3_path_result' => 'results/output.csv',
            'original_file' => 'test.csv',
        ], 7200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.excluded_blacklist_rows', 20)
            ->assertJsonPath('data.excluded_billing_rows', 15)
            ->assertJsonPath('data.excluded_previously_used_rows', 30);
    }

    public function test_status_response_structure(): void
    {
        $genToken = Str::uuid()->toString();

        Cache::put("file_generation:{$genToken}", [
            'status' => 'queued',
            'phase' => 'queued',
            'total_rows' => 100,
            'processed' => 0,
            'target_amount' => 5000,
            'tolerance' => 500,
            'original_file' => 'test.csv',
        ], 7200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/status");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'status',
                    'phase',
                    'total_rows',
                    'processed',
                    'progress',
                    'eligible_rows',
                    'selected_rows',
                    'achieved_amount',
                    'target_amount',
                    'tolerance',
                    'pricing_strategy',
                    'excluded_blacklist_rows',
                    'excluded_billing_rows',
                    'excluded_previously_used_rows',
                    'error',
                    'warning',
                    'original_file',
                    'completed_at',
                    'has_download',
                ],
            ]);
    }

    // ──────────────────────────────────────────────
    // download()
    // ──────────────────────────────────────────────

    public function test_download_returns_404_for_nonexistent_token(): void
    {
        $genToken = Str::uuid()->toString();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/download");

        $response->assertStatus(404);
    }

    public function test_download_returns_404_when_not_completed(): void
    {
        $genToken = Str::uuid()->toString();

        Cache::put("file_generation:{$genToken}", [
            'status' => 'processing',
            'phase' => 'filtering',
            'total_rows' => 200,
            'processed' => 100,
        ], 7200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/download");

        $response->assertStatus(404);
    }

    public function test_download_returns_404_when_no_output_file(): void
    {
        $genToken = Str::uuid()->toString();

        Cache::put("file_generation:{$genToken}", [
            'status' => 'completed',
            'phase' => 'completed',
            'total_rows' => 200,
            'processed' => 200,
        ], 7200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/download");

        $response->assertStatus(404);
    }

    public function test_download_streams_file_from_cache_when_completed(): void
    {
        $genToken = Str::uuid()->toString();

        Cache::put("file_generation:{$genToken}", [
            'status' => 'completed',
            'phase' => 'completed',
            'total_rows' => 200,
            'processed' => 200,
            's3_path_result' => 'results/generated-output.csv',
            'file_name' => 'generated-output.csv',
        ], 7200);

        $this->mock(FileClearanceService::class, function ($mock) {
            $mock->shouldReceive('streamDownloadFromS3')
                ->with('results/generated-output.csv', 'generated-output.csv')
                ->once()
                ->andReturn(response()->streamDownload(function () {
                    echo "first_name,last_name,iban\nJohn,Doe,DE123\n";
                }, 'generated-output.csv', ['Content-Type' => 'text/csv']));
        });

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/download");

        $response->assertStatus(200);
    }

    public function test_download_falls_back_to_batch_record_when_cache_expired(): void
    {
        $genToken = Str::uuid()->toString();

        $this->createBatch([
            'token' => $genToken,
            's3_path_result' => 'results/fallback-output.csv',
            'status' => 'completed',
        ]);

        $this->mock(FileClearanceService::class, function ($mock) {
            $mock->shouldReceive('streamDownloadFromS3')
                ->once()
                ->andReturn(response()->streamDownload(function () {
                    echo "first_name,last_name,iban\nJohn,Doe,DE123\n";
                }, 'fallback-output.csv', ['Content-Type' => 'text/csv']));
        });

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/download");

        $response->assertStatus(200);
    }

    public function test_download_returns_404_when_batch_not_completed(): void
    {
        $genToken = Str::uuid()->toString();

        $this->createBatch([
            'token' => $genToken,
            'status' => 'processing',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/download");

        $response->assertStatus(404);
    }

    public function test_download_returns_404_when_s3_file_missing(): void
    {
        $genToken = Str::uuid()->toString();

        Cache::put("file_generation:{$genToken}", [
            'status' => 'completed',
            'phase' => 'completed',
            'total_rows' => 200,
            'processed' => 200,
            's3_path_result' => 'results/missing-file.csv',
            'file_name' => 'missing-file.csv',
        ], 7200);

        $this->mock(FileClearanceService::class, function ($mock) {
            $mock->shouldReceive('streamDownloadFromS3')
                ->once()
                ->andThrow(new \RuntimeException('File not found on S3'));
        });

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/download");

        $response->assertStatus(404)
            ->assertJsonPath('message', 'File not found. Please run generation again.');
    }

    // ──────────────────────────────────────────────
    // history()
    // ──────────────────────────────────────────────

    public function test_history_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/file-generation/history');

        $response->assertStatus(401);
    }

    public function test_history_returns_paginated_batches(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->createBatch();
        }

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/file-generation/history');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
            ]);

        $this->assertCount(5, $response->json('data'));
    }

    public function test_history_respects_per_page_parameter(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->createBatch();
        }

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/file-generation/history?per_page=10');

        $response->assertStatus(200);
        $this->assertCount(10, $response->json('data'));
    }

    public function test_history_default_pagination(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->createBatch();
        }

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/file-generation/history');

        $response->assertStatus(200);
        $this->assertCount(20, $response->json('data'));
    }

    public function test_history_second_page(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->createBatch();
        }

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/file-generation/history?per_page=20&page=2');

        $response->assertStatus(200);
        $this->assertCount(5, $response->json('data'));
    }

    public function test_history_returns_empty_list_when_no_batches(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/file-generation/history');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }
}
