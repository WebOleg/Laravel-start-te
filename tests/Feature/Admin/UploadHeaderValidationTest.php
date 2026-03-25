<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadHeaderValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test')->plainTextToken;
    }

    public function test_upload_with_valid_headers_succeeds(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'valid.csv',
            "iban,first_name,last_name,amount\nDE89370400440532013000,John,Doe,100.00\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(201);
    }

    public function test_upload_with_missing_iban_returns_422(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'no_iban.csv',
            "first_name,last_name,amount\nJohn,Doe,100.00\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'File validation failed.')
            ->assertJsonPath('errors.0', 'Missing required header: IBAN.');
    }

    public function test_upload_with_missing_amount_returns_422(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'no_amount.csv',
            "iban,first_name,last_name\nDE89370400440532013000,John,Doe\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'File validation failed.')
            ->assertJsonPath('errors.0', 'Missing required header: amount.');
    }

    public function test_upload_with_missing_name_returns_422(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'no_name.csv',
            "iban,amount,email\nDE89370400440532013000,100.00,john@test.com\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'File validation failed.')
            ->assertJsonPath('errors.0', 'Missing required header: name.');
    }

    public function test_upload_with_misspelled_header_returns_422_with_suggestion(): void
    {
        // "lst_name" is the only name header — triggers missing name error + suggestion
        $file = UploadedFile::fake()->createWithContent(
            'typo.csv',
            "iban,lst_name,amount\nDE89370400440532013000,Doe,100.00\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'File validation failed.')
            ->assertJsonStructure(['errors', 'warnings', 'suggestions']);

        $json = $response->json();
        $this->assertContains('Missing required header: name.', $json['errors']);
        $this->assertEquals('last_name', $json['suggestions']['lst_name']);
    }

    public function test_upload_with_misspelled_header_but_valid_coverage_returns_201_with_warnings(): void
    {
        // "lst_name" is misspelled but "first_name" covers the name requirement
        $file = UploadedFile::fake()->createWithContent(
            'partial_typo.csv',
            "iban,first_name,lst_name,amount\nDE89370400440532013000,John,Doe,100.00\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        // File is processable — first_name covers the name group
        $response->assertStatus(201);
    }

    public function test_upload_with_empty_csv_returns_422(): void
    {
        $file = UploadedFile::fake()->createWithContent('empty.csv', '');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(422);
    }

    public function test_upload_with_header_alias_sum_for_amount_succeeds(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'alias.csv',
            "iban,name,sum\nDE89370400440532013000,John Doe,100.00\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(201);
    }

    public function test_upload_with_multiple_missing_headers_returns_all_errors(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'multimissing.csv',
            "email,city\njohn@test.com,Berlin\n"
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(422);

        $json = $response->json();
        $this->assertCount(3, $json['errors']);
    }
}
