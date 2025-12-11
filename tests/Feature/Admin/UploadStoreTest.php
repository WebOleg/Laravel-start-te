<?php

/**
 * Feature tests for Admin Upload store endpoint.
 * 
 * Stage A: Upload accepts ALL rows and saves with validation_status=pending
 * Stage B: Validation runs separately and updates status to valid/invalid
 */

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\Upload;
use App\Models\Debtor;
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

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_store_processes_valid_csv(): void
    {
        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,150.00";
        $file = UploadedFile::fake()->createWithContent('debtors.csv', $content);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(201)
            ->assertJsonPath('meta.created', 1)
            ->assertJsonPath('meta.failed', 0);

        $this->assertDatabaseHas('uploads', [
            'original_filename' => 'debtors.csv',
            'status' => Upload::STATUS_COMPLETED,
        ]);

        $this->assertDatabaseHas('debtors', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban' => 'DE89370400440532013000',
        ]);
    }

    public function test_store_accepts_invalid_iban_for_later_validation(): void
    {
        // Stage A: Upload accepts ALL rows, validation happens in Stage B
        $content = "first_name,last_name,iban,amount\nJohn,Doe,INVALID123,150.00";
        $file = UploadedFile::fake()->createWithContent('debtors.csv', $content);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(201)
            ->assertJsonPath('meta.created', 1);

        // Record is saved with validation_status = pending
        $this->assertDatabaseHas('debtors', [
            'first_name' => 'John',
            'validation_status' => Debtor::VALIDATION_PENDING,
        ]);
    }

    public function test_store_accepts_missing_amount_for_later_validation(): void
    {
        // Stage A: Upload accepts ALL rows, validation happens in Stage B
        $content = "first_name,last_name,iban\nJohn,Doe,DE89370400440532013000";
        $file = UploadedFile::fake()->createWithContent('debtors.csv', $content);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(201)
            ->assertJsonPath('meta.created', 1);

        // Record is saved with validation_status = pending
        $this->assertDatabaseHas('debtors', [
            'first_name' => 'John',
            'validation_status' => Debtor::VALIDATION_PENDING,
        ]);
    }

    public function test_store_processes_multiple_rows(): void
    {
        $content = "first_name,last_name,iban,amount\n";
        $content .= "John,Doe,DE89370400440532013000,100.00\n";
        $content .= "Jane,Smith,ES9121000418450200051332,200.00\n";
        $content .= "Hans,Mueller,FR1420041010050500013M02606,300.00";

        $file = UploadedFile::fake()->createWithContent('batch.csv', $content);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(201)
            ->assertJsonPath('meta.created', 3)
            ->assertJsonPath('meta.failed', 0);

        $this->assertDatabaseCount('debtors', 3);
    }

    public function test_store_saves_raw_data(): void
    {
        $content = "first_name,last_name,iban,amount,custom_field\nJohn,Doe,DE89370400440532013000,100,extra_value";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(201);

        $debtor = Debtor::first();
        $this->assertNotNull($debtor->raw_data);
        $this->assertEquals('extra_value', $debtor->raw_data['custom_field']);
    }

    public function test_store_handles_european_amount_format(): void
    {
        $content = "first_name;last_name;iban;amount\nJohn;Doe;DE89370400440532013000;1.234,56";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(201);

        $debtor = Debtor::first();
        $this->assertEquals(1234.56, $debtor->amount);
    }

    public function test_store_saves_headers_to_upload(): void
    {
        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads', ['file' => $file]);

        $response->assertStatus(201);

        $upload = Upload::first();
        $this->assertIsArray($upload->headers);
        $this->assertContains('first_name', $upload->headers);
        $this->assertContains('iban', $upload->headers);
    }
}
