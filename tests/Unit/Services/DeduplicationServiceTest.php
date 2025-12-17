<?php

/**
 * Unit tests for DeduplicationService.
 */

namespace Tests\Unit\Services;

use App\Models\Blacklist;
use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\Upload;
use App\Services\DeduplicationService;
use App\Services\IbanValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeduplicationServiceTest extends TestCase
{
    use RefreshDatabase;

    private DeduplicationService $service;
    private IbanValidator $ibanValidator;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->ibanValidator = new IbanValidator();
        $this->service = new DeduplicationService($this->ibanValidator);
    }

    public function test_returns_null_for_new_iban(): void
    {
        $result = $this->service->checkIban('DE89370400440532013000');

        $this->assertNull($result);
    }

    public function test_detects_blacklisted_iban(): void
    {
        $iban = 'DE89370400440532013000';
        Blacklist::create([
            'iban' => $iban,
            'iban_hash' => $this->ibanValidator->hash($iban),
            'reason' => 'Fraud',
        ]);

        $result = $this->service->checkIban($iban);

        $this->assertNotNull($result);
        $this->assertEquals(DeduplicationService::SKIP_BLACKLISTED, $result['reason']);
        $this->assertTrue($result['permanent']);
    }

    public function test_detects_chargebacked_iban(): void
    {
        $iban = 'DE89370400440532013000';
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => $iban,
            'iban_hash' => $this->ibanValidator->hash($iban),
        ]);

        BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'transaction_id' => 'tx_123',
            'amount' => 100,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
        ]);

        $result = $this->service->checkIban($iban);

        $this->assertNotNull($result);
        $this->assertEquals(DeduplicationService::SKIP_CHARGEBACKED, $result['reason']);
        $this->assertTrue($result['permanent']);
    }

    public function test_detects_recovered_iban(): void
    {
        $iban = 'DE89370400440532013000';
        $upload = Upload::factory()->create();
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => $iban,
            'iban_hash' => $this->ibanValidator->hash($iban),
            'status' => Debtor::STATUS_RECOVERED,
        ]);

        $result = $this->service->checkIban($iban);

        $this->assertNotNull($result);
        $this->assertEquals(DeduplicationService::SKIP_RECOVERED, $result['reason']);
        $this->assertTrue($result['permanent']);
    }

    public function test_detects_recently_attempted_iban(): void
    {
        $iban = 'DE89370400440532013000';
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => $iban,
            'iban_hash' => $this->ibanValidator->hash($iban),
        ]);

        $attempt = BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'transaction_id' => 'tx_456',
            'amount' => 100,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);
        
        $attempt->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();

        $result = $this->service->checkIban($iban);

        $this->assertNotNull($result);
        $this->assertEquals(DeduplicationService::SKIP_RECENTLY_ATTEMPTED, $result['reason']);
        $this->assertFalse($result['permanent']);
        $this->assertEquals(3, $result['days_ago']);
    }

    public function test_allows_iban_after_cooldown_period(): void
    {
        $iban = 'DE89370400440532013000';
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => $iban,
            'iban_hash' => $this->ibanValidator->hash($iban),
        ]);

        $attempt = BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'transaction_id' => 'tx_789',
            'amount' => 100,
            'status' => BillingAttempt::STATUS_DECLINED,
        ]);
        
        $attempt->forceFill(['created_at' => now()->subDays(35)])->saveQuietly();

        $result = $this->service->checkIban($iban);

        $this->assertNull($result);
    }

    public function test_excludes_current_upload_from_recovered_check(): void
    {
        $iban = 'DE89370400440532013000';
        $upload = Upload::factory()->create();
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => $iban,
            'iban_hash' => $this->ibanValidator->hash($iban),
            'status' => Debtor::STATUS_RECOVERED,
        ]);

        $result = $this->service->checkIban($iban, $upload->id);

        $this->assertNull($result);
    }

    public function test_check_batch_returns_correct_results(): void
    {
        $iban1 = 'DE89370400440532013000';
        $iban2 = 'ES9121000418450200051332';
        $iban3 = 'FR7630006000011234567890189';

        Blacklist::create([
            'iban' => $iban1,
            'iban_hash' => $this->ibanValidator->hash($iban1),
            'reason' => 'Fraud',
        ]);

        $upload = Upload::factory()->create();
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => $iban2,
            'iban_hash' => $this->ibanValidator->hash($iban2),
            'status' => Debtor::STATUS_RECOVERED,
        ]);

        $hashes = [
            $this->ibanValidator->hash($iban1),
            $this->ibanValidator->hash($iban2),
            $this->ibanValidator->hash($iban3),
        ];

        $results = $this->service->checkBatch($hashes);

        $this->assertCount(2, $results);
        $this->assertEquals(DeduplicationService::SKIP_BLACKLISTED, $results[$hashes[0]]['reason']);
        $this->assertEquals(DeduplicationService::SKIP_RECOVERED, $results[$hashes[1]]['reason']);
        $this->assertArrayNotHasKey($hashes[2], $results);
    }

    public function test_priority_blacklist_over_other_reasons(): void
    {
        $iban = 'DE89370400440532013000';
        
        Blacklist::create([
            'iban' => $iban,
            'iban_hash' => $this->ibanValidator->hash($iban),
            'reason' => 'Fraud',
        ]);

        $upload = Upload::factory()->create();
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => $iban,
            'iban_hash' => $this->ibanValidator->hash($iban),
            'status' => Debtor::STATUS_RECOVERED,
        ]);

        $result = $this->service->checkIban($iban);

        $this->assertEquals(DeduplicationService::SKIP_BLACKLISTED, $result['reason']);
    }

    public function test_blocks_iban_on_day_29(): void
    {
        $iban = 'DE89370400440532013000';
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => $iban,
            'iban_hash' => $this->ibanValidator->hash($iban),
        ]);

        $attempt = BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'transaction_id' => 'tx_day29',
            'amount' => 100,
            'status' => BillingAttempt::STATUS_PENDING,
        ]);
        
        $attempt->forceFill(['created_at' => now()->subDays(29)])->saveQuietly();

        $result = $this->service->checkIban($iban);

        $this->assertNotNull($result);
        $this->assertEquals(DeduplicationService::SKIP_RECENTLY_ATTEMPTED, $result['reason']);
        $this->assertEquals(29, $result['days_ago']);
    }

    public function test_allows_iban_on_day_31(): void
    {
        $iban = 'DE89370400440532013000';
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => $iban,
            'iban_hash' => $this->ibanValidator->hash($iban),
        ]);

        $attempt = BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'transaction_id' => 'tx_day31',
            'amount' => 100,
            'status' => BillingAttempt::STATUS_DECLINED,
        ]);
        
        $attempt->forceFill(['created_at' => now()->subDays(31)])->saveQuietly();

        $result = $this->service->checkIban($iban);

        $this->assertNull($result);
    }

    public function test_blocks_iban_exactly_on_day_30(): void
    {
        $iban = 'DE89370400440532013000';
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => $iban,
            'iban_hash' => $this->ibanValidator->hash($iban),
        ]);

        $attempt = BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'transaction_id' => 'tx_day30',
            'amount' => 100,
            'status' => BillingAttempt::STATUS_ERROR,
        ]);
        
        $attempt->forceFill(['created_at' => now()->subDays(30)])->saveQuietly();

        $result = $this->service->checkIban($iban);

        $this->assertNotNull($result);
        $this->assertEquals(DeduplicationService::SKIP_RECENTLY_ATTEMPTED, $result['reason']);
    }
}
