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

    // IBAN tests

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

    // Name tests

    public function test_is_name_blacklisted_returns_true_for_blacklisted_name(): void
    {
        Blacklist::create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban_hash' => hash('sha256', 'JohnDoe'),
            'reason' => 'Fraud',
            'source' => 'manual',
        ]);

        $this->assertTrue($this->service->isNameBlacklisted('John', 'Doe'));
    }

    public function test_is_name_blacklisted_is_case_insensitive(): void
    {
        Blacklist::create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban_hash' => hash('sha256', 'JohnDoe'),
            'reason' => 'Fraud',
            'source' => 'manual',
        ]);

        $this->assertTrue($this->service->isNameBlacklisted('JOHN', 'DOE'));
        $this->assertTrue($this->service->isNameBlacklisted('john', 'doe'));
        $this->assertTrue($this->service->isNameBlacklisted('JoHn', 'DoE'));
    }

    public function test_is_name_blacklisted_returns_false_for_clean_name(): void
    {
        $this->assertFalse($this->service->isNameBlacklisted('John', 'Doe'));
    }

    public function test_is_name_blacklisted_returns_false_for_empty_name(): void
    {
        $this->assertFalse($this->service->isNameBlacklisted('', ''));
    }

    // Email tests

    public function test_is_email_blacklisted_returns_true_for_blacklisted_email(): void
    {
        Blacklist::create([
            'email' => 'fraud@example.com',
            'iban_hash' => hash('sha256', 'fraud@example.com'),
            'reason' => 'Spam',
            'source' => 'manual',
        ]);

        $this->assertTrue($this->service->isEmailBlacklisted('fraud@example.com'));
    }

    public function test_is_email_blacklisted_is_case_insensitive(): void
    {
        Blacklist::create([
            'email' => 'fraud@example.com',
            'iban_hash' => hash('sha256', 'fraud@example.com'),
            'reason' => 'Spam',
            'source' => 'manual',
        ]);

        $this->assertTrue($this->service->isEmailBlacklisted('FRAUD@EXAMPLE.COM'));
        $this->assertTrue($this->service->isEmailBlacklisted('Fraud@Example.Com'));
    }

    public function test_is_email_blacklisted_returns_false_for_clean_email(): void
    {
        $this->assertFalse($this->service->isEmailBlacklisted('clean@example.com'));
    }

    public function test_is_email_blacklisted_returns_false_for_empty_email(): void
    {
        $this->assertFalse($this->service->isEmailBlacklisted(''));
    }

    // BIC tests

    public function test_is_bic_blacklisted_returns_true_for_blacklisted_bic(): void
    {
        Blacklist::create([
            'bic' => 'BDFEFR',
            'iban_hash' => hash('sha256', 'BDFEFR'),
            'reason' => 'Banque de France - blocked',
            'source' => 'v1_migration',
        ]);

        $this->assertTrue($this->service->isBicBlacklisted('BDFEFR'));
    }

    public function test_is_bic_blacklisted_is_case_insensitive(): void
    {
        Blacklist::create([
            'bic' => 'BDFEFR',
            'iban_hash' => hash('sha256', 'BDFEFR'),
            'reason' => 'Blocked bank',
            'source' => 'manual',
        ]);

        $this->assertTrue($this->service->isBicBlacklisted('bdfefr'));
        $this->assertTrue($this->service->isBicBlacklisted('BdFeFr'));
    }

    public function test_is_bic_blacklisted_returns_false_for_clean_bic(): void
    {
        $this->assertFalse($this->service->isBicBlacklisted('DEUTDEFF'));
    }

    public function test_is_bic_blacklisted_returns_false_for_empty_bic(): void
    {
        $this->assertFalse($this->service->isBicBlacklisted(''));
    }

    // checkDebtor tests

    public function test_check_debtor_detects_blacklisted_iban(): void
    {
        $iban = 'DE89370400440532013000';
        $this->service->add($iban);

        $result = $this->service->checkDebtor(['iban' => $iban]);

        $this->assertTrue($result['iban']);
        $this->assertFalse($result['name']);
        $this->assertFalse($result['email']);
        $this->assertFalse($result['bic']);
        $this->assertContains('IBAN is blacklisted', $result['reasons']);
    }

    public function test_check_debtor_detects_blacklisted_name(): void
    {
        Blacklist::create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban_hash' => hash('sha256', 'JohnDoe'),
            'reason' => 'Fraud',
            'source' => 'manual',
        ]);

        $result = $this->service->checkDebtor([
            'iban' => 'DE89370400440532013000',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $this->assertFalse($result['iban']);
        $this->assertTrue($result['name']);
        $this->assertContains('Name is blacklisted', $result['reasons']);
    }

    public function test_check_debtor_detects_blacklisted_email(): void
    {
        Blacklist::create([
            'email' => 'fraud@example.com',
            'iban_hash' => hash('sha256', 'fraud@example.com'),
            'reason' => 'Spam',
            'source' => 'manual',
        ]);

        $result = $this->service->checkDebtor([
            'iban' => 'DE89370400440532013000',
            'email' => 'fraud@example.com',
        ]);

        $this->assertFalse($result['iban']);
        $this->assertTrue($result['email']);
        $this->assertContains('Email is blacklisted', $result['reasons']);
    }

    public function test_check_debtor_detects_blacklisted_bic(): void
    {
        Blacklist::create([
            'bic' => 'BDFEFR',
            'iban_hash' => hash('sha256', 'BDFEFR'),
            'reason' => 'Blocked bank',
            'source' => 'manual',
        ]);

        $result = $this->service->checkDebtor([
            'iban' => 'DE89370400440532013000',
            'bic' => 'BDFEFR',
        ]);

        $this->assertFalse($result['iban']);
        $this->assertTrue($result['bic']);
        $this->assertContains('BIC is blacklisted', $result['reasons']);
    }

    public function test_check_debtor_detects_multiple_blacklist_matches(): void
    {
        $iban = 'DE89370400440532013000';
        $this->service->add($iban);

        Blacklist::create([
            'email' => 'fraud@example.com',
            'iban_hash' => hash('sha256', 'fraud@example.com'),
            'reason' => 'Spam',
            'source' => 'manual',
        ]);

        $result = $this->service->checkDebtor([
            'iban' => $iban,
            'email' => 'fraud@example.com',
        ]);

        $this->assertTrue($result['iban']);
        $this->assertTrue($result['email']);
        $this->assertCount(2, $result['reasons']);
    }

    public function test_is_debtor_blacklisted_returns_true_when_any_field_blacklisted(): void
    {
        Blacklist::create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban_hash' => hash('sha256', 'JohnDoe'),
            'reason' => 'Fraud',
            'source' => 'manual',
        ]);

        $this->assertTrue($this->service->isDebtorBlacklisted([
            'iban' => 'DE89370400440532013000',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]));
    }

    public function test_is_debtor_blacklisted_returns_false_when_clean(): void
    {
        $this->assertFalse($this->service->isDebtorBlacklisted([
            'iban' => 'DE89370400440532013000',
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'clean@example.com',
        ]));
    }
}
