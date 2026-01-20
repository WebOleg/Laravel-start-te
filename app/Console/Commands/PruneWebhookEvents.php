<?php

namespace App\Console\Commands;

use App\Models\WebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneWebhookEvents extends Command
{
    protected $signature = 'webhooks:prune 
                            {--days=30 : Days to retain in DB}
                            {--archive : Archive to S3/MinIO before deletion}
                            {--chunk=1000 : Records per batch}';

    protected $description = 'Archive and delete webhook events older than specified days';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $archive = $this->option('archive');
        $chunk = (int) $this->option('chunk');
        $cutoff = now()->subDays($days);

        $query = WebhookEvent::where('created_at', '<', $cutoff);
        $total = $query->count();

        if ($total === 0) {
            $this->info('No webhook events to prune.');
            return self::SUCCESS;
        }

        $this->info("Found {$total} webhook events older than {$days} days.");

        $archived = 0;
        $deleted = 0;

        $query->chunkById($chunk, function ($events) use ($archive, &$archived, &$deleted) {
            if ($archive) {
                $this->archiveToStorage($events);
                $archived += $events->count();
            }

            WebhookEvent::whereIn('id', $events->pluck('id'))->delete();
            $deleted += $events->count();

            $this->output->write('.');
        });

        $this->newLine();
        $this->info("Archived: {$archived}, Deleted: {$deleted}");

        return self::SUCCESS;
    }

    private function archiveToStorage($events): void
    {
        $date = now()->format('Y/m/d');
        $timestamp = now()->format('His');
        $firstId = $events->first()->id;

        $data = $events->map(fn($e) => [
            'id' => $e->id,
            'provider' => $e->provider,
            'unique_id' => $e->unique_id,
            'event_type' => $e->event_type,
            'transaction_type' => $e->transaction_type,
            'status' => $e->status,
            'signature_valid' => $e->signature_valid,
            'processing_type' => $e->processing_type,
            'processing_status' => $e->processing_status,
            'ip_address' => $e->ip_address,
            'payload' => $e->payload,
            'headers' => $e->headers,
            'error_message' => $e->error_message,
            'retry_count' => $e->retry_count,
            'processed_at' => $e->processed_at?->toIso8601String(),
            'created_at' => $e->created_at->toIso8601String(),
        ])->toArray();

        $filename = "webhooks/{$date}/batch_{$timestamp}_{$firstId}.json.gz";
        $compressed = gzencode(json_encode($data, JSON_UNESCAPED_UNICODE), 9);

        Storage::disk('s3')->put($filename, $compressed);
    }
}
