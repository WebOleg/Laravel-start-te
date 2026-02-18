<?php

/**
 * Unit tests for Descriptor service.
 */

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Models\TransactionDescriptor;
use App\Models\EmpAccount;
use App\Services\DescriptorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
        // Create a Global Default
        TransactionDescriptor::create([
            'is_default' => true,
            'descriptor_name' => 'DEFAULT-NAME',
            'year' => null,
            'month' => null,
            'emp_account_id' => null,
        ]);

        // Create EMP account
        $empAccount = EmpAccount::factory()->create();

        // Create a Specific rule for May 2026 with emp_account_id
        TransactionDescriptor::create([
            'is_default' => false,
            'year' => 2026,
            'month' => 5,
            'descriptor_name' => 'SPECIFIC-MAY',
            'emp_account_id' => $empAccount->id,
        ]);

        // Ask for May 10th, 2026 with emp_account_id
        $date = Carbon::create(2026, 5, 10);
        $result = $this->service->getActiveDescriptor($date, $empAccount->id);

        // Should ignore default and return the specific one
        $this->assertNotNull($result);
        $this->assertEquals('SPECIFIC-MAY', $result->descriptor_name);
        $this->assertEquals(2026, $result->year);
    }

    public function test_it_returns_default_if_no_specific_month_exists(): void
    {
        // Create ONLY a Global Default
        TransactionDescriptor::create([
            'is_default' => true,
            'descriptor_name' => 'DEFAULT-FALLBACK',
            'year' => null,
            'month' => null,
            'emp_account_id' => null,
        ]);

        // Ask for a random date (e.g., Dec 2030)
        $date = Carbon::create(2030, 12, 1);
        $result = $this->service->getActiveDescriptor($date);

        // Should fallback to Global Default
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
        // Create a global default since we're calling without emp_account_id
        TransactionDescriptor::create([
            'is_default' => true,
            'descriptor_name' => 'GLOBAL-DEFAULT',
            'year' => null,
            'month' => null,
            'emp_account_id' => null,
        ]);

        // Call without passing a date
        $result = $this->service->getActiveDescriptor(null);

        // Assert - should get the global default since no emp_account_id provided
        $this->assertNotNull($result);
        $this->assertEquals('GLOBAL-DEFAULT', $result->descriptor_name);
    }

    public function test_it_ignores_specific_records_from_other_months(): void
    {
        // Create EMP account
        $empAccount = EmpAccount::factory()->create();

        // Create a rule for January, but we will ask for February
        TransactionDescriptor::create([
            'year' => 2026,
            'month' => 1, // Jan
            'descriptor_name' => 'JANUARY-ONLY',
            'is_default' => false,
            'emp_account_id' => $empAccount->id,
        ]);

        // Ask for February with emp_account_id
        $february = Carbon::create(2026, 2, 1);
        $result = $this->service->getActiveDescriptor($february, $empAccount->id);

        // Assert - should return null (no descriptors for Feb)
        $this->assertNull($result);
    }

    public function test_ensure_single_default_unsets_previous_default(): void
    {
        // Create an existing global default
        $oldDefault = TransactionDescriptor::create([
            'is_default' => true,
            'descriptor_name' => 'OLD-DEFAULT',
            'year' => null,
            'month' => null,
            'emp_account_id' => null,
        ]);

        // Signal that we are about to save a NEW global default
        $this->service->ensureSingleDefault(true, null, null);

        // The old one should now be false
        $this->assertFalse($oldDefault->fresh()->is_default);
    }

    public function test_ensure_single_default_does_nothing_if_flag_is_false(): void
    {
        // Arrange: Create an existing global default
        $oldDefault = TransactionDescriptor::create([
            'is_default' => true,
            'descriptor_name' => 'OLD-DEFAULT',
            'year' => null,
            'month' => null,
            'emp_account_id' => null,
        ]);

        // Act: Call with false (meaning we are saving a normal, non-default record)
        $this->service->ensureSingleDefault(false);

        // Assert: The old one should stay true
        $this->assertTrue($oldDefault->fresh()->is_default);
    }

    // New tests for emp_account_id functionality

    public function test_it_prioritizes_emp_specific_descriptor_over_global(): void
    {
        // Create EMP account
        $empAccount = EmpAccount::factory()->create();

        // Create a Global Default
        TransactionDescriptor::create([
            'is_default' => true,
            'descriptor_name' => 'GLOBAL-DEFAULT',
            'year' => null,
            'month' => null,
            'emp_account_id' => null,
        ]);

        // Create a Specific descriptor for May 2026 with emp_account_id
        TransactionDescriptor::create([
            'is_default' => false,
            'year' => 2026,
            'month' => 5,
            'descriptor_name' => 'EMP-SPECIFIC-MAY',
            'emp_account_id' => $empAccount->id,
        ]);

        // Ask for May 10th, 2026 with emp_account_id
        $date = Carbon::create(2026, 5, 10);
        $result = $this->service->getActiveDescriptor($date, $empAccount->id);

        // Should return the EMP-specific descriptor
        $this->assertNotNull($result);
        $this->assertEquals('EMP-SPECIFIC-MAY', $result->descriptor_name);
        $this->assertEquals($empAccount->id, $result->emp_account_id);
    }

    public function test_it_falls_back_to_emp_default_when_no_specific_month(): void
    {
        // Create EMP account
        $empAccount = EmpAccount::factory()->create();

        // Create a Global Default
        TransactionDescriptor::create([
            'is_default' => true,
            'descriptor_name' => 'GLOBAL-DEFAULT',
            'year' => null,
            'month' => null,
            'emp_account_id' => null,
        ]);

        // Create an EMP Default
        TransactionDescriptor::create([
            'is_default' => true,
            'descriptor_name' => 'EMP-DEFAULT',
            'year' => null,
            'month' => null,
            'emp_account_id' => $empAccount->id,
        ]);

        // Ask for a random date with emp_account_id
        $date = Carbon::create(2030, 12, 1);
        $result = $this->service->getActiveDescriptor($date, $empAccount->id);

        // Should fallback to EMP Default, not Global Default
        $this->assertNotNull($result);
        $this->assertEquals('EMP-DEFAULT', $result->descriptor_name);
        $this->assertEquals($empAccount->id, $result->emp_account_id);
    }

    public function test_it_falls_back_to_global_default_when_no_emp_descriptors_exist(): void
    {
        // Create EMP account
        $empAccount = EmpAccount::factory()->create();

        // Create ONLY a Global Default
        TransactionDescriptor::create([
            'is_default' => true,
            'descriptor_name' => 'GLOBAL-DEFAULT',
            'year' => null,
            'month' => null,
            'emp_account_id' => null,
        ]);

        // Ask for any date with emp_account_id
        $date = Carbon::create(2026, 6, 1);
        $result = $this->service->getActiveDescriptor($date, $empAccount->id);

        // Should fallback to Global Default
        $this->assertNotNull($result);
        $this->assertEquals('GLOBAL-DEFAULT', $result->descriptor_name);
        $this->assertNull($result->emp_account_id);
    }

    public function test_it_ignores_other_emp_account_descriptors(): void
    {
        // Create 2 EMP accounts
        $empAccount1 = EmpAccount::factory()->create();
        $empAccount2 = EmpAccount::factory()->create();

        // Create a Specific descriptor for EMP Account 1
        TransactionDescriptor::create([
            'is_default' => false,
            'year' => 2026,
            'month' => 5,
            'descriptor_name' => 'EMP1-SPECIFIC',
            'emp_account_id' => $empAccount1->id,
        ]);

        // Create an EMP Default for EMP Account 1
        TransactionDescriptor::create([
            'is_default' => true,
            'descriptor_name' => 'EMP1-DEFAULT',
            'year' => null,
            'month' => null,
            'emp_account_id' => $empAccount1->id,
        ]);

        // Ask for May 2026 with EMP Account 2
        $date = Carbon::create(2026, 5, 1);
        $result = $this->service->getActiveDescriptor($date, $empAccount2->id);

        // Should return null (no descriptors for EMP Account 2 and no global default)
        $this->assertNull($result);
    }

    public function test_ensure_single_default_maintains_one_per_emp_account(): void
    {
        // Create EMP account
        $empAccount = EmpAccount::factory()->create();

        // Create a Global Default
        $globalDefault = TransactionDescriptor::create([
            'is_default' => true,
            'descriptor_name' => 'GLOBAL-DEFAULT',
            'year' => null,
            'month' => null,
            'emp_account_id' => null,
        ]);

        // Create an existing EMP Default
        $oldEmpDefault = TransactionDescriptor::create([
            'is_default' => true,
            'descriptor_name' => 'OLD-EMP-DEFAULT',
            'year' => null,
            'month' => null,
            'emp_account_id' => $empAccount->id,
        ]);

        // Signal that we are about to save a NEW EMP default
        $this->service->ensureSingleDefault(true, null, $empAccount->id);

        // The global default should remain unchanged
        $this->assertTrue($globalDefault->fresh()->is_default);

        // The old EMP default should be unset
        $this->assertFalse($oldEmpDefault->fresh()->is_default);
    }

    public function test_ensure_single_default_for_global_does_not_affect_emp_defaults(): void
    {
        // Create EMP account
        $empAccount = EmpAccount::factory()->create();

        // Create an existing Global Default
        $oldGlobalDefault = TransactionDescriptor::create([
            'is_default' => true,
            'descriptor_name' => 'OLD-GLOBAL-DEFAULT',
            'year' => null,
            'month' => null,
            'emp_account_id' => null,
        ]);

        // Create an EMP Default
        $empDefault = TransactionDescriptor::create([
            'is_default' => true,
            'descriptor_name' => 'EMP-DEFAULT',
            'year' => null,
            'month' => null,
            'emp_account_id' => $empAccount->id,
        ]);

        // Signal that we are about to save a NEW global default
        $this->service->ensureSingleDefault(true, null, null);

        // The old global default should be unset
        $this->assertFalse($oldGlobalDefault->fresh()->is_default);

        // The EMP default should remain unchanged
        $this->assertTrue($empDefault->fresh()->is_default);
    }

    // Cache tests

    public function test_it_caches_descriptor_lookups(): void
    {
        // Create a Global Default
        $descriptor = TransactionDescriptor::create([
            'is_default' => true,
            'descriptor_name' => 'CACHED-DEFAULT',
            'year' => null,
            'month' => null,
            'emp_account_id' => null,
        ]);

        $date = Carbon::create(2026, 5, 10);

        // First call - should hit database
        $result1 = $this->service->getActiveDescriptor($date);
        $this->assertEquals('CACHED-DEFAULT', $result1->descriptor_name);

        // Verify cache was set
        $cacheKey = 'descriptor:2026:5:global';
        $this->assertTrue(Cache::has($cacheKey));

        // Delete the descriptor from database
        $descriptor->delete();

        // Second call - should return cached result even though DB record is gone
        $result2 = $this->service->getActiveDescriptor($date);
        $this->assertNotNull($result2);
        $this->assertEquals('CACHED-DEFAULT', $result2->descriptor_name);
    }

    public function test_ensure_single_default_invalidates_cache(): void
    {
        // Create an existing global default
        TransactionDescriptor::create([
            'is_default' => true,
            'descriptor_name' => 'OLD-DEFAULT',
            'year' => null,
            'month' => null,
            'emp_account_id' => null,
        ]);

        $date = Carbon::create(2026, 5, 10);

        // First call - populate cache
        $this->service->getActiveDescriptor($date);
        $cacheKey = 'descriptor:2026:5:global';
        $this->assertTrue(Cache::has($cacheKey));

        // Call ensureSingleDefault - should invalidate cache
        $this->service->ensureSingleDefault(true, null, null);

        // Verify cache was cleared
        $this->assertFalse(Cache::has($cacheKey));
    }
}
