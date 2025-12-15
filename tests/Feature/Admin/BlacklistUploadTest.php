<?php

/**
 * Tests for blacklist integration in upload flow.
 * 
 * Blacklisted IBANs are now SKIPPED during upload (not created).
 */

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\Debtor;
use App\Services\BlacklistService;
use App\Services\DeduplicationService;
use App\Services\IbanValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class BlacklistUploadTest extends TestCase
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

    public function test_upload_skips_blacklisted_iban(): void
    {
        $blacklistService = new BlacklistService(new IbanValidator());
        $blacklistService->add('DE89370400440532013000', 'Fraud');

        $content = "first_name,last_name,iban,amount,city,postcode,address\n";
        $content .= "John,Doe,DE89370400440532013000,100.00,Berlin,10115,Main St 1";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(201);
        
        $this->assertEquals(0, $response->json('meta.created'));
        $this->assertEquals(1, $response->json('meta.skipped.total'));
        $this->assertEquals(1, $response->json('meta.skipped.blacklisted'));

        $this->assertDatabaseMissing('debtors', [
            'iban' => 'DE89370400440532013000',
        ]);
    }

    public function test_upload_accepts_clean_iban(): void
    {
        $content = "first_name,last_name,iban,amount,city,postcode,address\n";
        $content .= "John,Doe,DE89370400440532013000,100.00,Berlin,10115,Main St 1";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(201);

        $uploadId = $response->json('data.id');
        $this->assertEquals(1, $response->json('meta.created'));
        $this->assertEquals(0, $response->json('meta.skipped.total'));
        
        $this->assertDatabaseHas('debtors', [
            'upload_id' => $uploadId,
            'iban' => 'DE89370400440532013000',
        ]);
    }

    public function test_upload_returns_skipped_rows_details(): void
    {
        $blacklistService = new BlacklistService(new IbanValidator());
        $blacklistService->add('DE89370400440532013000', 'Fraud');

        $content = "first_name,last_name,iban,amount,city,postcode,address\n";
        $content .= "John,Doe,DE89370400440532013000,100.00,Berlin,10115,Main St 1\n";
        $content .= "Jane,Smith,ES9121000418450200051332,200.00,Madrid,28001,Calle Mayor 5";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(201);
        
        $this->assertEquals(1, $response->json('meta.created'));
        $this->assertEquals(1, $response->json('meta.skipped.total'));
        $this->assertEquals(1, $response->json('meta.skipped.blacklisted'));

        $this->assertDatabaseCount('debtors', 1);
        $this->assertDatabaseHas('debtors', [
            'iban' => 'ES9121000418450200051332',
        ]);
    }

    public function test_upload_skipped_info_in_resource(): void
    {
        $blacklistService = new BlacklistService(new IbanValidator());
        $blacklistService->add('DE89370400440532013000', 'Fraud');

        $content = "first_name,last_name,iban,amount\n";
        $content .= "John,Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(201);
        
        $response->assertJsonStructure([
            'data' => [
                'id',
                'skipped',
                'skipped_rows',
            ],
            'meta' => [
                'created',
                'failed',
                'skipped',
            ],
        ]);

        $skippedRows = $response->json('data.skipped_rows');
        $this->assertNotEmpty($skippedRows);
        $this->assertEquals(DeduplicationService::SKIP_BLACKLISTED, $skippedRows[0]['reason']);
    }
}
