<?php

namespace Tests\Feature\Admin;

use App\Models\Debtor;
use App\Models\Upload;
use App\Models\User;
use App\Models\Blacklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
        // Pre-validation no longer rejects duplicates
        // DeduplicationService handles this in Stage A
        $iban = 'DE89370400440532013000';

        $file = UploadedFile::fake()->createWithContent(
            'duplicates.csv',
            "iban,first_name,last_name,amount\n{$iban},John,Doe,100\n{$iban},Jane,Doe,200\n"
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertSuccessful();
        // Both records created - deduplication happens against existing data, not within file
        $this->assertEquals(2, Debtor::count());
    }

    public function test_invalid_iban_format_creates_record_with_pending_validation(): void
    {
        // Pre-validation no longer checks IBAN format
        // Validation happens in Stage B
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
}
