<?php
/**
 * Unit tests for VopScoringService.
 */
namespace Tests\Unit\Services;

use App\Models\Debtor;
use App\Models\Upload;
use App\Models\VopLog;
use App\Services\VopScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VopScoringServiceTest extends TestCase
{
    use RefreshDatabase;

    private VopScoringService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.iban.mock' => true]);
        $this->service = app(VopScoringService::class);
    }

    private function createDebtor(string $iban = 'DE89370400440532013000'): Debtor
    {
        $upload = Upload::factory()->create();
        return Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => $iban,
        ]);
    }

    public function test_score_creates_vop_log(): void
    {
        $debtor = $this->createDebtor();

        $vopLog = $this->service->score($debtor);

        $this->assertInstanceOf(VopLog::class, $vopLog);
        $this->assertEquals($debtor->id, $vopLog->debtor_id);
        $this->assertDatabaseHas('vop_logs', ['debtor_id' => $debtor->id]);
    }

    public function test_score_valid_german_iban_high_score(): void
    {
        $debtor = $this->createDebtor('DE89370400440532013000');

        $vopLog = $this->service->score($debtor);

        $this->assertGreaterThanOrEqual(85, $vopLog->vop_score);
        $this->assertEquals(VopLog::RESULT_VERIFIED, $vopLog->result);
        $this->assertTrue($vopLog->iban_valid);
        $this->assertTrue($vopLog->bank_identified);
    }

    public function test_score_valid_spanish_iban(): void
    {
        $debtor = $this->createDebtor('ES9121000418450200051332');

        $vopLog = $this->service->score($debtor);

        $this->assertGreaterThanOrEqual(60, $vopLog->vop_score);
        $this->assertTrue($vopLog->iban_valid);
        $this->assertEquals('ES', $vopLog->country);
    }

    public function test_score_invalid_iban_low_score(): void
    {
        $debtor = $this->createDebtor('INVALID123');

        $vopLog = $this->service->score($debtor);

        $this->assertEquals(0, $vopLog->vop_score);
        $this->assertEquals(VopLog::RESULT_REJECTED, $vopLog->result);
        $this->assertFalse($vopLog->iban_valid);
        $this->assertFalse($vopLog->bank_identified);
    }

    public function test_score_masks_iban(): void
    {
        $debtor = $this->createDebtor('DE89370400440532013000');

        $vopLog = $this->service->score($debtor);

        $this->assertStringContainsString('****', $vopLog->iban_masked);
        $this->assertStringNotContainsString('0532013000', $vopLog->iban_masked);
    }

    public function test_score_stores_bank_info(): void
    {
        $debtor = $this->createDebtor('DE89370400440532013000');

        $vopLog = $this->service->score($debtor);

        $this->assertNotNull($vopLog->bank_name);
        $this->assertNotNull($vopLog->bic);
    }

    public function test_score_stores_meta_breakdown(): void
    {
        $debtor = $this->createDebtor('DE89370400440532013000');

        $vopLog = $this->service->score($debtor);

        $this->assertIsArray($vopLog->meta);
        $this->assertArrayHasKey('iban_valid', $vopLog->meta);
        $this->assertArrayHasKey('country_supported', $vopLog->meta);
        $this->assertArrayHasKey('sepa_sdd', $vopLog->meta);
    }

    public function test_calculate_returns_breakdown(): void
    {
        $debtor = $this->createDebtor('DE89370400440532013000');

        $result = $this->service->calculate($debtor);

        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('result', $result);
        $this->assertArrayHasKey('breakdown', $result);
        $this->assertArrayHasKey('iban_valid', $result['breakdown']);
        $this->assertArrayHasKey('bank_identified', $result['breakdown']);
        $this->assertArrayHasKey('sepa_sdd', $result['breakdown']);
        $this->assertArrayHasKey('country_supported', $result['breakdown']);
    }

    public function test_calculate_does_not_create_vop_log(): void
    {
        $debtor = $this->createDebtor();

        $this->service->calculate($debtor);

        $this->assertDatabaseMissing('vop_logs', ['debtor_id' => $debtor->id]);
    }

    public function test_result_thresholds(): void
    {
        $thresholds = VopScoringService::getResultThresholds();

        $this->assertArrayHasKey(VopLog::RESULT_VERIFIED, $thresholds);
        $this->assertArrayHasKey(VopLog::RESULT_LIKELY_VERIFIED, $thresholds);
        $this->assertArrayHasKey(VopLog::RESULT_INCONCLUSIVE, $thresholds);
        $this->assertArrayHasKey(VopLog::RESULT_MISMATCH, $thresholds);
        $this->assertArrayHasKey(VopLog::RESULT_REJECTED, $thresholds);
    }

    public function test_score_breakdown_totals_100(): void
    {
        $breakdown = VopScoringService::getScoreBreakdown();

        $this->assertEquals(100, $breakdown['total']);
    }

    public function test_non_sepa_country_lower_score(): void
    {
        $debtor = $this->createDebtor('US12345678901234567890');

        $vopLog = $this->service->score($debtor);

        $this->assertFalse($vopLog->meta['country_supported'] ?? true);
    }
}
