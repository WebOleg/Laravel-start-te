<?php

namespace Tests\Feature\Console;

use App\Console\Commands\DispatchRecurringBilling;
use App\Jobs\ProcessBillingChunkJob;
use App\Jobs\ProcessValidationChunkJob;
use App\Jobs\ProcessVopChunkJob;
use App\Models\Debtor;
use App\Models\DebtorProfile;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DispatchRecurringBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure cache is clear before starting
        Cache::flush();
    }

    public function test_command_dispatches_pipeline_for_eligible_candidates(): void
    {
        Bus::fake();

        // 1. Validation Candidate (Flywheel, Due, Not Valid)
        $profile1 = DebtorProfile::factory()->create([
            'billing_model' => DebtorProfile::MODEL_FLYWHEEL,
            'next_bill_at' => now()->subDay(), // Due
        ]);
        $debtorValidation = Debtor::factory()->create([
            'debtor_profile_id' => $profile1->id,
            'validation_status' => Debtor::VALIDATION_PENDING,
        ]);

        // 2. VOP Candidate (Recovery, Due, Valid, VOP Pending)
        $profile2 = DebtorProfile::factory()->create([
            'billing_model' => DebtorProfile::MODEL_RECOVERY,
            'next_bill_at' => now()->subDay(), // Due
        ]);
        $debtorVop = Debtor::factory()->create([
            'debtor_profile_id' => $profile2->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'vop_status' => Debtor::VOP_PENDING,
        ]);

        // 3. Billing Candidate (Flywheel, Due, Valid, VOP Verified)
        $profile3 = DebtorProfile::factory()->create([
            'billing_model' => DebtorProfile::MODEL_FLYWHEEL,
            'next_bill_at' => now()->subDay(), // Due
        ]);
        $debtorBilling = Debtor::factory()->create([
            'debtor_profile_id' => $profile3->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'vop_status' => Debtor::VOP_VERIFIED,
        ]);

        $this->artisan('billing:dispatch')
            ->expectsOutput('Recurring billing pipeline dispatched.')
            ->assertExitCode(0);

        // Assert Validation Job Dispatched (Flywheel)
        Bus::assertBatched(function (PendingBatch $batch) use ($debtorValidation) {
            return $batch->jobs->contains(function ($job) use ($debtorValidation) {
                // FIX: Use 'debtorIds' instead of 'chunk'
                return $job instanceof ProcessValidationChunkJob
                    && in_array($debtorValidation->id, $job->debtorIds);
            });
        });

        // Assert VOP Job Dispatched (Recovery)
        Bus::assertBatched(function (PendingBatch $batch) use ($debtorVop) {
            return $batch->jobs->contains(function ($job) use ($debtorVop) {
                // FIX: Use 'debtorIds' instead of 'chunk'
                return $job instanceof ProcessVopChunkJob
                    && in_array($debtorVop->id, $job->debtorIds);
            });
        });

        // Assert Billing Job Dispatched (Flywheel)
        Bus::assertBatched(function (PendingBatch $batch) use ($debtorBilling) {
            return $batch->jobs->contains(function ($job) use ($debtorBilling) {
                // FIX: Use 'debtorIds' instead of 'chunk' AND 'billingModel' instead of 'model'
                return $job instanceof ProcessBillingChunkJob
                    && in_array($debtorBilling->id, $job->debtorIds)
                    && $job->billingModel === DebtorProfile::MODEL_FLYWHEEL;
            });
        });

        // Verify Locks were acquired for Validation and VOP
        $this->assertTrue(Cache::has("billing:lock:validation:{$debtorValidation->id}"));
        $this->assertTrue(Cache::has("billing:lock:vop:{$debtorVop->id}"));
    }

    public function test_skips_records_locked_in_redis(): void
    {
        Bus::fake();

        $profile = DebtorProfile::factory()->create([
            'billing_model' => DebtorProfile::MODEL_FLYWHEEL,
            'next_bill_at' => now()->subDay(),
        ]);

        // Create a validation candidate
        $debtor = Debtor::factory()->create([
            'debtor_profile_id' => $profile->id,
            'validation_status' => Debtor::VALIDATION_PENDING,
        ]);

        // Manually lock this record in Redis
        Cache::add("billing:lock:validation:{$debtor->id}", true, 1800);

        $this->artisan('billing:dispatch');

        // Assert NO batches were dispatched because the only candidate was locked
        Bus::assertNothingBatched();
    }

    public function test_skips_records_not_due_or_invalid_status(): void
    {
        Bus::fake();

        // 1. Profile NOT due (future date) - Should be skipped
        $futureProfile = DebtorProfile::factory()->create([
            'billing_model' => DebtorProfile::MODEL_FLYWHEEL,
            'next_bill_at' => now()->addDay(),
        ]);

        Debtor::factory()->create([
            'debtor_profile_id' => $futureProfile->id,
            'validation_status' => Debtor::VALIDATION_PENDING,
        ]);

        // 2. Debtor valid but VOP Pending (Should be picked up for VOP phase)
        // FIX: Explicitly set model to FLYWHEEL because the command ignores LEGACY
        $profile = DebtorProfile::factory()->create([
            'billing_model' => DebtorProfile::MODEL_FLYWHEEL,
            'next_bill_at' => now()->subDay()
        ]);

        $debtor = Debtor::factory()->create([
            'debtor_profile_id' => $profile->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'vop_status' => Debtor::VOP_PENDING,
        ]);

        $this->artisan('billing:dispatch');

        // Assert that we ONLY dispatched the VOP batch for the valid flywheel debtor
        // and skipped the future dated one.
        Bus::assertBatched(function (PendingBatch $batch) {
            return $batch->name === 'Recurring VOP (flywheel)';
        });

        // Ensure no other batches (like Validation) were dispatched
        Bus::assertBatched(function (PendingBatch $batch) {
            return str_contains($batch->name, 'Validation') === false;
        });
    }

    public function test_chunking_logic_splits_large_datasets(): void
    {
        Bus::fake();

        // Create 150 validation candidates (Chunk size is 100)
        $profile = DebtorProfile::factory()->create([
            'billing_model' => DebtorProfile::MODEL_FLYWHEEL,
            'next_bill_at' => now()->subDay(),
        ]);

        Debtor::factory()->count(150)->create([
            'debtor_profile_id' => $profile->id,
            'validation_status' => Debtor::VALIDATION_PENDING,
        ]);

        $this->artisan('billing:dispatch');

        Bus::assertBatched(function (PendingBatch $batch) {
            // We expect one batch containing 2 jobs (100 + 50)
            return $batch->jobs->count() === 2
                && $batch->jobs->first() instanceof ProcessValidationChunkJob;
        });
    }

    public function test_iterates_all_defined_models(): void
    {
        Bus::fake();

        // Flywheel Candidate
        $p1 = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_FLYWHEEL, 'next_bill_at' => now()->subDay()]);
        $d1 = Debtor::factory()->create(['debtor_profile_id' => $p1->id, 'validation_status' => Debtor::VALIDATION_PENDING]);

        // Recovery Candidate
        $p2 = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_RECOVERY, 'next_bill_at' => now()->subDay()]);
        $d2 = Debtor::factory()->create(['debtor_profile_id' => $p2->id, 'validation_status' => Debtor::VALIDATION_PENDING]);

        $this->artisan('billing:dispatch');

        // Verify batch was created for Flywheel
        Bus::assertBatched(function (PendingBatch $batch) use ($d1) {
            return $batch->name === 'Recurring Validation (flywheel)'
                // FIX: Use 'debtorIds'
                && $batch->jobs->contains(fn($j) => in_array($d1->id, $j->debtorIds));
        });

        // Verify batch was created for Recovery
        Bus::assertBatched(function (PendingBatch $batch) use ($d2) {
            return $batch->name === 'Recurring Validation (recovery)'
                // FIX: Use 'debtorIds'
                && $batch->jobs->contains(fn($j) => in_array($d2->id, $j->debtorIds));
        });
    }
}
