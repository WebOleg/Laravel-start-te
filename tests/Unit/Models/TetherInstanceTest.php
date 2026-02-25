<?php

/**
 * Unit tests for the TetherInstance model.
 */

namespace Tests\Unit\Models;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\TetherInstance;
use App\Models\Upload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TetherInstanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The create_tether_instances_table migration seeds one record; delete it
        // so scope/count assertions start from a clean state.
        TetherInstance::query()->delete();
    }

    public function test_scope_active_returns_only_active_instances(): void
    {
        TetherInstance::factory()->active()->create(['slug' => 'emp']);
        TetherInstance::factory()->inactive()->create(['slug' => 'finxp']);

        $results = TetherInstance::active()->get();

        $this->assertCount(1, $results);
        $this->assertTrue($results->first()->is_active);
    }

    public function test_scope_for_acquirer_filters_by_type(): void
    {
        TetherInstance::factory()->create(['slug' => 'emp-1', 'acquirer_type' => TetherInstance::ACQUIRER_EMP]);
        TetherInstance::factory()->create(['slug' => 'finxp-1', 'acquirer_type' => TetherInstance::ACQUIRER_FINXP]);

        $empResults = TetherInstance::forAcquirer(TetherInstance::ACQUIRER_EMP)->get();

        $this->assertCount(1, $empResults);
        $this->assertEquals(TetherInstance::ACQUIRER_EMP, $empResults->first()->acquirer_type);
    }

    public function test_is_emp_returns_true_for_emp_acquirer_type(): void
    {
        $instance = TetherInstance::factory()->create([
            'slug'         => 'emp-test',
            'acquirer_type' => TetherInstance::ACQUIRER_EMP,
        ]);

        $this->assertTrue($instance->isEmp());
    }

    public function test_is_emp_returns_false_for_non_emp_acquirer_type(): void
    {
        $instance = TetherInstance::factory()->create([
            'slug'         => 'finxp-test',
            'acquirer_type' => TetherInstance::ACQUIRER_FINXP,
        ]);

        $this->assertFalse($instance->isEmp());
    }

    public function test_get_queue_name_returns_expected_format(): void
    {
        $instance = TetherInstance::factory()->create(['slug' => 'emp']);

        $this->assertEquals("billing:{$instance->id}", $instance->getQueueName('billing'));
        $this->assertEquals("vop:{$instance->id}", $instance->getQueueName('vop'));
    }

    public function test_debtors_relationship_resolves(): void
    {
        $instance = TetherInstance::factory()->create(['slug' => 'emp']);
        $upload   = Upload::factory()->create(['tether_instance_id' => $instance->id]);
        Debtor::factory()->create(['upload_id' => $upload->id, 'tether_instance_id' => $instance->id]);

        $this->assertCount(1, $instance->debtors);
    }

    public function test_uploads_relationship_resolves(): void
    {
        $instance = TetherInstance::factory()->create(['slug' => 'emp']);
        Upload::factory()->create(['tether_instance_id' => $instance->id]);

        $this->assertCount(1, $instance->uploads);
    }

    public function test_billing_attempts_relationship_resolves(): void
    {
        $instance = TetherInstance::factory()->create(['slug' => 'emp']);
        $upload   = Upload::factory()->create(['tether_instance_id' => $instance->id]);
        $debtor   = Debtor::factory()->create(['upload_id' => $upload->id, 'tether_instance_id' => $instance->id]);
        BillingAttempt::factory()->create([
            'upload_id'          => $upload->id,
            'debtor_id'          => $debtor->id,
            'tether_instance_id' => $instance->id,
        ]);

        $this->assertCount(1, $instance->billingAttempts);
    }
}
