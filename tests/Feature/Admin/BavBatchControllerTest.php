<?php

namespace Tests\Feature\Admin;

use App\Models\BavBatch;
use App\Models\User;
use App\Jobs\ProcessBavBatchJob;
use App\Services\BavBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BavBatchControllerTest extends TestCase
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

    public function test_bav_batch_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/bav/batches');
        $response->assertStatus(401);
    }

    public function test_bav_batch_index_returns_empty_list(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/bav/batches');

        $response->assertStatus(200)
            ->assertJsonPath('data', []);
    }

    public function test_bav_batch_upload_validates_file_required(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/bav/batches/upload');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_bav_batch_upload_with_header_csv(): void
    {
        $csvContent = "first_name,last_name,iban,bic\nJohn,Doe,FR7630006000011234567890189,BNPAFRPPXXX\nJane,Smith,DE89370400440532013000,COBADEFFXXX\n";
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->post('/api/admin/bav/batches/upload', ['file' => $file]);

        $response->assertStatus(201)
            ->assertJsonPath('data.total_records', 2)
            ->assertJsonPath('data.column_mapping.has_header', true)
            ->assertJsonPath('data.column_mapping.iban', 2)
            ->assertJsonPath('data.column_mapping.first_name', 0)
            ->assertJsonPath('data.column_mapping.last_name', 1);
    }

    public function test_bav_batch_upload_auto_detects_headerless_csv(): void
    {
        $csvContent = "SOPHIE,LUKASIK,FR7630003011900005022271171,SOGEFRPPXXX\nCHRISTOPHE,LE PICARD,FR8330002004090000072585B11,CRLYFRPPXXX\n";
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->post('/api/admin/bav/batches/upload', ['file' => $file]);

        $response->assertStatus(201);

        $data = $response->json('data');
        $this->assertEquals(2, $data['total_records']);
        $this->assertFalse($data['column_mapping']['has_header']);
        $this->assertEquals(2, $data['column_mapping']['iban']);
        $this->assertNotNull($data['column_mapping']['first_name']);
        $this->assertNotNull($data['column_mapping']['last_name']);
    }

    public function test_bav_batch_upload_detects_iban_with_spaces(): void
    {
        $csvContent = "MARTINEZ,SANDRINE,FR7630003012302329728872542,SOGEFRPPXXX\nRIGNAULT,VANESSA,FR7610278060505310012802435,CMCIFR2AXXX\n";
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->post('/api/admin/bav/batches/upload', ['file' => $file]);

        $response->assertStatus(201);

        $data = $response->json('data');
        $this->assertEquals(2, $data['column_mapping']['iban']);
        $this->assertNotNull($data['preview']);
        $this->assertCount(2, $data['preview']);
    }

    public function test_bav_batch_status_returns_progress(): void
    {
        $batch = BavBatch::create([
            'user_id' => $this->user->id,
            'original_filename' => 'test.csv',
            'file_path' => 'bav-batches/test.csv',
            'status' => BavBatch::STATUS_PROCESSING,
            'total_records' => 50,
            'processed_records' => 25,
            'success_count' => 20,
            'failed_count' => 5,
            'credits_used' => 25,
            'started_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/bav/batches/{$batch->id}/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.total', 50)
            ->assertJsonPath('data.processed', 25);

        $this->assertEquals(50.0, $response->json('data.percentage'));
    }

    public function test_bav_batch_start_rejects_non_pending(): void
    {
        $batch = BavBatch::create([
            'user_id' => $this->user->id,
            'original_filename' => 'test.csv',
            'file_path' => 'bav-batches/test.csv',
            'status' => BavBatch::STATUS_COMPLETED,
            'total_records' => 10,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/bav/batches/{$batch->id}/start");

        $response->assertStatus(422);
    }

    public function test_bav_batch_download_rejects_incomplete(): void
    {
        $batch = BavBatch::create([
            'user_id' => $this->user->id,
            'original_filename' => 'test.csv',
            'file_path' => 'bav-batches/test.csv',
            'status' => BavBatch::STATUS_PROCESSING,
            'total_records' => 10,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/bav/batches/{$batch->id}/download");

        $response->assertStatus(404);
    }

    public function test_bav_batch_balance_endpoint(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/bav/batches/balance');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['success', 'credits_remaining']]);
    }

    public function test_auto_detect_semicolon_delimited(): void
    {
        $csvContent = "first_name;last_name;iban;bic\nJohn;Doe;FR7630006000011234567890189;BNPAFRPPXXX\n";
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->post('/api/admin/bav/batches/upload', ['file' => $file]);

        $response->assertStatus(201)
            ->assertJsonPath('data.total_records', 1)
            ->assertJsonPath('data.column_mapping.has_header', true);
    }

    public function test_bav_batch_start_with_record_limit(): void
    {
        Queue::fake();

        $batch = BavBatch::create([
            'user_id' => $this->user->id,
            'original_filename' => 'test.csv',
            'file_path' => 'bav-batches/test.csv',
            'status' => BavBatch::STATUS_PENDING,
            'total_records' => 100,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/bav/batches/{$batch->id}/start", [
                'record_limit' => 30,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.record_limit', 30);

        $batch->refresh();
        $this->assertEquals(30, $batch->record_limit);

        Queue::assertPushed(ProcessBavBatchJob::class);
    }

    public function test_bav_batch_start_without_record_limit_uses_total(): void
    {
        Queue::fake();

        $batch = BavBatch::create([
            'user_id' => $this->user->id,
            'original_filename' => 'test.csv',
            'file_path' => 'bav-batches/test.csv',
            'status' => BavBatch::STATUS_PENDING,
            'total_records' => 50,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/bav/batches/{$batch->id}/start");

        $response->assertStatus(200);

        $batch->refresh();
        $this->assertEquals(50, $batch->record_limit);

        Queue::assertPushed(ProcessBavBatchJob::class);
    }

    public function test_bav_batch_start_rejects_limit_exceeding_total(): void
    {
        $batch = BavBatch::create([
            'user_id' => $this->user->id,
            'original_filename' => 'test.csv',
            'file_path' => 'bav-batches/test.csv',
            'status' => BavBatch::STATUS_PENDING,
            'total_records' => 10,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/bav/batches/{$batch->id}/start", [
                'record_limit' => 50,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['record_limit']);
    }

    public function test_bav_batch_start_rejects_zero_limit(): void
    {
        $batch = BavBatch::create([
            'user_id' => $this->user->id,
            'original_filename' => 'test.csv',
            'file_path' => 'bav-batches/test.csv',
            'status' => BavBatch::STATUS_PENDING,
            'total_records' => 10,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/bav/batches/{$batch->id}/start", [
                'record_limit' => 0,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['record_limit']);
    }

    public function test_bav_batch_progress_percentage_uses_record_limit(): void
    {
        $batch = BavBatch::create([
            'user_id' => $this->user->id,
            'original_filename' => 'test.csv',
            'file_path' => 'bav-batches/test.csv',
            'status' => BavBatch::STATUS_PROCESSING,
            'total_records' => 100,
            'record_limit' => 20,
            'processed_records' => 10,
            'success_count' => 8,
            'failed_count' => 2,
            'credits_used' => 10,
            'started_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/bav/batches/{$batch->id}/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.total', 100)
            ->assertJsonPath('data.record_limit', 20)
            ->assertJsonPath('data.effective_limit', 20);

        $this->assertEquals(50.0, $response->json('data.percentage'));
    }

    public function test_bav_batch_index_includes_record_limit(): void
    {
        BavBatch::create([
            'user_id' => $this->user->id,
            'original_filename' => 'test.csv',
            'file_path' => 'bav-batches/test.csv',
            'status' => BavBatch::STATUS_COMPLETED,
            'total_records' => 100,
            'record_limit' => 25,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/bav/batches');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.record_limit', 25)
            ->assertJsonPath('data.0.total_records', 100);
    }
}
