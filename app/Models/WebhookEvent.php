<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class WebhookEvent extends Model
{
    public const RECEIVED = 0;
    public const QUEUED = 1;
    public const PROCESSING = 2;
    public const COMPLETED = 3;
    public const FAILED = 4;
    public const DUPLICATE = 5;

    public const CACHE_TTL = 86400;
    public const CACHE_PREFIX = 'wh:';

    protected $fillable = [
        'provider',
        'unique_id',
        'event_type',
        'transaction_type',
        'status',
        'signature',
        'signature_valid',
        'processing_type',
        'processing_status',
        'ip_address',
        'user_agent',
        'payload_size',
        'payload',
        'headers',
        'error_message',
        'retry_count',
        'processed_at',
    ];

    protected $casts = [
        'signature_valid' => 'boolean',
        'payload' => 'array',
        'headers' => 'array',
        'processed_at' => 'datetime',
    ];

    public static function cacheKey(string $provider, string $uniqueId, ?string $eventType): string
    {
        return self::CACHE_PREFIX . $provider . ':' . $uniqueId . ':' . ($eventType ?? '_');
    }

    public static function isDuplicate(string $provider, string $uniqueId, ?string $eventType = null): bool
    {
        $key = self::cacheKey($provider, $uniqueId, $eventType);

        if (Cache::has($key)) {
            return true;
        }

        return self::where('provider', $provider)
            ->where('unique_id', $uniqueId)
            ->when($eventType, fn($q) => $q->where('event_type', $eventType))
            ->where('processing_status', '<=', self::COMPLETED)
            ->exists();
    }

    public static function recordAndCheck(array $data): ?self
    {
        $key = self::cacheKey($data['provider'], $data['unique_id'], $data['event_type'] ?? null);

        if (!Cache::add($key, 1, self::CACHE_TTL)) {
            return null;
        }

        try {
            return self::create($data);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] === 1062 || str_contains($e->getMessage(), 'duplicate')) {
                return null;
            }
            Cache::forget($key);
            throw $e;
        }
    }

    public function markQueued(): void
    {
        $this->update(['processing_status' => self::QUEUED]);
    }

    public function markProcessing(): void
    {
        $this->update(['processing_status' => self::PROCESSING]);
    }

    public function markCompleted(): void
    {
        $this->update([
            'processing_status' => self::COMPLETED,
            'processed_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'processing_status' => self::FAILED,
            'error_message' => mb_substr($error, 0, 500),
            'processed_at' => now(),
        ]);

        Cache::forget(self::cacheKey($this->provider, $this->unique_id, $this->event_type));
    }

    public function markDuplicate(): void
    {
        $this->update(['processing_status' => self::DUPLICATE]);
    }

    public function incrementRetry(): void
    {
        $this->increment('retry_count');
    }
}
