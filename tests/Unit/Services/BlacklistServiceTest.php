<?php

/**
 * Unit tests for BlacklistService.
 */

namespace Tests\Unit\Services;

use App\Models\Blacklist;
use App\Models\User;
use App\Services\BlacklistService;
use App\Services\IbanValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlacklistServiceTest extends TestCase
{
    use RefreshDatabase;

    private BlacklistService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BlacklistService(new IbanValidator());
    }

    public function test_add_creates_blacklist_entry(): void
    {
        $iban = 'DE89370400440532013000';

        $blacklist = $this->service->add($iban, 'Fraud', 'Manual');

        $this->assertDatabaseHas('blacklists', [
            'iban' => 'DE89370400440532013000',
            'reason' => 'Fraud',
            'source' => 'Manual',
        ]);
        $this->assertNotNull($blacklist->iban_hash);
    }

    public function test_add_with_user(): void
    {
        $user = User::factory()->create();
        $iban = 'DE89370400440532013000';

        $blacklist = $this->service->add($iban, 'Chargeback', 'System', $user->id);

        $this->assertEquals($user->id, $blacklist->added_by);
    }

    public function test_is_blacklisted_returns_true_for_blacklisted_iban(): void
    {
        $iban = 'DE89370400440532013000';
        $this->service->add($iban);

        $this->assertTrue($this->service->isBlacklisted($iban));
    }

    public function test_is_blacklisted_returns_false_for_clean_iban(): void
    {
        $iban = 'DE89370400440532013000';

        $this->assertFalse($this->service->isBlacklisted($iban));
    }

    public function test_is_blacklisted_works_with_formatted_iban(): void
    {
        $this->service->add('DE89370400440532013000');

        $this->assertTrue($this->service->isBlacklisted('DE89 3704 0044 0532 0130 00'));
    }

    public function test_remove_deletes_blacklist_entry(): void
    {
        $iban = 'DE89370400440532013000';
        $this->service->add($iban);

        $result = $this->service->remove($iban);

        $this->assertTrue($result);
        $this->assertFalse($this->service->isBlacklisted($iban));
    }

    public function test_remove_returns_false_for_nonexistent_entry(): void
    {
        $result = $this->service->remove('DE89370400440532013000');

        $this->assertFalse($result);
    }
}
