<?php

namespace App\Jobs;

use App\Services\VopReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateVopReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(
        public int $uploadId
    ) {
        $this->onQueue('default');
    }

    public function handle(VopReportService $reportService): void
    {
        try {
            $reportFile = $reportService->generateReport($this->uploadId);

            Log::info('VOP report generated', [
                'upload_id' => $this->uploadId,
                'report_file' => $reportFile,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to generate VOP report', [
                'upload_id' => $this->uploadId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
