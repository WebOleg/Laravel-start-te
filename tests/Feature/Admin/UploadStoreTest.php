<?php

namespace Tests\Feature\Admin;

use App\Models\Debtor;
use App\Models\Upload;
use App\Models\User;
use App\Models\EmpAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UploadStoreTest extends TestCase
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

    public function test_store_requires_authentication(): void
    {
        $file = UploadedFile::fake()->create('test.csv', 100);

        $response = $this->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(401);
    }

    public function test_store_requires_file(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_store_rejects_invalid_file_type(): void
    {
        $file = UploadedFile::fake()->create('test.pdf', 100);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(422);
    }

    public function test_store_processes_valid_csv(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'test.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,John,Doe,100.50\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => ['id', 'filename', 'status'],
                'meta' => ['created', 'failed'],
            ]);

        $this->assertDatabaseHas('uploads', [
            'original_filename' => 'test.csv',
            'status' => 'completed',
        ]);
    }

    public function test_store_accepts_invalid_iban_format_for_later_validation(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'test.csv',
            "iban,first_name,last_name,amount\nINVALID_IBAN,John,Doe,100\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(201);
        $this->assertEquals(1, Debtor::count());
        $this->assertEquals(Debtor::VALIDATION_PENDING, Debtor::first()->validation_status);
    }

    public function test_store_rejects_missing_amount_column(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'test.csv',
            "iban,first_name,last_name\nDE89370400440532013000,John,Doe\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.0', 'Missing required header: amount.');
    }

    public function test_store_processes_multiple_rows(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'test.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,John,Doe,100\nFR7630006000011234567890189,Jane,Smith,200\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(201)
            ->assertJsonPath('meta.created', 2);

        $this->assertEquals(2, Debtor::count());
    }

    public function test_store_saves_raw_data(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'test.csv',
            "iban,first_name,last_name,amount,custom_field\nDE89370400440532013000,John,Doe,100,custom_value\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(201);

        $debtor = Debtor::first();
        $this->assertNotNull($debtor->raw_data);
        $this->assertEquals('custom_value', $debtor->raw_data['custom_field']);
    }

    public function test_store_handles_european_amount_format(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'test.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,John,Doe,1.234,56\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(201);
    }

    public function test_store_saves_headers_to_upload(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'test.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,John,Doe,100\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(201);

        $upload = Upload::first();
        $this->assertNotNull($upload->headers);
        $this->assertContains('iban', $upload->headers);
    }

    public function test_store_queues_large_file_async(): void
    {
        Queue::fake();

        $header = "iban,first_name,last_name,amount\n";
        $rows = '';
        for ($i = 0; $i < 150; $i++) {
            $rows .= "DE89370400440532013000,User{$i},Last{$i}," . ($i + 1) . "\n";
        }

        $file = UploadedFile::fake()->createWithContent('large.csv', $header . $rows);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(202)
            ->assertJsonPath('meta.queued', true)
            ->assertJsonPath('meta.message', 'File queued for processing. Check status for updates.');
    }

    public function test_store_async_flag_forces_queuing(): void
    {
        Queue::fake();

        $file = UploadedFile::fake()->createWithContent(
            'small.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,John,Doe,100\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', [
                'file' => $file,
                'async' => true,
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('meta.queued', true);
    }

    public function test_store_sync_response_includes_queued_false(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'sync.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,John,Doe,100\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(201)
            ->assertJsonPath('meta.queued', false);
    }

    public function test_store_defaults_billing_model_to_legacy(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'legacy.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,John,Doe,100\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(201);

        $upload = Upload::where('original_filename', 'legacy.csv')->first();
        $this->assertEquals('legacy', $upload->billing_model);
    }

    public function test_store_accepts_flywheel_billing_model(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'flywheel.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,John,Doe,5\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', [
                'file' => $file,
                'billing_model' => 'flywheel',
            ]);

        $response->assertSuccessful();

        $upload = Upload::where('original_filename', 'flywheel.csv')->first();
        $this->assertEquals('flywheel', $upload->billing_model);
    }

    public function test_store_accepts_recovery_billing_model(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'recovery.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,John,Doe,500\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', [
                'file' => $file,
                'billing_model' => 'recovery',
            ]);

        $response->assertSuccessful();

        $upload = Upload::where('original_filename', 'recovery.csv')->first();
        $this->assertEquals('recovery', $upload->billing_model);
    }

    public function test_store_assigns_explicit_emp_account(): void
    {
        $account = EmpAccount::factory()->create();

        $file = UploadedFile::fake()->createWithContent(
            'emp.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,John,Doe,100\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', [
                'file' => $file,
                'emp_account_id' => $account->id,
            ]);

        $response->assertSuccessful();

        $upload = Upload::where('original_filename', 'emp.csv')->first();
        $this->assertEquals($account->id, $upload->emp_account_id);
    }

    public function test_store_persists_is_30d_cool_flag(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'cool.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,John,Doe,100\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', [
                'file' => $file,
                'is_30d_cool' => false,
            ]);

        $response->assertSuccessful();

        $upload = Upload::where('original_filename', 'cool.csv')->first();
        $this->assertFalse($upload->is_30d_cool);
    }

    public function test_store_persists_skip_chargeback_check_flag(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'skip_cb.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,John,Doe,100\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', [
                'file' => $file,
                'skip_chargeback_check' => true,
            ]);

        $response->assertSuccessful();

        $upload = Upload::where('original_filename', 'skip_cb.csv')->first();
        $this->assertTrue($upload->skip_chargeback_check);
    }

    public function test_store_persists_global_lock_in_meta(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'lock.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,John,Doe,100\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', [
                'file' => $file,
                'apply_global_lock' => true,
            ]);

        $response->assertSuccessful();

        $upload = Upload::where('original_filename', 'lock.csv')->first();
        $this->assertTrue($upload->meta['apply_global_lock']);
    }

    public function test_store_sync_response_includes_error_details(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'errors.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,John,Doe,100\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => ['id'],
                'meta' => ['queued', 'created', 'failed', 'skipped', 'errors'],
            ]);
    }

    public function test_store_sync_response_errors_capped_at_10(): void
    {
        // This tests the array_slice($result['errors'], 0, 10) in the controller
        $file = UploadedFile::fake()->createWithContent(
            'test.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,John,Doe,100\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(201);

        $errors = $response->json('meta.errors');
        $this->assertIsArray($errors);
        $this->assertLessThanOrEqual(10, count($errors));
    }

    public function test_store_records_uploading_user(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'tracked.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,John,Doe,100\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertSuccessful();

        $upload = Upload::where('original_filename', 'tracked.csv')->first();
        $this->assertEquals($this->user->id, $upload->uploaded_by);
    }

    public function test_store_records_file_size_and_mime(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'sized.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,John,Doe,100\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertSuccessful();

        $upload = Upload::where('original_filename', 'sized.csv')->first();
        $this->assertGreaterThan(0, $upload->file_size);
        $this->assertNotNull($upload->mime_type);
    }

    public function test_store_saves_column_mapping(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'mapped.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,John,Doe,100\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertSuccessful();

        $upload = Upload::where('original_filename', 'mapped.csv')->first();
        $this->assertIsArray($upload->column_mapping);
        $this->assertEquals('iban', $upload->column_mapping['iban']);
        $this->assertEquals('amount', $upload->column_mapping['amount']);
    }

    public function test_store_rejects_empty_file(): void
    {
        $file = UploadedFile::fake()->createWithContent('empty.csv', '');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(422);
    }

    public function test_store_rejects_headers_only_file(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'headers.csv',
            "iban,first_name,last_name,amount\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.0', 'File has headers but no data rows.');
    }
}
