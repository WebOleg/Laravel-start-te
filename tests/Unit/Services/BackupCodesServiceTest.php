<?php

/**
 * Unit tests for BackupCodesService.
 */

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\BackupCodesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BackupCodesServiceTest extends TestCase
{
    use RefreshDatabase;

    private BackupCodesService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BackupCodesService::class);
    }

    public function test_generate_creates_fresh_set_of_codes(): void
    {
        $user = User::factory()->create();

        $plaintextCodes = $this->service->generate($user);

        // Assert 10 codes are returned to the user
        $this->assertCount(10, $plaintextCodes);

        // Assert codes follow the XXXX-XXXX format
        foreach ($plaintextCodes as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code);
        }
    }

    public function test_generate_stores_hashed_codes_in_database(): void
    {
        $user = User::factory()->create();

        $plaintextCodes = $this->service->generate($user);
        $user->refresh();

        $storedCodes = $user->two_factor_backup_codes;

        $this->assertCount(10, $storedCodes);

        // Ensure the stored code is NOT the plaintext code
        $this->assertNotEquals($plaintextCodes[0], $storedCodes[0]);

        // Ensure the stored code is a valid hash of the plaintext code
        $this->assertTrue(Hash::check($plaintextCodes[0], $storedCodes[0]));
    }

    public function test_verify_returns_true_for_valid_code(): void
    {
        $user = User::factory()->create();
        $codes = $this->service->generate($user);
        $validCode = $codes[0];

        $isValid = $this->service->verify($user, $validCode);

        $this->assertTrue($isValid);
    }

    public function test_verify_consumes_code_on_success(): void
    {
        $user = User::factory()->create();
        $codes = $this->service->generate($user);
        $codeToUse = $codes[0];

        // First verification should succeed
        $this->service->verify($user, $codeToUse);

        $user->refresh();

        // Count should decrease by 1
        $this->assertCount(9, $user->two_factor_backup_codes);
        $this->assertEquals(9, $this->service->getRemainingCount($user));

        // Using the same code again should fail
        $isRetryValid = $this->service->verify($user, $codeToUse);
        $this->assertFalse($isRetryValid);
    }

    public function test_verify_returns_false_for_invalid_code(): void
    {
        $user = User::factory()->create();
        $this->service->generate($user);

        $isValid = $this->service->verify($user, 'WRONG-CODE');

        $this->assertFalse($isValid);

        // Count should remain unchanged
        $this->assertEquals(10, $this->service->getRemainingCount($user));
    }

    public function test_verify_handles_formatting_and_case_insensitivity(): void
    {
        $user = User::factory()->create();
        $codes = $this->service->generate($user);

        // Convert to lowercase and add whitespace
        $messyInput = '  ' . strtolower($codes[0]) . '  ';

        $isValid = $this->service->verify($user, $messyInput);

        $this->assertTrue($isValid);
    }

    public function test_verify_returns_false_if_no_codes_exist(): void
    {
        $user = User::factory()->create([
            'two_factor_backup_codes' => null,
        ]);

        $isValid = $this->service->verify($user, 'ANY-CODE');

        $this->assertFalse($isValid);
    }

    public function test_get_remaining_count_returns_correct_integer(): void
    {
        $user = User::factory()->create();

        // Initially 0 or null
        $this->assertEquals(0, $this->service->getRemainingCount($user));

        // After generation
        $this->service->generate($user);
        $this->assertEquals(10, $this->service->getRemainingCount($user));

        // After using one
        $codes = $this->service->generate($user);
        $this->service->verify($user, $codes[0]);
        $user->refresh();

        $this->assertEquals(9, $this->service->getRemainingCount($user));
    }
}
