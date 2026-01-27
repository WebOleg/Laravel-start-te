<?php

/**
 * Unit tests for Debtor model scopes.
 */

namespace Tests\Unit\Models;

use App\Models\Debtor;
use App\Models\Upload;
use App\Models\VopLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebtorTest extends TestCase
{
    use RefreshDatabase;

    public function test_ready_for_sync_includes_debtors_without_vop_logs(): void
    {
        $upload = Upload::factory()->create();

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'status' => Debtor::STATUS_UPLOADED,
        ]);

        $readyForSync = Debtor::where('upload_id', $upload->id)->readyForSync()->get();

        $this->assertCount(1, $readyForSync);
        $this->assertEquals($debtor->id, $readyForSync->first()->id);
    }

    public function test_ready_for_sync_includes_vop_verified_debtors(): void
    {
        $upload = Upload::factory()->create();

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'status' => Debtor::STATUS_UPLOADED,
        ]);

        VopLog::factory()->verified()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $readyForSync = Debtor::where('upload_id', $upload->id)->readyForSync()->get();

        $this->assertCount(1, $readyForSync);
    }

    public function test_ready_for_sync_includes_vop_likely_verified_debtors(): void
    {
        $upload = Upload::factory()->create();

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'status' => Debtor::STATUS_UPLOADED,
        ]);

        VopLog::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'result' => VopLog::RESULT_LIKELY_VERIFIED,
            'vop_score' => 65,
            'iban_valid' => true,
            'bank_identified' => true,
        ]);

        $readyForSync = Debtor::where('upload_id', $upload->id)->readyForSync()->get();

        $this->assertCount(1, $readyForSync);
    }

    public function test_ready_for_sync_excludes_vop_mismatch_debtors(): void
    {
        $upload = Upload::factory()->create();

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'status' => Debtor::STATUS_UPLOADED,
        ]);

        VopLog::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'result' => VopLog::RESULT_MISMATCH,
            'vop_score' => 35,
        ]);

        $readyForSync = Debtor::where('upload_id', $upload->id)->readyForSync()->get();

        $this->assertCount(0, $readyForSync);
    }

    public function test_ready_for_sync_excludes_vop_rejected_debtors(): void
    {
        $upload = Upload::factory()->create();

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'status' => Debtor::STATUS_UPLOADED,
        ]);

        VopLog::factory()->rejected()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $readyForSync = Debtor::where('upload_id', $upload->id)->readyForSync()->get();

        $this->assertCount(0, $readyForSync);
    }

    public function test_ready_for_sync_excludes_vop_inconclusive_debtors(): void
    {
        $upload = Upload::factory()->create();

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'status' => Debtor::STATUS_UPLOADED,
        ]);

        VopLog::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'result' => VopLog::RESULT_INCONCLUSIVE,
            'vop_score' => 45,
        ]);

        $readyForSync = Debtor::where('upload_id', $upload->id)->readyForSync()->get();

        $this->assertCount(0, $readyForSync);
    }

    public function test_ready_for_sync_excludes_invalid_debtors(): void
    {
        $upload = Upload::factory()->create();

        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_INVALID,
            'status' => Debtor::STATUS_UPLOADED,
        ]);

        $readyForSync = Debtor::where('upload_id', $upload->id)->readyForSync()->get();

        $this->assertCount(0, $readyForSync);
    }

    public function test_ready_for_sync_excludes_non_uploaded_status(): void
    {
        $upload = Upload::factory()->create();

        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'status' => Debtor::STATUS_APPROVED,
        ]);

        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'status' => Debtor::STATUS_PROCESSING,
        ]);

        $readyForSync = Debtor::where('upload_id', $upload->id)->readyForSync()->get();

        $this->assertCount(0, $readyForSync);
    }

    public function test_ready_for_sync_mixed_vop_results(): void
    {
        $upload = Upload::factory()->create();

        // Should be included - VOP passed
        $passedDebtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'status' => Debtor::STATUS_UPLOADED,
        ]);
        VopLog::factory()->verified()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $passedDebtor->id,
        ]);

        // Should be included - no VOP log
        $noVopDebtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'status' => Debtor::STATUS_UPLOADED,
        ]);

        // Should be excluded - VOP mismatch
        $mismatchDebtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'status' => Debtor::STATUS_UPLOADED,
        ]);
        VopLog::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $mismatchDebtor->id,
            'result' => VopLog::RESULT_MISMATCH,
            'vop_score' => 30,
        ]);

        // Should be excluded - VOP rejected
        $rejectedDebtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'status' => Debtor::STATUS_UPLOADED,
        ]);
        VopLog::factory()->rejected()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $rejectedDebtor->id,
        ]);

        $readyForSync = Debtor::where('upload_id', $upload->id)->readyForSync()->get();

        $this->assertCount(2, $readyForSync);
        $this->assertTrue($readyForSync->contains('id', $passedDebtor->id));
        $this->assertTrue($readyForSync->contains('id', $noVopDebtor->id));
        $this->assertFalse($readyForSync->contains('id', $mismatchDebtor->id));
        $this->assertFalse($readyForSync->contains('id', $rejectedDebtor->id));
    }
}
