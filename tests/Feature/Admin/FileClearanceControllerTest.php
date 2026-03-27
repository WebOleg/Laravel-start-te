<?php

/**
 * Feature tests for FileClearanceController.
 */

namespace Tests\Feature\Admin;

use App\Jobs\ProcessFileClearanceJob;
use App\Models\User;
use App\Services\FileClearanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileClearanceControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');

        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test')->plainTextToken;
    }

    // ──────────────────────────────────────────────
    // store() — Authentication
    // ──────────────────────────────────────────────

    public function test_store_requires_authentication(): void
    {
        $file = UploadedFile::fake()->createWithContent('test.csv', "iban\nDE89370400440532013000\n");

        $response = $this->postJson('/api/admin/file-clearance', ['file' => $file]);

        $response->assertStatus(401);
    }

    // ──────────────────────────────────────────────
    // store() — Validation
    // ──────────────────────────────────────────────

    public function test_store_validates_file_required(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-clearance');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_store_rejects_oversized_file(): void
    {
        $file = UploadedFile::fake()->create('huge.csv', 51201); // exceeds 51200 KB limit

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-clearance', ['file' => $file]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_store_rejects_invalid_mime_type(): void
    {
        $file = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-clearance', ['file' => $file]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    // ──────────────────────────────────────────────
    // store() — Successful Upload
    // ──────────────────────────────────────────────

    public function test_store_accepts_valid_csv_and_dispatches_job(): void
    {
        Queue::fake();

        $csvContent = "first_name,last_name,iban\nJohn,Doe,DE89370400440532013000\nJane,Smith,FR7630006000011234567890189\n";
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->mock(FileClearanceService::class, function ($mock) {
            $mock->shouldReceive('validateAndStore')
                ->once()
                ->andReturn([
                    'total_rows'  => 2,
                    's3_path'     => 'file-clearance/test.csv',
                    'headers'     => ['first_name', 'last_name', 'iban'],
                    'header_meta' => ['iban_index' => 2],
                ]);
        });

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-clearance', ['file' => $file]);

        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.total_rows', 2)
            ->assertJsonPath('data.original_file', 'test.csv')
            ->assertJsonStructure([
                'data' => ['token', 'status', 'original_file', 'total_rows', 'headers', 'message'],
            ]);

        Queue::assertPushed(ProcessFileClearanceJob::class);
    }

    public function test_store_seeds_cache_with_correct_data(): void
    {
        Queue::fake();

        $csvContent = "iban\nDE89370400440532013000\n";
        $file = UploadedFile::fake()->createWithContent('clearance.csv', $csvContent);

        $this->mock(FileClearanceService::class, function ($mock) {
            $mock->shouldReceive('validateAndStore')
                ->once()
                ->andReturn([
                    'total_rows'  => 1,
                    's3_path'     => 'file-clearance/clearance.csv',
                    'headers'     => ['iban'],
                    'header_meta' => ['iban_index' => 0],
                ]);
        });

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-clearance', ['file' => $file]);

        $response->assertStatus(202);

        $token = $response->json('data.token');
        $cached = Cache::get("file_clearance:{$token}");

        $this->assertNotNull($cached);
        $this->assertEquals('queued', $cached['status']);
        $this->assertEquals(1, $cached['total_rows']);
        $this->assertEquals(0, $cached['processed']);
        $this->assertEquals('clearance.csv', $cached['original_file']);
        $this->assertEquals($this->user->id, $cached['admin_id']);
    }

    // ──────────────────────────────────────────────
    // store() — Error Handling
    // ──────────────────────────────────────────────

    public function test_store_returns_422_when_iban_column_missing(): void
    {
        $csvContent = "first_name,last_name,email\nJohn,Doe,john@test.com\n";
        $file = UploadedFile::fake()->createWithContent('no_iban.csv', $csvContent);

        $this->mock(FileClearanceService::class, function ($mock) {
            $mock->shouldReceive('validateAndStore')
                ->once()
                ->andThrow(new \InvalidArgumentException('IBAN column not found in file headers.'));
        });

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-clearance', ['file' => $file]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'IBAN column not found in file headers.');
    }

    public function test_store_returns_500_on_unexpected_error(): void
    {
        $csvContent = "iban\nDE89370400440532013000\n";
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $this->mock(FileClearanceService::class, function ($mock) {
            $mock->shouldReceive('validateAndStore')
                ->once()
                ->andThrow(new \RuntimeException('S3 connection failed'));
        });

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-clearance', ['file' => $file]);

        $response->assertStatus(500)
            ->assertJsonPath('message', 'File clearance failed. Please try again.')
            ->assertJsonPath('error', 'S3 connection failed');
    }

    // ──────────────────────────────────────────────
    // status() — Authentication & Token Lookup
    // ──────────────────────────────────────────────

    public function test_status_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/file-clearance/some-token/status');

        $response->assertStatus(401);
    }

    public function test_status_returns_404_for_expired_token(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/file-clearance/nonexistent-token/status');

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Token expired or not found. Please upload the file again.');
    }

    // ──────────────────────────────────────────────
    // status() — Progress Reporting
    // ──────────────────────────────────────────────

    public function test_status_returns_queued_state(): void
    {
        $token = 'test-token-queued';

        Cache::put("file_clearance:{$token}", [
            'status'        => 'queued',
            'total_rows'    => 100,
            'processed'     => 0,
            'original_file' => 'test.csv',
            'admin_id'      => $this->user->id,
            'created_at'    => now()->toISOString(),
        ], 7200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-clearance/{$token}/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.total_rows', 100)
            ->assertJsonPath('data.processed', 0)
            ->assertJsonPath('data.progress', 0);
    }

    public function test_status_returns_processing_progress(): void
    {
        $token = 'test-token-processing';

        Cache::put("file_clearance:{$token}", [
            'status'        => 'processing',
            'total_rows'    => 200,
            'processed'     => 100,
            'cleared_rows'  => 80,
            'excluded_rows' => 20,
            'vop_resolved'  => 90,
            'vop_failed'    => 10,
            'original_file' => 'test.csv',
        ], 7200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-clearance/{$token}/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.total_rows', 200)
            ->assertJsonPath('data.processed', 100)
            ->assertJsonPath('data.cleared_rows', 80)
            ->assertJsonPath('data.excluded_rows', 20)
            ->assertJsonPath('data.vop_resolved', 90)
            ->assertJsonPath('data.vop_failed', 10);

        $this->assertEquals(50.0, $response->json('data.progress'));
    }

    public function test_status_returns_completed_state(): void
    {
        $token = 'test-token-completed';

        Cache::put("file_clearance:{$token}", [
            'status'         => 'completed',
            'total_rows'     => 50,
            'processed'      => 50,
            'cleared_rows'   => 45,
            'excluded_rows'  => 5,
            'vop_resolved'   => 48,
            'vop_failed'     => 2,
            'original_file'  => 'test.csv',
            'completed_at'   => now()->toISOString(),
            's3_path_result' => 'clearance/results/abc/cleared_test.csv',
            'file_name'      => 'cleared_test.csv',
        ], 7200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-clearance/{$token}/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.processed', 50)
            ->assertJsonPath('data.cleared_rows', 45)
            ->assertJsonPath('data.excluded_rows', 5)
            ->assertJsonPath('data.original_file', 'test.csv')
            ->assertJsonStructure(['data' => ['completed_at']]);

        $this->assertEquals(100.0, $response->json('data.progress'));
    }

    public function test_status_returns_error_state(): void
    {
        $token = 'test-token-error';

        Cache::put("file_clearance:{$token}", [
            'status'     => 'failed',
            'total_rows' => 100,
            'processed'  => 40,
            'error'      => 'VOP service unavailable',
        ], 7200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-clearance/{$token}/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', 'VOP service unavailable');

        $this->assertEquals(40.0, $response->json('data.progress'));
    }

    public function test_status_handles_zero_total_rows_gracefully(): void
    {
        $token = 'test-token-zero';

        Cache::put("file_clearance:{$token}", [
            'status'     => 'queued',
            'total_rows' => 0,
            'processed'  => 0,
        ], 7200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-clearance/{$token}/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.progress', 0);
    }

    public function test_status_response_includes_full_data_structure(): void
    {
        $token = 'test-token-structure';

        Cache::put("file_clearance:{$token}", [
            'status'     => 'processing',
            'total_rows' => 10,
            'processed'  => 5,
        ], 7200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-clearance/{$token}/status");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'status',
                    'total_rows',
                    'processed',
                    'cleared_rows',
                    'excluded_rows',
                    'excluded_iban_rows',
                    'excluded_bic_rows',
                    'invalid_name_rows',
                    'vop_resolved',
                    'vop_failed',
                    'progress',
                    'error',
                    'headers',
                    'excluded_details',
                    'original_file',
                    'completed_at',
                    'has_excluded_ibans',
                    'has_excluded_bics',
                    'has_invalid_names',
                ],
            ]);
    }

    public function test_status_defaults_missing_keys_to_null_or_zero(): void
    {
        $token = 'test-token-minimal';

        Cache::put("file_clearance:{$token}", [
            'status'     => 'queued',
            'total_rows' => 10,
            'processed'  => 0,
        ], 7200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-clearance/{$token}/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.cleared_rows', 0)
            ->assertJsonPath('data.excluded_rows', 0)
            ->assertJsonPath('data.excluded_iban_rows', 0)
            ->assertJsonPath('data.excluded_bic_rows', 0)
            ->assertJsonPath('data.invalid_name_rows', 0)
            ->assertJsonPath('data.vop_resolved', 0)
            ->assertJsonPath('data.vop_failed', 0)
            ->assertJsonPath('data.error', null)
            ->assertJsonPath('data.headers', null)
            ->assertJsonPath('data.excluded_details', null)
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.has_excluded_ibans', false)
            ->assertJsonPath('data.has_excluded_bics', false)
            ->assertJsonPath('data.has_invalid_names', false);
    }

    // ──────────────────────────────────────────────
    // download() — Authentication
    // ──────────────────────────────────────────────

    public function test_download_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/file-clearance/some-token/download');

        $response->assertStatus(401);
    }

    // ──────────────────────────────────────────────
    // download() — Token & Status Validation
    // ──────────────────────────────────────────────

    public function test_download_returns_404_for_expired_token(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/file-clearance/nonexistent-token/download');

        $response->assertStatus(404)
            ->assertJsonPath('message', 'File not ready or token expired.');
    }

    public function test_download_returns_404_when_not_completed(): void
    {
        $token = 'test-token-not-done';

        Cache::put("file_clearance:{$token}", [
            'status'     => 'processing',
            'total_rows' => 100,
            'processed'  => 50,
        ], 7200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-clearance/{$token}/download");

        $response->assertStatus(404)
            ->assertJsonPath('message', 'File not ready or token expired.');
    }

    public function test_download_returns_404_when_s3_path_missing(): void
    {
        $token = 'test-token-no-path';

        Cache::put("file_clearance:{$token}", [
            'status'     => 'completed',
            'total_rows' => 10,
            'processed'  => 10,
        ], 7200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-clearance/{$token}/download");

        $response->assertStatus(404)
            ->assertJsonPath('message', 'File not available (no matching rows). Nothing to download.');
    }

    public function test_download_returns_404_when_file_does_not_exist_on_s3(): void
    {
        $token = 'test-token-missing-file';

        Cache::put("file_clearance:{$token}", [
            'status'         => 'completed',
            's3_path_result' => 'clearance/results/abc/nonexistent.csv',
            'file_name'      => 'cleared.csv',
        ], 7200);

        $this->mock(FileClearanceService::class, function ($mock) {
            $mock->shouldReceive('streamDownloadFromS3')
                ->once()
                ->andThrow(new \RuntimeException('Cleared file not found in S3'));
        });

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-clearance/{$token}/download");

        $response->assertStatus(404)
            ->assertJsonPath('message', 'File not found. Please run clearance again.');
    }

    // ──────────────────────────────────────────────
    // download() — Successful Download
    // ──────────────────────────────────────────────

    public function test_download_returns_csv_file_when_completed(): void
    {
        $token = 'test-token-download';
        $s3Path = 'clearance/results/abc/cleared_output.csv';
        $csvContent = "iban,bic,status\nDE89370400440532013000,COBADEFFXXX,cleared\n";

        Storage::disk('s3')->put($s3Path, $csvContent);

        Cache::put("file_clearance:{$token}", [
            'status'         => 'completed',
            's3_path_result' => $s3Path,
            'file_name'      => 'cleared_output.csv',
        ], 7200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->get("/api/admin/file-clearance/{$token}/download");

        $response->assertStatus(200)
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertDownload('cleared_output.csv');
    }

    public function test_download_uses_default_filename_when_not_set(): void
    {
        $token = 'test-token-default-name';
        $s3Path = 'clearance/results/def/cleared.csv';
        $csvContent = "iban\nDE89370400440532013000\n";

        Storage::disk('s3')->put($s3Path, $csvContent);

        Cache::put("file_clearance:{$token}", [
            'status'         => 'completed',
            's3_path_result' => $s3Path,
            // file_name intentionally omitted
        ], 7200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->get("/api/admin/file-clearance/{$token}/download");

        $response->assertStatus(200)
            ->assertDownload('cleared.csv');
    }

    // ──────────────────────────────────────────────
    // store() — File Type Acceptance
    // ──────────────────────────────────────────────

    public function test_store_accepts_txt_file(): void
    {
        Queue::fake();

        $file = UploadedFile::fake()->createWithContent('test.txt', "iban\nDE89370400440532013000\n");

        $this->mock(FileClearanceService::class, function ($mock) {
            $mock->shouldReceive('validateAndStore')
                ->once()
                ->andReturn([
                    'total_rows'  => 1,
                    's3_path'     => 'file-clearance/test.txt',
                    'headers'     => ['iban'],
                    'header_meta' => ['iban_index' => 0],
                ]);
        });

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-clearance', ['file' => $file]);

        $response->assertStatus(202);

        Queue::assertPushed(ProcessFileClearanceJob::class);
    }

    public function test_store_dispatches_job_with_correct_parameters(): void
    {
        Queue::fake();

        $csvContent = "iban,name\nDE89370400440532013000,Test\n";
        $file = UploadedFile::fake()->createWithContent('params_test.csv', $csvContent);

        $this->mock(FileClearanceService::class, function ($mock) {
            $mock->shouldReceive('validateAndStore')
                ->once()
                ->andReturn([
                    'total_rows'  => 1,
                    's3_path'     => 'file-clearance/params_test.csv',
                    'headers'     => ['iban', 'name'],
                    'header_meta' => ['iban_index' => 0],
                ]);
        });

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-clearance', ['file' => $file]);

        $response->assertStatus(202);

        $token = $response->json('data.token');

        Queue::assertPushed(ProcessFileClearanceJob::class);
    }

    public function test_store_returns_unique_token_per_upload(): void
    {
        Queue::fake();

        $this->mock(FileClearanceService::class, function ($mock) {
            $mock->shouldReceive('validateAndStore')
                ->twice()
                ->andReturn([
                    'total_rows'  => 1,
                    's3_path'     => 'file-clearance/test.csv',
                    'headers'     => ['iban'],
                    'header_meta' => ['iban_index' => 0],
                ]);
        });

        $file1 = UploadedFile::fake()->createWithContent('test1.csv', "iban\nDE89370400440532013000\n");
        $response1 = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-clearance', ['file' => $file1]);

        $file2 = UploadedFile::fake()->createWithContent('test2.csv', "iban\nFR7630006000011234567890189\n");
        $response2 = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/file-clearance', ['file' => $file2]);

        $token1 = $response1->json('data.token');
        $token2 = $response2->json('data.token');

        $this->assertNotEquals($token1, $token2);
    }

    // ──────────────────────────────────────────────
    // download exclusion files — Authentication
    // ──────────────────────────────────────────────

    public function test_download_excluded_ibans_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/file-clearance/some-token/download-excluded-ibans');
        $response->assertStatus(401);
    }

    public function test_download_excluded_bics_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/file-clearance/some-token/download-excluded-bics');
        $response->assertStatus(401);
    }

    public function test_download_invalid_names_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/file-clearance/some-token/download-invalid-names');
        $response->assertStatus(401);
    }

    // ──────────────────────────────────────────────
    // download exclusion files — Not ready / empty
    // ──────────────────────────────────────────────

    public function test_download_excluded_ibans_returns_404_when_not_completed(): void
    {
        $token = 'test-excl-iban-notdone';

        Cache::put("file_clearance:{$token}", [
            'status'     => 'processing',
            'total_rows' => 100,
            'processed'  => 50,
        ], 7200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-clearance/{$token}/download-excluded-ibans");

        $response->assertStatus(404)
            ->assertJsonPath('message', 'File not ready or token expired.');
    }

    public function test_download_excluded_ibans_returns_404_when_no_rows(): void
    {
        $token = 'test-excl-iban-empty';

        Cache::put("file_clearance:{$token}", [
            'status'     => 'completed',
            'total_rows' => 100,
            'processed'  => 100,
            // s3_path_excluded_ibans intentionally omitted (no rows matched)
        ], 7200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-clearance/{$token}/download-excluded-ibans");

        $response->assertStatus(404)
            ->assertJsonPath('message', 'File not available (no matching rows). Nothing to download.');
    }

    // ──────────────────────────────────────────────
    // download exclusion files — Successful Downloads
    // ──────────────────────────────────────────────

    public function test_download_excluded_ibans_returns_csv(): void
    {
        $token = 'test-excl-iban-ok';
        $s3Path = 'clearance/results/abc/test_excluded_ibans.csv';
        $csvContent = "iban,exclusion_reason\nDE89370400440532013000,IBAN is blacklisted\n";

        Storage::disk('s3')->put($s3Path, $csvContent);

        Cache::put("file_clearance:{$token}", [
            'status'                   => 'completed',
            's3_path_excluded_ibans'   => $s3Path,
            'file_name_excluded_ibans' => 'test_excluded_ibans.csv',
        ], 7200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->get("/api/admin/file-clearance/{$token}/download-excluded-ibans");

        $response->assertStatus(200)
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertDownload('test_excluded_ibans.csv');
    }

    public function test_download_excluded_bics_returns_csv(): void
    {
        $token = 'test-excl-bic-ok';
        $s3Path = 'clearance/results/abc/test_excluded_bics.csv';
        $csvContent = "iban,bic,exclusion_reason\nDE89370400440532013000,COBADEFFXXX,BIC is blacklisted\n";

        Storage::disk('s3')->put($s3Path, $csvContent);

        Cache::put("file_clearance:{$token}", [
            'status'                  => 'completed',
            's3_path_excluded_bics'   => $s3Path,
            'file_name_excluded_bics' => 'test_excluded_bics.csv',
        ], 7200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->get("/api/admin/file-clearance/{$token}/download-excluded-bics");

        $response->assertStatus(200)
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertDownload('test_excluded_bics.csv');
    }

    public function test_download_invalid_names_returns_csv(): void
    {
        $token = 'test-excl-name-ok';
        $s3Path = 'clearance/results/abc/test_invalid_names.csv';
        $csvContent = "iban,first_name,exclusion_reason\nDE89370400440532013000,J0hn,First name contains invalid characters\n";

        Storage::disk('s3')->put($s3Path, $csvContent);

        Cache::put("file_clearance:{$token}", [
            'status'                   => 'completed',
            's3_path_invalid_names'    => $s3Path,
            'file_name_invalid_names'  => 'test_invalid_names.csv',
        ], 7200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->get("/api/admin/file-clearance/{$token}/download-invalid-names");

        $response->assertStatus(200)
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertDownload('test_invalid_names.csv');
    }

    // ──────────────────────────────────────────────
    // status() — Exclusion availability flags
    // ──────────────────────────────────────────────

    public function test_status_shows_exclusion_availability_flags(): void
    {
        $token = 'test-token-flags';

        Cache::put("file_clearance:{$token}", [
            'status'                 => 'completed',
            'total_rows'             => 100,
            'processed'              => 100,
            's3_path_excluded_ibans' => 'clearance/results/abc/excluded_ibans.csv',
            's3_path_invalid_names'  => 'clearance/results/abc/invalid_names.csv',
            // s3_path_excluded_bics intentionally omitted
        ], 7200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/file-clearance/{$token}/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.has_excluded_ibans', true)
            ->assertJsonPath('data.has_excluded_bics', false)
            ->assertJsonPath('data.has_invalid_names', true);
    }
}
