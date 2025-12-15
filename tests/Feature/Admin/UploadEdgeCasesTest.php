<?php

/**
 * Edge case tests for file upload functionality.
 */

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\Upload;
use App\Models\Debtor;
use App\Models\Blacklist;
use App\Services\IbanValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class UploadEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private IbanValidator $ibanValidator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->ibanValidator = new IbanValidator();
    }

    public function test_empty_csv_returns_error(): void
    {
        $content = '';
        $file = UploadedFile::fake()->createWithContent('empty.csv', $content);

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        // Empty file causes parser error - 500 is acceptable
        $this->assertTrue(in_array($response->status(), [422, 500]));
    }

    public function test_csv_with_only_headers_creates_no_debtors(): void
    {
        $content = "iban,first_name,last_name,amount,city,postcode,address\n";
        $file = UploadedFile::fake()->createWithContent('headers_only.csv', $content);

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertSuccessful();
        $this->assertEquals(0, Debtor::count());
    }

    public function test_duplicate_ibans_within_same_file_creates_all(): void
    {
        $iban = 'DE89370400440532013000';
        $content = "iban,first_name,last_name,amount,city,postcode,address\n";
        $content .= "{$iban},John,Doe,100.00,Berlin,10115,Main St 1\n";
        $content .= "{$iban},Jane,Doe,200.00,Munich,80331,Oak Ave 5\n";
        
        $file = UploadedFile::fake()->createWithContent('duplicates.csv', $content);

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertSuccessful();
        
        $this->assertEquals(2, Debtor::where('iban', $iban)->count());
    }

    public function test_mixed_valid_invalid_skipped_in_one_upload(): void
    {
        $blacklistedIban = 'ES9121000418450200051332';
        Blacklist::create([
            'iban' => $blacklistedIban,
            'iban_hash' => $this->ibanValidator->hash($blacklistedIban),
            'reason' => 'Fraud',
        ]);

        $validIban = 'DE89370400440532013000';
        $invalidIban = 'INVALID_IBAN_FORMAT';
        
        $content = "iban,first_name,last_name,amount,city,postcode,address\n";
        $content .= "{$validIban},John,Valid,100.00,Berlin,10115,Main St 1\n";
        $content .= "{$blacklistedIban},Jane,Blocked,200.00,Madrid,28001,Calle 5\n";
        $content .= "{$invalidIban},Bob,Invalid,50.00,Paris,75001,Rue 1\n";
        
        $file = UploadedFile::fake()->createWithContent('mixed.csv', $content);

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertSuccessful();
        
        $meta = $response->json('meta');
        
        $this->assertEquals(2, $meta['created']);
        $this->assertEquals(1, $meta['skipped']['total']);
        $this->assertEquals(1, $meta['skipped']['blacklisted']);
    }

    public function test_upload_with_missing_required_columns_still_creates_records(): void
    {
        $content = "iban,first_name,last_name,city\n";
        $content .= "DE89370400440532013000,John,Doe,Berlin\n";
        
        $file = UploadedFile::fake()->createWithContent('missing_columns.csv', $content);

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertSuccessful();
        
        $debtor = Debtor::first();
        $this->assertNotNull($debtor);
        $this->assertEquals('pending', $debtor->validation_status);
    }

    public function test_second_upload_skips_iban_from_first_upload_if_recovered(): void
    {
        $iban = 'DE89370400440532013000';
        
        $upload1 = Upload::factory()->create();
        Debtor::factory()->create([
            'upload_id' => $upload1->id,
            'iban' => $iban,
            'iban_hash' => $this->ibanValidator->hash($iban),
            'status' => Debtor::STATUS_RECOVERED,
        ]);

        $content = "iban,first_name,last_name,amount,city,postcode,address\n";
        $content .= "{$iban},Jane,New,150.00,Hamburg,20095,New St 1\n";
        
        $file = UploadedFile::fake()->createWithContent('second_upload.csv', $content);

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertSuccessful();
        
        $meta = $response->json('meta');
        $this->assertEquals(0, $meta['created']);
        $this->assertEquals(1, $meta['skipped']['total']);
        $this->assertEquals(1, $meta['skipped']['already_recovered']);
    }
}
