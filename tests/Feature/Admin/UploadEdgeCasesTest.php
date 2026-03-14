<?php

namespace Tests\Feature\Admin;

use App\Models\Debtor;
use App\Models\Upload;
use App\Models\User;
use App\Models\Blacklist;
use App\Models\EmpAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_empty_csv_returns_error(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'empty.csv',
            ''
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(422);
    }

    public function test_csv_with_only_headers_returns_error(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'headers_only.csv',
            "iban,first_name,last_name,amount\n"
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.0', 'File has headers but no data rows.');
    }

    public function test_duplicate_ibans_within_file_creates_both_records(): void
    {
        $iban = 'DE89370400440532013000';

        $file = UploadedFile::fake()->createWithContent(
            'duplicates.csv',
            "iban,first_name,last_name,amount\n{$iban},John,Doe,100\n{$iban},Jane,Doe,200\n"
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertSuccessful();
        $this->assertEquals(2, Debtor::count());
    }

    public function test_invalid_iban_format_creates_record_with_pending_validation(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'invalid_iban.csv',
            "iban,first_name,last_name,amount\nINVALID,John,Doe,100\n"
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertSuccessful();
        $this->assertEquals(1, Debtor::count());
        $this->assertEquals(Debtor::VALIDATION_PENDING, Debtor::first()->validation_status);
    }

    public function test_missing_required_columns_returns_error(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'missing_amount.csv',
            "iban,first_name,last_name\nDE89370400440532013000,John,Doe\n"
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.0', 'Missing required column: amount (amount, sum, total, or price).');
    }

    public function test_valid_file_processes_successfully(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'valid.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,John,Doe,100\nFR7630006000011234567890189,Jane,Smith,200\n"
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertSuccessful();
        $this->assertEquals(2, Debtor::count());
    }

    public function test_second_upload_skips_iban_from_first_upload_if_recovered(): void
    {
        $iban = 'DE89370400440532013000';

        $upload1 = Upload::factory()->create(['status' => Upload::STATUS_COMPLETED]);
        Debtor::factory()->create([
            'upload_id' => $upload1->id,
            'iban' => $iban,
            'iban_hash' => hash('sha256', $iban),
            'status' => Debtor::STATUS_RECOVERED,
        ]);

        $file = UploadedFile::fake()->createWithContent(
            'second.csv',
            "iban,first_name,last_name,amount\n{$iban},John,Doe,100\n"
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertSuccessful();
        $this->assertEquals(0, $response->json('meta.created'));
    }

    public function test_rejects_unsupported_file_type(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'data.json',
            '{"name": "test"}'
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(422);
    }

    public function test_rejects_file_exceeding_size_limit(): void
    {
        // Create a file larger than the upload limit
        $file = UploadedFile::fake()->create('huge.csv', 51200); // 50MB

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(422);
    }

    public function test_recognizes_sum_as_amount_column(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'alias.csv',
            "iban,first_name,last_name,sum\nDE89370400440532013000,John,Doe,150\n"
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertSuccessful();
        $this->assertEquals(1, Debtor::count());
    }

    public function test_recognizes_total_as_amount_column(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'total.csv',
            "iban,first_name,last_name,total\nDE89370400440532013000,John,Doe,200\n"
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertSuccessful();
        $this->assertEquals(1, Debtor::count());
    }

    public function test_recognizes_price_as_amount_column(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'price.csv',
            "iban,first_name,last_name,price\nDE89370400440532013000,John,Doe,99\n"
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertSuccessful();
        $this->assertEquals(1, Debtor::count());
    }

    public function test_recognizes_full_name_column(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'fullname.csv',
            "iban,full_name,amount\nDE89370400440532013000,John Doe,100\n"
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertSuccessful();
        $this->assertEquals(1, Debtor::count());
    }

    public function test_missing_iban_column_returns_error(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'no_iban.csv',
            "first_name,last_name,amount\nJohn,Doe,100\n"
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(422);
    }

    public function test_sync_response_includes_meta_counts(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'meta.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,John,Doe,100\nFR7630006000011234567890189,Jane,Smith,200\n"
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'meta' => ['queued', 'created', 'failed', 'skipped', 'errors'],
            ])
            ->assertJsonPath('meta.queued', false)
            ->assertJsonPath('meta.created', 2)
            ->assertJsonPath('meta.failed', 0);
    }

    public function test_large_csv_processes_async(): void
    {
        Queue::fake();

        $header = "iban,first_name,last_name,amount\n";
        $rows = '';
        for ($i = 0; $i < 150; $i++) {
            $rows .= "DE89370400440532013000,User{$i},Last{$i}," . ($i + 1) . "\n";
        }

        $file = UploadedFile::fake()->createWithContent('large.csv', $header . $rows);

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(202)
            ->assertJsonPath('meta.queued', true);
    }

    public function test_force_async_flag_queues_small_file(): void
    {
        Queue::fake();

        $file = UploadedFile::fake()->createWithContent(
            'small_async.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,John,Doe,100\n"
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', [
                'file' => $file,
                'async' => true,
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('meta.queued', true);
    }

    public function test_store_with_flywheel_billing_model(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'flywheel.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,John,Doe,5\n"
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', [
                'file' => $file,
                'billing_model' => 'flywheel',
            ]);

        $response->assertSuccessful();

        $upload = Upload::where('original_filename', 'flywheel.csv')->first();
        $this->assertEquals('flywheel', $upload->billing_model);
    }

    public function test_store_defaults_to_legacy_billing_model(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'default_model.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,John,Doe,100\n"
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertSuccessful();

        $upload = Upload::where('original_filename', 'default_model.csv')->first();
        $this->assertEquals('legacy', $upload->billing_model);
    }

    public function test_store_creates_upload_record_with_correct_filename(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'my_data_2024.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,John,Doe,100\n"
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertSuccessful();

        $this->assertDatabaseHas('uploads', [
            'original_filename' => 'my_data_2024.csv',
        ]);
    }

    public function test_store_tracks_uploading_user(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'tracked.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,John,Doe,100\n"
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertSuccessful();

        $upload = Upload::where('original_filename', 'tracked.csv')->first();
        $this->assertEquals($this->user->id, $upload->uploaded_by);
    }

    public function test_handles_csv_with_quoted_fields(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'quoted.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,\"John, Jr.\",\"O'Brien\",100\n"
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertSuccessful();
        $this->assertEquals(1, Debtor::count());
    }

    public function test_store_passes_is_30d_cool_flag(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'cool.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,John,Doe,100\n"
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', [
                'file' => $file,
                'is_30d_cool' => false,
            ]);

        $response->assertSuccessful();

        $upload = Upload::where('original_filename', 'cool.csv')->first();
        $this->assertFalse($upload->is_30d_cool);
    }

    public function test_store_passes_skip_chargeback_check_flag(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'skip_cb.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,John,Doe,100\n"
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', [
                'file' => $file,
                'skip_chargeback_check' => true,
            ]);

        $response->assertSuccessful();

        $upload = Upload::where('original_filename', 'skip_cb.csv')->first();
        $this->assertTrue($upload->skip_chargeback_check);
    }

    public function test_single_row_csv_processes_successfully(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'single.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,John,Doe,100\n"
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(201)
            ->assertJsonPath('meta.created', 1);

        $this->assertEquals(1, Debtor::count());
    }
}
