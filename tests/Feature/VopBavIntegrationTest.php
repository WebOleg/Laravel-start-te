<?php
/**
 * Tests for BAV (Bank Account Verification) integration in VOP scoring.
 */
namespace Tests\Feature;

use App\Models\Debtor;
use App\Models\Upload;
use App\Models\VopLog;
use App\Services\VopScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VopBavIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private VopScoringService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.iban.mock' => true]);
        config(['services.iban.bav_enabled' => true]);
        config(['services.iban.bav_sampling_percentage' => 100]);
        config(['services.iban.bav_daily_limit' => 1000]);
        $this->service = app(VopScoringService::class);
    }

    public function test_bav_selected_debtor_gets_name_match_score(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => 'DE89370400440532013000',
            'iban_valid' => true,
            'validation_status' => Debtor::VALIDATION_VALID,
            'first_name' => 'Max',
            'last_name' => 'Mustermann',
            'bav_selected' => true,
        ]);

        $vopLog = $this->service->score($debtor);

        $this->assertTrue($vopLog->bav_verified);
        $this->assertNotNull($vopLog->name_match);
        $this->assertEquals(VopLog::NAME_MATCH_YES, $vopLog->name_match);
        $this->assertEquals(100, $vopLog->name_match_score);
        $this->assertEquals(100, $vopLog->vop_score);
    }

    public function test_non_bav_selected_debtor_skips_name_match(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => 'DE89370400440532013000',
            'iban_valid' => true,
            'validation_status' => Debtor::VALIDATION_VALID,
            'bav_selected' => false,
        ]);

        $vopLog = $this->service->score($debtor);

        $this->assertFalse($vopLog->bav_verified);
        $this->assertNull($vopLog->name_match);
        $this->assertNull($vopLog->name_match_score);
    }

    public function test_partial_name_match_gives_partial_score(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => 'LT601010012345678901',
            'iban_valid' => true,
            'validation_status' => Debtor::VALIDATION_VALID,
            'first_name' => 'Max',
            'last_name' => 'Mustermann',
            'bav_selected' => true,
        ]);

        $vopLog = $this->service->score($debtor);

        $this->assertTrue($vopLog->bav_verified);
        $this->assertEquals(VopLog::NAME_MATCH_PARTIAL, $vopLog->name_match);
        $this->assertEquals(70, $vopLog->name_match_score);
    }

    public function test_name_mismatch_sets_vop_match_false(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => 'ES9121000418450200051332',
            'iban_valid' => true,
            'validation_status' => Debtor::VALIDATION_VALID,
            'first_name' => 'Wrong',
            'last_name' => 'Name',
            'bav_selected' => true,
        ]);

        $vopLog = $this->service->score($debtor);

        $this->assertTrue($vopLog->bav_verified);
        $this->assertEquals(VopLog::NAME_MATCH_NO, $vopLog->name_match);

        $debtor->refresh();
        $this->assertFalse($debtor->vop_match);
        $this->assertEquals(Debtor::VOP_VERIFIED, $debtor->vop_status);
    }

    public function test_debtor_status_updated_after_scoring(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => 'DE89370400440532013000',
            'iban_valid' => true,
            'validation_status' => Debtor::VALIDATION_VALID,
            'vop_status' => Debtor::VOP_PENDING,
            'bav_selected' => true,
        ]);

        $this->service->score($debtor);
        $debtor->refresh();

        $this->assertEquals(Debtor::VOP_VERIFIED, $debtor->vop_status);
        $this->assertNotNull($debtor->vop_verified_at);
        $this->assertTrue($debtor->vop_match);
    }

    public function test_unsupported_country_skips_bav(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => 'GB82WEST12345698765432',
            'iban_valid' => true,
            'validation_status' => Debtor::VALIDATION_VALID,
            'bav_selected' => true,
        ]);

        $vopLog = $this->service->score($debtor);

        $this->assertFalse($vopLog->bav_verified);
        $this->assertNull($vopLog->name_match);
    }

    public function test_score_calculation_with_full_bav(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => 'DE89370400440532013000',
            'iban_valid' => true,
            'validation_status' => Debtor::VALIDATION_VALID,
            'bav_selected' => true,
        ]);

        $vopLog = $this->service->score($debtor);

        $this->assertEquals(100, $vopLog->vop_score);
        $this->assertEquals(VopLog::RESULT_VERIFIED, $vopLog->result);
    }

    public function test_uses_account_holder_from_meta_if_available(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => 'DE89370400440532013000',
            'iban_valid' => true,
            'validation_status' => Debtor::VALIDATION_VALID,
            'first_name' => 'Different',
            'last_name' => 'Name',
            'meta' => ['account_holder' => 'Max Mustermann'],
            'bav_selected' => true,
        ]);

        $nameForBav = $debtor->getNameForBav();

        $this->assertEquals('Max Mustermann', $nameForBav);
    }
}
