<?php

namespace Tests\Feature;

use App\Models\Debtor;
use App\Models\Upload;
use App\Services\IbanBavService;
use App\Services\VopReportService;
use App\Services\VopScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Tests for BAV and VOP logging channel separation
 */
class BavLoggingChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.iban.mock' => true]);
        config(['services.iban.bav_enabled' => true]);
        config(['services.iban.bav_sampling_percentage' => 100]);
    }

    public function test_iban_bav_service_logs_to_bav_channel(): void
    {
        Log::shouldReceive('channel')
            ->with('bav')
            ->andReturnSelf();

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $bavService = app(IbanBavService::class);
        $result = $bavService->verify('DE89370400440532013000', 'Max Mustermann');

        // Verify the service returns expected result
        $this->assertTrue($result['success']);
        $this->assertEquals('yes', $result['name_match']);
    }

    public function test_iban_bav_service_logs_unsupported_country_to_bav_channel(): void
    {
        Log::shouldReceive('channel')
            ->with('bav')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->withArgs(function ($message, $context) {
                return $message === 'IbanBavService: verify() called' &&
                       $context['country'] === 'GB';
            })
            ->once();

        Log::shouldReceive('warning')
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'not supported for BAV') &&
                       $context['country'] === 'GB';
            })
            ->once();

        $bavService = app(IbanBavService::class);
        $bavService->verify('GB82WEST12345698765432', 'John Doe');
    }

    public function test_vop_scoring_service_logs_bav_operations_to_bav_channel(): void
    {
        Log::shouldReceive('channel')
            ->with('bav')
            ->andReturnSelf();

        Log::shouldReceive('channel')
            ->with('vop')
            ->andReturnSelf();

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

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

        $scoringService = app(VopScoringService::class);
        $vopLog = $scoringService->score($debtor);

        // Verify BAV was performed
        $this->assertTrue($vopLog->bav_verified);
        $this->assertNotNull($vopLog->name_match);
    }

    public function test_vop_scoring_service_logs_vop_operations_to_vop_channel(): void
    {
        Log::shouldReceive('channel')
            ->with('vop')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->atLeast()->once();

        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => 'DE89370400440532013000',
            'iban_valid' => true,
            'validation_status' => Debtor::VALIDATION_VALID,
            'bav_selected' => false, // No BAV
        ]);

        $scoringService = app(VopScoringService::class);
        $scoringService->score($debtor);
    }

    public function test_vop_report_service_logs_to_bav_channel(): void
    {
        Log::shouldReceive('channel')
            ->with('bav')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->withArgs(function ($message, $context) {
                return $message === 'BAV CSV report generated' &&
                       isset($context['upload_id']) &&
                       isset($context['csv_report']);
            })
            ->once();

        Log::shouldReceive('info')
            ->withArgs(function ($message, $context) {
                return $message === 'BAV CSV saved to S3 successfully';
            })
            ->once();

        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'bav_selected' => true,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        $reportService = app(VopReportService::class);
        $reportService->generateReport($upload->id);
    }

    public function test_bav_channel_configuration_exists(): void
    {
        $channels = config('logging.channels');

        $this->assertArrayHasKey('bav', $channels);
        $this->assertEquals('daily', $channels['bav']['driver']);
        $this->assertStringEndsWith('bav.log', $channels['bav']['path']);
        $this->assertEquals('debug', $channels['bav']['level']);
        $this->assertEquals(14, $channels['bav']['days']);
    }

    public function test_vop_channel_configuration_exists(): void
    {
        $channels = config('logging.channels');

        $this->assertArrayHasKey('vop', $channels);
        $this->assertEquals('daily', $channels['vop']['driver']);
        $this->assertStringEndsWith('vop.log', $channels['vop']['path']);
        $this->assertEquals('debug', $channels['vop']['level']);
        $this->assertEquals(14, $channels['vop']['days']);
    }

    public function test_bav_error_logs_to_bav_channel(): void
    {
        Log::shouldReceive('channel')
            ->with('bav')
            ->andReturnSelf();

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        // Mock HTTP to throw an exception
        config(['services.iban.mock' => false]);
        config(['services.iban.api_key' => 'test_key']);

        \Illuminate\Support\Facades\Http::fake(function () {
            throw new \Exception('Connection timeout');
        });

        $bavService = app(IbanBavService::class);
        $result = $bavService->verify('DE89370400440532013000', 'Test User');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Connection timeout', $result['error']);
    }

    public function test_different_operations_use_correct_channels(): void
    {
        $bavChannelCallCount = 0;
        $vopChannelCallCount = 0;

        Log::shouldReceive('channel')
            ->with('bav')
            ->andReturnUsing(function () use (&$bavChannelCallCount) {
                $bavChannelCallCount++;
                return Log::getFacadeRoot();
            });

        Log::shouldReceive('channel')
            ->with('vop')
            ->andReturnUsing(function () use (&$vopChannelCallCount) {
                $vopChannelCallCount++;
                return Log::getFacadeRoot();
            });

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

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

        // Score debtor (uses both VOP and BAV channels)
        $scoringService = app(VopScoringService::class);
        $scoringService->score($debtor);

        // Generate report (uses BAV channel)
        $reportService = app(VopReportService::class);
        $reportService->generateReport($upload->id);

        // Both channels should have been used
        $this->assertGreaterThan(0, $bavChannelCallCount, 'BAV channel should be used');
        $this->assertGreaterThan(0, $vopChannelCallCount, 'VOP channel should be used');
    }
}
