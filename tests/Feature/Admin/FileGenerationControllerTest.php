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

    private function mockClearanceService(int $totalRows = 150, string $s3Path = 'uploads/test-file.csv'): void
    {
        $this->mock(FileClearanceService::class, function ($mock) use ($totalRows, $s3Path) {
            $mock->shouldReceive('validateAndStore')->once()->andReturn([
                's3_path' => $s3Path,
                'headers' => ['first_name', 'last_name', 'iban'],
                'header_meta' => ['col_count' => 3],
                'total_rows' => $totalRows,
            ]);
        });
    }

    // ══════════════════════════════════════════════
    // store() — Validation
    // ══════════════════════════════════════════════

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/admin/file-generation')->assertStatus(401);
    }

    public function test_store_requires_file(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', ['target_amount' => 5000])
            ->assertStatus(422);
    }

    public function test_store_requires_target_amount_when_files_absent(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', ['file' => $file])
            ->assertStatus(422);
    }

    public function test_store_does_not_require_target_amount_when_files_present(): void
    {
        Bus::fake();
        Storage::fake('s3');
        $this->mockClearanceService();

        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'files' => [
                    ['amount' => 5000, 'tolerance' => 200],
                ],
            ])
            ->assertStatus(202);
    }

    public function test_store_rejects_target_amount_below_minimum(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', ['file' => $file, 'target_amount' => 0])
            ->assertStatus(422);
    }

    public function test_store_rejects_target_amount_above_maximum(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', ['file' => $file, 'target_amount' => 1000001])
            ->assertStatus(422);
    }

    public function test_store_rejects_invalid_file_type(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', ['file' => $file, 'target_amount' => 5000])
            ->assertStatus(422);
    }

    public function test_store_rejects_file_exceeding_max_size(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->create('test.csv', 51201, 'text/csv');

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', ['file' => $file, 'target_amount' => 5000])
            ->assertStatus(422);
    }

    public function test_store_rejects_tolerance_below_zero(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', ['file' => $file, 'target_amount' => 5000, 'tolerance' => -1])
            ->assertStatus(422);
    }

    public function test_store_rejects_tolerance_above_maximum(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', ['file' => $file, 'target_amount' => 5000, 'tolerance' => 10001])
            ->assertStatus(422);
    }

    public function test_store_rejects_empty_custom_amounts_array(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', ['file' => $file, 'target_amount' => 5000, 'custom_amounts' => []])
            ->assertStatus(422);
    }

    public function test_store_rejects_custom_amounts_with_invalid_values(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', ['file' => $file, 'target_amount' => 5000, 'custom_amounts' => [0.001]])
            ->assertStatus(422);
    }

    public function test_store_rejects_custom_amounts_exceeding_max(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', ['file' => $file, 'target_amount' => 5000, 'custom_amounts' => [1000.00]])
            ->assertStatus(422);
    }

    // ── files[] validation ──

    public function test_store_rejects_files_exceeding_max_count(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $files = array_fill(0, 51, ['amount' => 1000, 'tolerance' => 100]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', ['file' => $file, 'files' => $files])
            ->assertStatus(422);
    }

    public function test_store_rejects_files_with_missing_amount(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'files' => [['tolerance' => 200]],
            ])
            ->assertStatus(422);
    }

    public function test_store_rejects_files_with_amount_below_minimum(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'files' => [['amount' => 0, 'tolerance' => 200]],
            ])
            ->assertStatus(422);
    }

    public function test_store_rejects_files_with_amount_above_maximum(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'files' => [['amount' => 1000001, 'tolerance' => 200]],
            ])
            ->assertStatus(422);
    }

    public function test_store_rejects_files_with_tolerance_below_zero(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'files' => [['amount' => 5000, 'tolerance' => -1]],
            ])
            ->assertStatus(422);
    }

    public function test_store_rejects_files_with_tolerance_above_maximum(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'files' => [['amount' => 5000, 'tolerance' => 10001]],
            ])
            ->assertStatus(422);
    }

    // ══════════════════════════════════════════════
    // store() — Successful Upload & Job Dispatch
    // ══════════════════════════════════════════════

    public function test_store_accepts_valid_csv_with_legacy_params_and_dispatches_job(): void
    {
        Bus::fake();
        Storage::fake('s3');
        $this->mockClearanceService();

        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'target_amount' => 5000,
            ]);

        $response->assertStatus(202)
            ->assertJsonStructure([
                'data' => [
                    'token', 'status', 'original_file', 'total_rows',
                    'target_amount', 'tolerance', 'pricing_strategy',
                    'pricing_amounts', 'file_count', 'file_configs',
                    'headers', 'message',
                ],
            ])
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.total_rows', 150)
            ->assertJsonPath('data.target_amount', 5000)
            ->assertJsonPath('data.file_count', 1);

        Bus::assertDispatched(ProcessFileGenerationJob::class);
    }

    public function test_store_accepts_per_file_configs_and_dispatches_job(): void
    {
        Bus::fake();
        Storage::fake('s3');
        $this->mockClearanceService();

        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'files' => [
                    ['amount' => 13000, 'tolerance' => 200],
                    ['amount' => 5000, 'tolerance' => 100],
                    ['amount' => 8000, 'tolerance' => 300],
                ],
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.file_count', 3)
            ->assertJsonPath('data.target_amount', 13000)  // primary = first file
            ->assertJsonPath('data.tolerance', 200)
            ->assertJsonCount(3, 'data.file_configs')
            ->assertJsonPath('data.file_configs.0.amount', 13000)
            ->assertJsonPath('data.file_configs.0.tolerance', 200)
            ->assertJsonPath('data.file_configs.1.amount', 5000)
            ->assertJsonPath('data.file_configs.1.tolerance', 100)
            ->assertJsonPath('data.file_configs.2.amount', 8000)
            ->assertJsonPath('data.file_configs.2.tolerance', 300);

        Bus::assertDispatched(ProcessFileGenerationJob::class);
    }

    public function test_store_legacy_file_count_replicates_config(): void
    {
        Bus::fake();
        Storage::fake('s3');
        $this->mockClearanceService();

        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'target_amount' => 7000,
                'tolerance' => 150,
                'file_count' => 3,
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.file_count', 3)
            ->assertJsonCount(3, 'data.file_configs')
            ->assertJsonPath('data.file_configs.0.amount', 7000)
            ->assertJsonPath('data.file_configs.0.tolerance', 150)
            ->assertJsonPath('data.file_configs.1.amount', 7000)
            ->assertJsonPath('data.file_configs.1.tolerance', 150)
            ->assertJsonPath('data.file_configs.2.amount', 7000)
            ->assertJsonPath('data.file_configs.2.tolerance', 150);
    }

    public function test_store_creates_batch_record_with_file_configs(): void
    {
        Bus::fake();
        Storage::fake('s3');
        $this->mockClearanceService(200, 'uploads/batch-test.csv');

        $file = UploadedFile::fake()->create('batch-test.csv', 100, 'text/csv');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'files' => [
                    ['amount' => 10000, 'tolerance' => 250],
                    ['amount' => 5000, 'tolerance' => 100],
                ],
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
            'file_count' => 2,
        ]);

        $batch = FileGenerationBatch::where('token', $batchToken)->first();
        $this->assertCount(2, $batch->file_configs);
        $this->assertEquals(10000, $batch->file_configs[0]['amount']);
        $this->assertEquals(5000, $batch->file_configs[1]['amount']);
    }

    public function test_store_sets_cache_entry_with_file_configs(): void
    {
        Bus::fake();
        Storage::fake('s3');
        $this->mockClearanceService(50, 'uploads/cache-test.csv');

        $file = UploadedFile::fake()->create('cache-test.csv', 100, 'text/csv');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'files' => [
                    ['amount' => 3000, 'tolerance' => 100],
                ],
            ]);

        $response->assertStatus(202);

        $batchToken = $response->json('data.token');
        $cached = Cache::get("file_generation:{$batchToken}");

        $this->assertNotNull($cached);
        $this->assertEquals('queued', $cached['status']);
        $this->assertEquals(50, $cached['total_rows']);
        $this->assertEquals(3000, $cached['target_amount']);
        $this->assertCount(1, $cached['file_configs']);
        $this->assertEquals(3000, $cached['file_configs'][0]['amount']);
    }

    public function test_store_uses_default_tolerance_when_not_provided(): void
    {
        Bus::fake();
        Storage::fake('s3');
        $this->mockClearanceService(100, 'uploads/default-tol.csv');

        $file = UploadedFile::fake()->create('default-tol.csv', 100, 'text/csv');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'target_amount' => 5000,
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.tolerance', 200)
            ->assertJsonPath('data.file_configs.0.tolerance', 200);
    }

    public function test_store_per_file_uses_default_tolerance_when_omitted(): void
    {
        Bus::fake();
        Storage::fake('s3');
        $this->mockClearanceService();

        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'files' => [
                    ['amount' => 5000],
                ],
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.file_configs.0.tolerance', 200);
    }

    public function test_store_uses_default_strategy_when_not_provided(): void
    {
        Bus::fake();
        Storage::fake('s3');
        $this->mockClearanceService(100, 'uploads/default-strategy.csv');

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
        $this->mockClearanceService(75, 'uploads/test-file.xlsx');

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
        $this->mockClearanceService(100, 'uploads/custom-amounts.csv');

        $file = UploadedFile::fake()->create('custom-amounts.csv', 100, 'text/csv');

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'target_amount' => 5000,
                'custom_amounts' => [9.99, 19.99, 49.99],
            ])
            ->assertStatus(202);
    }

    public function test_store_accepts_mixed_per_file_configs_with_different_amounts(): void
    {
        Bus::fake();
        Storage::fake('s3');
        $this->mockClearanceService();

        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', [
                'file' => $file,
                'files' => [
                    ['amount' => 1000, 'tolerance' => 50],
                    ['amount' => 25000, 'tolerance' => 500],
                    ['amount' => 3000, 'tolerance' => 0],
                ],
                'pricing_strategy' => 'premium',
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.file_count', 3)
            ->assertJsonPath('data.file_configs.0.amount', 1000)
            ->assertJsonPath('data.file_configs.0.tolerance', 50)
            ->assertJsonPath('data.file_configs.1.amount', 25000)
            ->assertJsonPath('data.file_configs.1.tolerance', 500)
            ->assertJsonPath('data.file_configs.2.amount', 3000)
            ->assertJsonPath('data.file_configs.2.tolerance', 0);
    }

    // ══════════════════════════════════════════════
    // store() — Error Handling
    // ══════════════════════════════════════════════

    public function test_store_returns_422_when_clearance_validation_fails(): void
    {
        Storage::fake('s3');

        $this->mock(FileClearanceService::class, function ($mock) {
            $mock->shouldReceive('validateAndStore')->once()
                ->andThrow(new \InvalidArgumentException('Invalid CSV headers'));
        });

        $file = UploadedFile::fake()->create('bad-headers.csv', 100, 'text/csv');

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', ['file' => $file, 'target_amount' => 5000])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invalid CSV headers');
    }

    public function test_store_returns_500_on_unexpected_error(): void
    {
        Storage::fake('s3');

        $this->mock(FileClearanceService::class, function ($mock) {
            $mock->shouldReceive('validateAndStore')->once()
                ->andThrow(new \RuntimeException('S3 connection failed'));
        });

        $file = UploadedFile::fake()->create('error-test.csv', 100, 'text/csv');

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-generation', ['file' => $file, 'target_amount' => 5000])
            ->assertStatus(500)
            ->assertJsonStructure(['message', 'error']);
    }

    // ══════════════════════════════════════════════
    // status()
    // ══════════════════════════════════════════════

    public function test_status_returns_queued_state(): void
    {
        $genToken = Str::uuid()->toString();

        Cache::put("file_generation:{$genToken}", [
            'status' => 'queued', 'phase' => 'queued', 'total_rows' => 200, 'processed' => 0,
            'target_amount' => 5000, 'tolerance' => 500, 'pricing_strategy' => 'default',
            'file_count' => 1, 'file_configs' => [['amount' => 5000, 'tolerance' => 500]],
            'original_file' => 'test.csv',
        ], 7200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.phase', 'queued')
            ->assertJsonPath('data.total_rows', 200)
            ->assertJsonPath('data.processed', 0)
            ->assertJsonPath('data.has_download', false)
            ->assertJsonPath('data.file_count', 1)
            ->assertJsonCount(1, 'data.file_configs');

        $this->assertEquals(0, $response->json('data.progress'));
    }

    public function test_status_returns_progress_during_processing(): void
    {
        $genToken = Str::uuid()->toString();

        Cache::put("file_generation:{$genToken}", [
            'status' => 'processing', 'phase' => 'filtering', 'total_rows' => 200, 'processed' => 100,
            'target_amount' => 5000, 'tolerance' => 500, 'eligible_rows' => 80,
            'selected_rows' => 0, 'achieved_amount' => 0, 'pricing_strategy' => 'default',
            'file_count' => 2, 'file_configs' => [
                ['amount' => 5000, 'tolerance' => 500],
                ['amount' => 3000, 'tolerance' => 100],
            ],
            'original_file' => 'test.csv',
        ], 7200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.phase', 'filtering')
            ->assertJsonPath('data.eligible_rows', 80)
            ->assertJsonPath('data.file_count', 2)
            ->assertJsonCount(2, 'data.file_configs');

        $this->assertEquals(50, $response->json('data.progress'));
    }

    public function test_status_returns_completed_with_result_files(): void
    {
        $genToken = Str::uuid()->toString();

        Cache::put("file_generation:{$genToken}", [
            'status' => 'completed', 'phase' => 'completed', 'total_rows' => 200, 'processed' => 200,
            'target_amount' => 5000, 'tolerance' => 500, 'eligible_rows' => 150,
            'selected_rows' => 120, 'achieved_amount' => 9850,
            'pricing_strategy' => 'default', 'original_file' => 'test.csv',
            'file_count' => 2, 'file_configs' => [
                ['amount' => 5000, 'tolerance' => 500],
                ['amount' => 5000, 'tolerance' => 500],
            ],
            's3_result_files' => [
                ['s3_path' => 'results/gen_1.csv', 'file_name' => 'gen_1.csv', 'row_count' => 60, 'amount' => 4900, 'target_amount' => 5000, 'tolerance' => 500],
                ['s3_path' => 'results/gen_2.csv', 'file_name' => 'gen_2.csv', 'row_count' => 60, 'amount' => 4950, 'target_amount' => 5000, 'tolerance' => 500],
            ],
            's3_path_leftover' => 'results/leftover.csv',
            'leftover_rows' => 30,
            'completed_at' => now()->toISOString(),
        ], 7200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.selected_rows', 120)
            ->assertJsonPath('data.achieved_amount', 9850)
            ->assertJsonPath('data.has_download', true)
            ->assertJsonPath('data.has_leftover', true)
            ->assertJsonPath('data.leftover_rows', 30)
            ->assertJsonCount(2, 'data.result_files')
            ->assertJsonPath('data.result_files.0.row_count', 60)
            ->assertJsonPath('data.result_files.0.target_amount', 5000)
            ->assertJsonPath('data.result_files.1.amount', 4950);
    }

    public function test_status_returns_404_for_expired_token(): void
    {
        $genToken = Str::uuid()->toString();

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/status")
            ->assertStatus(404)
            ->assertJsonPath('message', 'Token expired or not found.');
    }

    public function test_status_returns_error_information(): void
    {
        $genToken = Str::uuid()->toString();

        Cache::put("file_generation:{$genToken}", [
            'status' => 'failed', 'phase' => 'error', 'total_rows' => 200, 'processed' => 50,
            'target_amount' => 5000, 'error' => 'Insufficient eligible rows to reach target amount.',
            'original_file' => 'test.csv',
        ], 7200);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/status")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', 'Insufficient eligible rows to reach target amount.');
    }

    public function test_status_returns_warning_information(): void
    {
        $genToken = Str::uuid()->toString();

        Cache::put("file_generation:{$genToken}", [
            'status' => 'completed', 'phase' => 'completed', 'total_rows' => 200, 'processed' => 200,
            'target_amount' => 5000, 'tolerance' => 500, 'achieved_amount' => 4600,
            'warning' => 'Eligible rows exhausted after file 2 of 3.',
            's3_path_result' => 'results/output.csv', 'original_file' => 'test.csv',
        ], 7200);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/status")
            ->assertStatus(200)
            ->assertJsonPath('data.warning', 'Eligible rows exhausted after file 2 of 3.');
    }

    public function test_status_returns_exclusion_counts(): void
    {
        $genToken = Str::uuid()->toString();

        Cache::put("file_generation:{$genToken}", [
            'status' => 'completed', 'phase' => 'completed', 'total_rows' => 500, 'processed' => 500,
            'target_amount' => 10000, 'tolerance' => 500,
            'excluded_blacklist_rows' => 20, 'excluded_billing_rows' => 15,
            'excluded_previously_used_rows' => 30, 'eligible_rows' => 435,
            'selected_rows' => 300, 'achieved_amount' => 9800,
            's3_path_result' => 'results/output.csv', 'original_file' => 'test.csv',
        ], 7200);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/status")
            ->assertStatus(200)
            ->assertJsonPath('data.excluded_blacklist_rows', 20)
            ->assertJsonPath('data.excluded_billing_rows', 15)
            ->assertJsonPath('data.excluded_previously_used_rows', 30);
    }

    public function test_status_response_structure(): void
    {
        $genToken = Str::uuid()->toString();

        Cache::put("file_generation:{$genToken}", [
            'status' => 'queued', 'phase' => 'queued', 'total_rows' => 100, 'processed' => 0,
            'target_amount' => 5000, 'tolerance' => 500, 'original_file' => 'test.csv',
        ], 7200);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/status")
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'status', 'phase', 'total_rows', 'processed', 'progress',
                    'eligible_rows', 'selected_rows', 'achieved_amount',
                    'target_amount', 'tolerance', 'pricing_strategy',
                    'file_count', 'file_configs',
                    'excluded_blacklist_rows', 'excluded_billing_rows',
                    'excluded_previously_used_rows',
                    'error', 'warning', 'original_file', 'completed_at',
                    'has_download', 'result_files', 'has_leftover', 'leftover_rows',
                ],
            ]);
    }

    // ══════════════════════════════════════════════
    // download()
    // ══════════════════════════════════════════════

    public function test_download_returns_404_for_nonexistent_token(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/file-generation/' . Str::uuid() . '/download')
            ->assertStatus(404);
    }

    public function test_download_returns_404_when_not_completed(): void
    {
        $genToken = Str::uuid()->toString();
        Cache::put("file_generation:{$genToken}", ['status' => 'processing', 'phase' => 'filtering', 'total_rows' => 200, 'processed' => 100], 7200);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/download")
            ->assertStatus(404);
    }

    public function test_download_returns_404_when_no_output_file(): void
    {
        $genToken = Str::uuid()->toString();
        Cache::put("file_generation:{$genToken}", ['status' => 'completed', 'phase' => 'completed', 'total_rows' => 200, 'processed' => 200], 7200);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/download")
            ->assertStatus(404);
    }

    public function test_download_streams_file_from_cache_when_completed(): void
    {
        $genToken = Str::uuid()->toString();

        Cache::put("file_generation:{$genToken}", [
            'status' => 'completed', 'phase' => 'completed', 'total_rows' => 200, 'processed' => 200,
            's3_result_files' => [
                ['s3_path' => 'results/gen_1.csv', 'file_name' => 'gen_1.csv', 'row_count' => 100, 'amount' => 5000, 'target_amount' => 5000, 'tolerance' => 200],
            ],
            's3_path_result' => 'results/gen_1.csv', 'file_name' => 'gen_1.csv',
        ], 7200);

        $this->mock(FileClearanceService::class, function ($mock) {
            $mock->shouldReceive('streamDownloadFromS3')
                ->with('results/gen_1.csv', 'gen_1.csv')
                ->once()
                ->andReturn(response()->streamDownload(function () {
                    echo "first_name,last_name,iban\nJohn,Doe,DE123\n";
                }, 'gen_1.csv', ['Content-Type' => 'text/csv']));
        });

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/download")
            ->assertStatus(200);
    }

    public function test_download_specific_file_by_index(): void
    {
        $genToken = Str::uuid()->toString();

        Cache::put("file_generation:{$genToken}", [
            'status' => 'completed', 'phase' => 'completed', 'total_rows' => 200, 'processed' => 200,
            's3_result_files' => [
                ['s3_path' => 'results/gen_1.csv', 'file_name' => 'gen_1.csv', 'row_count' => 50, 'amount' => 5000, 'target_amount' => 5000, 'tolerance' => 200],
                ['s3_path' => 'results/gen_2.csv', 'file_name' => 'gen_2.csv', 'row_count' => 50, 'amount' => 3000, 'target_amount' => 3000, 'tolerance' => 100],
            ],
        ], 7200);

        $this->mock(FileClearanceService::class, function ($mock) {
            $mock->shouldReceive('streamDownloadFromS3')
                ->with('results/gen_2.csv', 'gen_2.csv')
                ->once()
                ->andReturn(response()->streamDownload(function () {
                    echo "data\n";
                }, 'gen_2.csv', ['Content-Type' => 'text/csv']));
        });

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/download?file_index=1")
            ->assertStatus(200);
    }

    public function test_download_leftover_file(): void
    {
        $genToken = Str::uuid()->toString();

        Cache::put("file_generation:{$genToken}", [
            'status' => 'completed', 'phase' => 'completed', 'total_rows' => 200, 'processed' => 200,
            's3_path_leftover' => 'results/leftover.csv', 'leftover_file_name' => 'leftover.csv',
            's3_path_result' => 'results/gen_1.csv',
        ], 7200);

        $this->mock(FileClearanceService::class, function ($mock) {
            $mock->shouldReceive('streamDownloadFromS3')
                ->with('results/leftover.csv', 'leftover.csv')
                ->once()
                ->andReturn(response()->streamDownload(function () {
                    echo "leftover_data\n";
                }, 'leftover.csv', ['Content-Type' => 'text/csv']));
        });

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/download?type=leftover")
            ->assertStatus(200);
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
            $mock->shouldReceive('streamDownloadFromS3')->once()
                ->andReturn(response()->streamDownload(function () { echo "data\n"; }, 'fallback-output.csv', ['Content-Type' => 'text/csv']));
        });

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/download")
            ->assertStatus(200);
    }

    public function test_download_returns_404_when_batch_not_completed(): void
    {
        $genToken = Str::uuid()->toString();
        $this->createBatch(['token' => $genToken, 'status' => 'processing']);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/download")
            ->assertStatus(404);
    }

    public function test_download_returns_404_when_s3_file_missing(): void
    {
        $genToken = Str::uuid()->toString();

        Cache::put("file_generation:{$genToken}", [
            'status' => 'completed', 'phase' => 'completed', 'total_rows' => 200, 'processed' => 200,
            's3_path_result' => 'results/missing.csv', 'file_name' => 'missing.csv',
        ], 7200);

        $this->mock(FileClearanceService::class, function ($mock) {
            $mock->shouldReceive('streamDownloadFromS3')->once()
                ->andThrow(new \RuntimeException('File not found on S3'));
        });

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-generation/{$genToken}/download")
            ->assertStatus(404)
            ->assertJsonPath('message', 'File not found. Please run generation again.');
    }

    // ══════════════════════════════════════════════
    // history()
    // ══════════════════════════════════════════════

    public function test_history_requires_authentication(): void
    {
        $this->getJson('/api/admin/file-generation/history')->assertStatus(401);
    }

    public function test_history_returns_paginated_batches(): void
    {
        for ($i = 0; $i < 5; $i++) $this->createBatch();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/file-generation/history');

        $response->assertStatus(200)->assertJsonStructure(['data']);
        $this->assertCount(5, $response->json('data'));
    }

    public function test_history_respects_per_page_parameter(): void
    {
        for ($i = 0; $i < 25; $i++) $this->createBatch();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/file-generation/history?per_page=10');

        $response->assertStatus(200);
        $this->assertCount(10, $response->json('data'));
    }

    public function test_history_default_pagination(): void
    {
        for ($i = 0; $i < 25; $i++) $this->createBatch();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/file-generation/history');

        $response->assertStatus(200);
        $this->assertCount(20, $response->json('data'));
    }

    public function test_history_second_page(): void
    {
        for ($i = 0; $i < 25; $i++) $this->createBatch();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/file-generation/history?per_page=20&page=2');

        $response->assertStatus(200);
        $this->assertCount(5, $response->json('data'));
    }

    public function test_history_returns_empty_list_when_no_batches(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/file-generation/history')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }
}
