<?php

/**
 * Integration tests for S3 file upload storage and retrieval.
 */

namespace Tests\Feature;

use App\Jobs\ProcessUploadJob;
use App\Models\Upload;
use App\Models\User;
use App\Services\DebtorImportService;
use App\Services\SpreadsheetParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class S3FileUploadTest extends TestCase
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

    public function test_file_is_stored_in_s3(): void
    {
        $csvContent = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->post('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(201);

        $upload = Upload::first();
        $this->assertNotNull($upload);
        $this->assertNotNull($upload->file_path);

        Storage::disk('s3')->assertExists($upload->file_path);
    }

    public function test_upload_job_can_read_from_s3(): void
    {
        $filePath = 'uploads/test_' . uniqid() . '.csv';
        $csvContent = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00\nJane,Smith,ES9121000418450200051332,200.00";

        Storage::disk('s3')->put($filePath, $csvContent);

        $upload = Upload::factory()->create([
            'file_path' => $filePath,
            'status' => Upload::STATUS_PENDING,
            'total_records' => 2,
        ]);

        $columnMapping = [
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'iban' => 'iban',
            'amount' => 'amount',
        ];

        $job = new ProcessUploadJob($upload, $columnMapping);
        $job->handle(
            app(SpreadsheetParserService::class),
            app(DebtorImportService::class)
        );

        $upload->refresh();

        $this->assertEquals(Upload::STATUS_COMPLETED, $upload->status);
        $this->assertEquals(2, $upload->processed_records);
        $this->assertDatabaseHas('debtors', [
            'upload_id' => $upload->id,
            'first_name' => 'John',
        ]);
        $this->assertDatabaseHas('debtors', [
            'upload_id' => $upload->id,
            'first_name' => 'Jane',
        ]);
    }

    public function test_file_delete_removes_from_s3(): void
    {
        $filePath = 'uploads/test_delete_' . uniqid() . '.csv';
        Storage::disk('s3')->put($filePath, 'test content');

        $upload = Upload::factory()->create([
            'file_path' => $filePath,
            'filename' => 'test_delete.csv',
            'status' => Upload::STATUS_COMPLETED,
        ]);

        Storage::disk('s3')->assertExists($filePath);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson('/api/admin/uploads/' . $upload->id);

        $response->assertStatus(200);
        Storage::disk('s3')->assertMissing($filePath);
        $this->assertDatabaseMissing('uploads', ['id' => $upload->id]);
    }

    public function test_full_upload_workflow_with_s3(): void
    {
        $csvContent = "first_name,last_name,iban,amount\nTest,User,DE89370400440532013000,50.00";
        $file = UploadedFile::fake()->createWithContent('workflow_test.csv', $csvContent);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->post('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(201);
        $upload = Upload::first();

        Storage::disk('s3')->assertExists($upload->file_path);

        $this->assertDatabaseHas('debtors', [
            'upload_id' => $upload->id,
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);

        $filePath = $upload->file_path;

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson('/api/admin/uploads/' . $upload->id);

        $response->assertStatus(200);
        
        $this->assertSoftDeleted('uploads', ['id' => $upload->id]);
        $this->assertDatabaseMissing('debtors', ['upload_id' => $upload->id, 'deleted_at' => null]);
    }

    public function test_hard_delete_removes_file_from_s3(): void
    {
        $filePath = 'uploads/hard_delete_' . uniqid() . '.csv';
        Storage::disk('s3')->put($filePath, 'test content');

        $upload = Upload::factory()->create([
            'file_path' => $filePath,
            'filename' => 'hard_delete.csv',
            'status' => Upload::STATUS_COMPLETED,
        ]);

        Storage::disk('s3')->assertExists($filePath);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson('/api/admin/uploads/' . $upload->id);

        $response->assertStatus(200);
        Storage::disk('s3')->assertMissing($filePath);
        $this->assertDatabaseMissing('uploads', ['id' => $upload->id]);
    }
}
