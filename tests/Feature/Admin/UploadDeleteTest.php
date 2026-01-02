<?php

namespace Tests\Feature\Admin;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadDeleteTest extends TestCase
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

    public function test_upload_destroy_hard_deletes_upload_when_no_debtors_exist(): void
    {
        $filePath = 'uploads/test_file_123.csv';
        Storage::disk('s3')->put($filePath, 'content');
        
        $upload = Upload::factory()->create([
            'file_path' => $filePath,
            'filename' => 'test_file_123.csv',
            'status' => Upload::STATUS_COMPLETED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
                            ->deleteJson('/api/admin/uploads/' . $upload->id);
        
        $response->assertStatus(200);
        $this->assertDatabaseMissing('uploads', ['id' => $upload->id]);
        Storage::disk('s3')->assertMissing($filePath);
    }

    public function test_upload_destroy_soft_deletes_upload_when_has_debtors_no_billing(): void
    {
        $upload = Upload::factory()->create([
            'status' => Upload::STATUS_COMPLETED,
        ]);
        $upload->debtors()->createMany([
            ['first_name' => 'John', 'last_name' => 'Doe', 'iban' => 'DE123456789', 'amount' => 9.99],
            ['first_name' => 'Jane', 'last_name' => 'Smith', 'iban' => 'DE987654321', 'amount' => 9.99],
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
                            ->deleteJson('/api/admin/uploads/' . $upload->id);
        $response->assertStatus(200)
                    ->assertJsonPath('success', true)
                    ->assertJsonPath('message', 'Upload and associated debtors deleted successfully.');
        $this->assertSoftDeleted('uploads', ['id' => $upload->id]);
        $this->assertEquals(0, Debtor::where('upload_id', $upload->id)->withoutTrashed()->count());
    }

    public function test_upload_destroy_returns_403_when_has_billing_attempts(): void
    {
        $upload = Upload::factory()->create();
        $debtor = $upload->debtors()->create([
            'first_name'    => 'John',
            'last_name'     => 'Doe',
            'iban'          => 'DE123456789',
            'amount'        => 9.99,
        ]);
        $debtor->billingAttempts()->create([
            'upload_id'     => $upload->id,
            'reference'     => 'REF123',
            'status'        => BillingAttempt::STATUS_APPROVED,
            'transaction_id'=> 'TXN123',
            'amount'        => 9.99,
            'currency'      => 'EUR',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson('/api/admin/uploads/' . $upload->id);
        $response->assertStatus(403)
                    ->assertJsonPath('success', false)
                    ->assertJsonPath('message', 'Upload cannot be deleted as it has associated debtors.');
        $this->assertDatabaseHas('uploads', ['id' => $upload->id]);
    }

    public function test_upload_destroy_requires_authentication(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->deleteJson('/api/admin/uploads/' . $upload->id);
        $response->assertStatus(401);
    }

    public function test_upload_destroy_returns_404_for_nonexistent_upload(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
                            ->deleteJson('/api/admin/uploads/99999');
        $response->assertStatus(404);
    }


    public function test_upload_destroy_removes_file_from_storage(): void
    {
        $filePath = 'uploads/test_file.csv';
        Storage::disk('s3')->put($filePath, 'test content');
        
        $upload = Upload::factory()->create([
            'file_path' => $filePath,
            'filename' => 'test_file.csv',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
                ->deleteJson('/api/admin/uploads/' . $upload->id);
        
        Storage::disk('s3')->assertMissing($filePath);
    }

    public function test_upload_destroy_deletes_all_associated_debtors(): void
    {
        $upload = Upload::factory()->create();
        $upload->debtors()->createMany([
            ['first_name' => 'John', 'last_name' => 'Doe', 'iban' => 'DE1', 'amount' => 9.99],
            ['first_name' => 'Jane', 'last_name' => 'Smith', 'iban' => 'DE2', 'amount' => 9.99],
            ['first_name' => 'Bob', 'last_name' => 'Johnson', 'iban' => 'DE3', 'amount' => 9.99],
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
                ->deleteJson('/api/admin/uploads/' . $upload->id);
        $this->assertEquals(0, Debtor::where('upload_id', $upload->id)->withoutTrashed()->count());
    }

    public function test_upload_destroy_returns_correct_json_structure(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
                            ->deleteJson('/api/admin/uploads/' . $upload->id);
        $response->assertStatus(200)
                    ->assertJsonStructure([
                        'success',
                        'message',
                    ])
                    ->assertJsonIsObject();
    }
}
