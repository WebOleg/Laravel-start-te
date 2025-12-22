<?php

namespace Tests\Feature;

use App\Models\Debtor;
use App\Models\Upload;
use App\Models\VopLog;
use App\Services\VopVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VopVerificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private VopVerificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.iban.mock' => true]);
        $this->service = app(VopVerificationService::class);
    }

    public function test_can_verify_valid_debtor(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => 'DE89370400440532013000',
            'iban_hash' => hash('sha256', 'DE89370400440532013000'),
            'iban_valid' => true,
            'validation_status' => Debtor::VALIDATION_VALID,
            'first_name' => 'Max',
            'last_name' => 'Mustermann',
        ]);

        $vopLog = $this->service->verify($debtor);

        $this->assertNotNull($vopLog);
        $this->assertEquals($debtor->id, $vopLog->debtor_id);
        $this->assertNotNull($vopLog->bic);
        $this->assertGreaterThan(0, $vopLog->vop_score);
    }

    public function test_cannot_verify_invalid_debtor(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_INVALID,
        ]);

        $this->assertFalse($this->service->canVerify($debtor));
    }


    public function test_cache_hit_returns_existing_voplog(): void
    {
        $upload = Upload::factory()->create();
        $iban = 'NL91ABNA0417164300';
        $ibanHash = hash('sha256', $iban);

        $debtor1 = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => $iban,
            'iban_hash' => $ibanHash,
            'iban_valid' => true,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        $vopLog1 = $this->service->verify($debtor1);

        $debtor2 = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => $iban,
            'iban_hash' => $ibanHash,
            'iban_valid' => true,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        $vopLog2 = $this->service->verify($debtor2);

        $this->assertNotNull($vopLog1);
        $this->assertNotNull($vopLog2);
        $this->assertEquals($vopLog1->bic, $vopLog2->bic);
        $this->assertEquals($vopLog1->vop_score, $vopLog2->vop_score);
        $this->assertArrayHasKey('cached_from', $vopLog2->meta);
    }

    public function test_force_refresh_ignores_cache(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => 'ES9121000418450200051332',
            'iban_hash' => hash('sha256', 'ES9121000418450200051332'),
            'iban_valid' => true,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        $vopLog1 = $this->service->verify($debtor);
        $vopLog2 = $this->service->verify($debtor, forceRefresh: true);

        $this->assertNotEquals($vopLog1->id, $vopLog2->id);
    }

    public function test_get_upload_stats(): void
    {
        $upload = Upload::factory()->create();

        Debtor::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'iban_valid' => true,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        VopLog::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'result' => VopLog::RESULT_VERIFIED,
        ]);

        $stats = $this->service->getUploadStats($upload->id);

        $this->assertEquals(3, $stats['total_eligible']);
        $this->assertEquals(2, $stats['verified']);
        $this->assertEquals(1, $stats['pending']);
    }
}
