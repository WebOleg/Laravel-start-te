<?php

/**
 * Tests for blacklist integration in upload flow.
 */

namespace Tests\Feature\Admin;

use App\Models\User;
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

    public function test_upload_rejects_blacklisted_iban(): void
    {
        $blacklistService = new BlacklistService(new IbanValidator());
        $blacklistService->add('DE89370400440532013000', 'Fraud');

        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(201);

        $uploadId = $response->json('data.id');
        $this->assertDatabaseHas('uploads', [
            'id' => $uploadId,
            'failed_records' => 1,
        ]);
    }

    public function test_upload_accepts_clean_iban(): void
    {
        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00";
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
}
