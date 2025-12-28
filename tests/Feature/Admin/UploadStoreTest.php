<?php

namespace Tests\Feature\Admin;

use App\Models\Debtor;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
        // Pre-validation no longer checks IBAN format
        // Validation happens in Stage B
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
            ->assertJsonPath('errors.0', 'Missing required column: amount (amount, sum, total, or price).');
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
}
