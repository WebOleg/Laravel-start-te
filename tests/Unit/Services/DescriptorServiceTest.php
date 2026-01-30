<?php

/**
 * Unit tests for Descriptor service.
 */

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Models\TransactionDescriptor;
use App\Services\DescriptorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

class DescriptorServiceTest extends TestCase
{
    use RefreshDatabase;

    private DescriptorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DescriptorService();
    }

    public function test_it_prioritizes_specific_month_over_default(): void
    {
        // Create a Default AND a Specific rule for May 2026
        TransactionDescriptor::create([
            'is_default' => true,
            'descriptor_name' => 'DEFAULT-NAME',
            'year' => null,
            'month' => null,
        ]);

        TransactionDescriptor::create([
            'is_default' => false,
            'year' => 2026,
            'month' => 5,
            'descriptor_name' => 'SPECIFIC-MAY',
        ]);

        // Ask for May 10th, 2026
        $date = Carbon::create(2026, 5, 10);
        $result = $this->service->getActiveDescriptor($date);

        // Should ignore default and return the specific one
        $this->assertNotNull($result);
        $this->assertEquals('SPECIFIC-MAY', $result->descriptor_name);
        $this->assertEquals(2026, $result->year);
    }

    public function test_it_returns_default_if_no_specific_month_exists(): void
    {
        // Create ONLY a Default
        TransactionDescriptor::create([
            'is_default' => true,
            'descriptor_name' => 'DEFAULT-FALLBACK',
            'year' => null,
            'month' => null,
        ]);

        // Ask for a random date (e.g., Dec 2030)
        $date = Carbon::create(2030, 12, 1);
        $result = $this->service->getActiveDescriptor($date);

        // Should fallback to Default
        $this->assertNotNull($result);
        $this->assertEquals('DEFAULT-FALLBACK', $result->descriptor_name);
        $this->assertTrue($result->is_default);
    }

    public function test_it_returns_null_if_nothing_exists(): void
    {
        // Database is empty (RefreshDatabase handles cleanup)

        // Act
        $result = $this->service->getActiveDescriptor(Carbon::now());

        // Assert
        $this->assertNull($result);
    }

    public function test_it_uses_current_date_if_argument_is_null(): void
    {
        // Create a specific rule for "Right Now"
        $now = Carbon::now();

        TransactionDescriptor::create([
            'year' => $now->year,
            'month' => $now->month,
            'descriptor_name' => 'CURRENT-MONTH',
            'is_default' => false,
        ]);

        // Call without passing a date
        $result = $this->service->getActiveDescriptor(null);

        // Assert
        $this->assertNotNull($result);
        $this->assertEquals('CURRENT-MONTH', $result->descriptor_name);
    }

    public function test_it_ignores_specific_records_from_other_months(): void
    {
        // Create a rule for January, but we will ask for February
        TransactionDescriptor::create([
            'year' => 2026,
            'month' => 1, // Jan
            'descriptor_name' => 'JANUARY-ONLY',
            'is_default' => false,
        ]);

        // Ask for February
        $february = Carbon::create(2026, 2, 1);
        $result = $this->service->getActiveDescriptor($february);

        // Assert
        $this->assertNull($result);
    }

    public function test_ensure_single_default_unsets_previous_default(): void
    {
        // Create an existing default
        $oldDefault = TransactionDescriptor::create([
            'is_default' => true,
            'descriptor_name' => 'OLD-DEFAULT',
            'year' => null,
            'month' => null,
        ]);

        // Signal that we are about to save a NEW default
        $this->service->ensureSingleDefault(true);

        // The old one should now be false
        $this->assertFalse($oldDefault->fresh()->is_default);
    }

    public function test_ensure_single_default_does_nothing_if_flag_is_false(): void
    {
        // Arrange: Create an existing default
        $oldDefault = TransactionDescriptor::create([
            'is_default' => true,
            'descriptor_name' => 'OLD-DEFAULT',
            'year' => null,
            'month' => null,
        ]);

        // Act: Call with false (meaning we are saving a normal, non-default record)
        $this->service->ensureSingleDefault(false);

        // Assert: The old one should stay true
        $this->assertTrue($oldDefault->fresh()->is_default);
    }

    public function test_ensure_single_default_ignores_specified_id_on_update(): void
    {
        // Arrange: We have a record that IS default (conceptually we are updating it)
        $currentDefault = TransactionDescriptor::create([
            'is_default' => true,
            'descriptor_name' => 'CURRENT-DEFAULT',
            'year' => null,
            'month' => null,
        ]);

        // Also create ANOTHER default just to test the logic (invalid state, but good for testing)
        $anotherDefault = TransactionDescriptor::create([
            'is_default' => true,
            'descriptor_name' => 'ANOTHER-DEFAULT',
            'year' => null,
            'month' => null,
        ]);

        // This simulates updating the current default record itself.
        $this->service->ensureSingleDefault(true, $currentDefault->id);


        // The ignored ID should remain TRUE (it wasn't touched)
        $this->assertTrue($currentDefault->fresh()->is_default);

        // The *other* default should be flipped to FALSE
        $this->assertFalse($anotherDefault->fresh()->is_default);
    }
}
