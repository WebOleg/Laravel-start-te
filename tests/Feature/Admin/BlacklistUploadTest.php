<?php

/**
 * Tests for blacklist integration in upload flow.
 * 
 * Stage A: Upload accepts ALL rows
 * Stage B: Validation checks blacklist and marks as invalid
 */

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\Debtor;
use App\Services\BlacklistService;
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

    public function test_upload_accepts_blacklisted_iban_then_validation_marks_invalid(): void
    {
        // Add to blacklist
        $blacklistService = new BlacklistService(new IbanValidator());
        $blacklistService->add('DE89370400440532013000', 'Fraud');

        // Stage A: Upload accepts the row
        $content = "first_name,last_name,iban,amount,city,postcode,address\n";
        $content .= "John,Doe,DE89370400440532013000,100.00,Berlin,10115,Main St 1";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(201);
        $uploadId = $response->json('data.id');

        // Record is created with pending status
        $this->assertDatabaseHas('debtors', [
            'upload_id' => $uploadId,
            'iban' => 'DE89370400440532013000',
            'validation_status' => Debtor::VALIDATION_PENDING,
        ]);

        // Stage B: Run validation
        $validateResponse = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$uploadId}/validate");

        $validateResponse->assertStatus(200);

        // Record is now marked as invalid with blacklist error
        $debtor = Debtor::where('upload_id', $uploadId)->first();
        $this->assertEquals(Debtor::VALIDATION_INVALID, $debtor->validation_status);
        $this->assertNotNull($debtor->validation_errors);
        $this->assertTrue(
            collect($debtor->validation_errors)->contains(fn($e) => str_contains(strtolower($e), 'blacklist'))
        );
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
        $this->assertDatabaseHas('uploads', [
            'id' => $uploadId,
            'processed_records' => 1,
            'failed_records' => 0,
        ]);
    }

    public function test_validation_stats_counts_blacklisted(): void
    {
        // Add to blacklist
        $blacklistService = new BlacklistService(new IbanValidator());
        $blacklistService->add('DE89370400440532013000', 'Fraud');

        // Upload with all required fields
        $content = "first_name,last_name,iban,amount,city,postcode,address\n";
        $content .= "John,Doe,DE89370400440532013000,100.00,Berlin,10115,Main St 1\n";
        $content .= "Jane,Smith,ES9121000418450200051332,200.00,Madrid,28001,Calle Mayor 5";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $uploadId = $response->json('data.id');

        // Validate
        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$uploadId}/validate");

        // Check stats
        $statsResponse = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$uploadId}/validation-stats");

        $statsResponse->assertStatus(200);
        $this->assertEquals(1, $statsResponse->json('data.blacklisted'));
        $this->assertEquals(1, $statsResponse->json('data.valid'));
    }
}
