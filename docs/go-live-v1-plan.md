# Go-Live v1 Implementation Plan

**Document Version:** 1.0
**Date:** December 30, 2024
**Target Go-Live:** January 5-6, 2026

---

## Table of Contents
1. [Executive Summary](#executive-summary)
2. [Current State Analysis](#current-state-analysis)
3. [Code Changes Required](#code-changes-required)
4. [Configuration Changes](#configuration-changes)
5. [Infrastructure Requirements](#infrastructure-requirements)
6. [Testing Plan](#testing-plan)
7. [Timeline](#timeline)
8. [Go-Live Checklist](#go-live-checklist)

---

## Executive Summary

### Goal
Make the Tether Laravel application **production-ready** and **horizontally scalable** by making it fully stateless.

### Core Requirements
1. **Stateless Application**: No data stored on local disk
2. **Fast Webhooks**: EMP webhook must respond under 100ms
3. **Idempotency**: Duplicate webhook events handled safely
4. **Horizontal Scaling**: Support multiple API nodes behind load balancer

### Architecture Changes
- **File Storage**: Local disk → MinIO (S3-compatible)
- **Webhook Processing**: Synchronous → Asynchronous (queue-based)
- **Idempotency**: Redis-based deduplication
- **Infrastructure**: Single server → Load balanced multi-node setup

---

## Current State Analysis

### What's Working Well ✅
- Jobs use proper queue architecture with batching
- Jobs implement idempotency via `ShouldBeUnique`
- Circuit breakers and rate limiting in place
- Reconciliation system for webhook failure recovery
- Health endpoint exists (`/health`)

### Critical Issues ❌

#### Issue 1: Local File Storage
**Location**: `app/Services/FileUploadService.php:157`
```php
// Current: Stores files locally
return $file->storeAs('uploads', $filename, 'local');
```

**Impact**:
- Files stored on local disk (`storage/app/uploads/`)
- Cannot scale horizontally (each node has different files)
- Upload on Node A not accessible from Node B

**Solution**: Store all files in MinIO (S3)

---

#### Issue 2: Synchronous Webhook Processing
**Location**: `app/Http/Controllers/Webhook/EmpWebhookController.php:61-104`

**Current behavior**:
```php
public function handleChargeback(array $data): JsonResponse
{
    // Line 61: DB query
    $billingAttempt = BillingAttempt::where('transaction_id', $originalTxId)->first();

    // Line 71-85: DB update with complex meta merge
    $billingAttempt->update([...]);

    // Line 90-104: Blacklist service (more DB queries)
    $this->blacklistService->addDebtor(...);

    return response()->json(['status' => 'ok']);
}
```

**Impact**:
- Webhook response time: 200-500ms (too slow)
- EMP expects fast 200 response (<100ms)
- Blocks HTTP worker thread
- Risk of timeout on slow DB queries

**Solution**: Signature validation + queue dispatch + immediate 200 response

---

#### Issue 3: No Webhook Idempotency
**Location**: `app/Http/Controllers/Webhook/EmpWebhookController.php:27`

**Current behavior**:
- No check for duplicate events
- If EMP sends same webhook twice → processed twice
- Risk of duplicate charges, double blacklisting

**Solution**: Redis-based deduplication with 24-hour TTL

---

## Code Changes Required

### A) File Storage Migration (Local → S3/MinIO)

#### Task A1: Update FileUploadService to use S3
**File**: `app/Services/FileUploadService.php`
**Priority**: 🔴 Critical
**Estimated Time**: 15 minutes

**Current code (line 154-158)**:
```php
private function storeFile(UploadedFile $file): string
{
    $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
    return $file->storeAs('uploads', $filename, 'local'); // ❌ Local storage
}
```

**New code**:
```php
private function storeFile(UploadedFile $file): string
{
    $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
    return $file->storeAs('uploads', $filename, 's3'); // ✅ S3 storage
}
```

---

#### Task A2: Update ProcessUploadJob to read from S3
**File**: `app/Jobs/ProcessUploadJob.php`
**Priority**: 🔴 Critical
**Estimated Time**: 30 minutes

**Current code (line 62-66)**:
```php
$filePath = storage_path('app/' . $this->upload->file_path);

if (!file_exists($filePath)) {
    throw new \RuntimeException("File not found: {$filePath}");
}
```

**New code**:
```php
use Illuminate\Support\Facades\Storage;

// Download from S3 to temporary location
if (!Storage::disk('s3')->exists($this->upload->file_path)) {
    throw new \RuntimeException("File not found in S3: {$this->upload->file_path}");
}

$s3Content = Storage::disk('s3')->get($this->upload->file_path);
$filePath = tempnam(sys_get_temp_dir(), 'upload_');
file_put_contents($filePath, $s3Content);

try {
    // Parse file (existing logic)
    $parsed = $this->parseFile($parser, $filePath);
    $rows = $parsed['rows'];

    // ... rest of processing logic

} finally {
    // Clean up temp file
    @unlink($filePath);
}
```

**Alternative approach (streaming, if parser supports)**:
```php
// If SpreadsheetParserService supports streams/strings
$stream = Storage::disk('s3')->readStream($this->upload->file_path);
$parsed = $parser->parseStream($stream);
```

---

#### Task A3: Verify SpreadsheetParserService compatibility
**File**: `app/Services/SpreadsheetParserService.php`
**Priority**: 🟡 Important
**Estimated Time**: 15 minutes

**Check**:
1. Does `League\Csv\Reader` need file path or can use streams?
2. Does `PhpOffice\PhpSpreadsheet` need file path or can use streams?

**Note**: Most likely both need file paths, so Task A2's temp file approach is correct.

---

#### Task A4: Update file download endpoints (if any)
**Files to check**: Search for controllers that serve files
**Priority**: 🟡 Important
**Estimated Time**: 15 minutes

**Search for**:
```bash
grep -r "Storage::download" app/Http/Controllers/
grep -r "response()->download" app/Http/Controllers/
```

**Change from**:
```php
return Storage::disk('local')->download($upload->file_path);
```

**To**:
```php
return Storage::disk('s3')->download($upload->file_path);
```

Or use dynamic disk:
```php
return Storage::download($upload->file_path); // Uses default disk from config
```

---

#### Task A5: Migration script for existing files (Optional)
**File**: `app/Console/Commands/MigrateFilesToS3.php` (new)
**Priority**: 🟢 Nice to have (only if existing data)
**Estimated Time**: 45 minutes

**Create command**:
```php
<?php

namespace App\Console\Commands;

use App\Models\Upload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigrateFilesToS3 extends Command
{
    protected $signature = 'files:migrate-to-s3 {--dry-run}';
    protected $description = 'Migrate existing local files to S3';

    public function handle(): int
    {
        $uploads = Upload::whereNotNull('file_path')->get();
        $this->info("Found {$uploads->count()} uploads to migrate");

        $migrated = 0;
        $failed = 0;

        foreach ($uploads as $upload) {
            $localPath = storage_path('app/' . $upload->file_path);

            if (!file_exists($localPath)) {
                $this->warn("Local file not found: {$localPath}");
                $failed++;
                continue;
            }

            if ($this->option('dry-run')) {
                $this->info("Would migrate: {$upload->file_path}");
                continue;
            }

            try {
                // Upload to S3
                $content = file_get_contents($localPath);
                Storage::disk('s3')->put($upload->file_path, $content);

                // Verify
                if (Storage::disk('s3')->exists($upload->file_path)) {
                    $this->info("Migrated: {$upload->file_path}");
                    $migrated++;

                    // Optionally delete local file
                    // unlink($localPath);
                } else {
                    $this->error("Failed to verify S3 upload: {$upload->file_path}");
                    $failed++;
                }
            } catch (\Exception $e) {
                $this->error("Error migrating {$upload->file_path}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("Migration complete: {$migrated} succeeded, {$failed} failed");
        return $failed > 0 ? 1 : 0;
    }
}
```

**Usage**:
```bash
# Dry run first
php artisan files:migrate-to-s3 --dry-run

# Actual migration
php artisan files:migrate-to-s3
```

---

### B) Webhook Optimization (Synchronous → Asynchronous)

#### Task B1: Create ProcessEmpWebhookJob
**File**: `app/Jobs/ProcessEmpWebhookJob.php` (new)
**Priority**: 🔴 Critical
**Estimated Time**: 45 minutes

**Full implementation**:
```php
<?php

namespace App\Jobs;

use App\Models\BillingAttempt;
use App\Services\BlacklistService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Process EMP webhook notifications asynchronously.
 *
 * This job handles the actual business logic for webhook notifications,
 * allowing the controller to return a fast 200 response.
 */
class ProcessEmpWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public array $backoff = [10, 30, 60];

    public function __construct(
        public array $webhookData,
        public string $transactionType
    ) {
        $this->onQueue('webhooks'); // Dedicated high-priority queue
    }

    public function handle(BlacklistService $blacklistService): void
    {
        Log::info('ProcessEmpWebhookJob started', [
            'transaction_type' => $this->transactionType,
            'unique_id' => $this->webhookData['unique_id'] ?? null,
        ]);

        match ($this->transactionType) {
            'chargeback' => $this->handleChargeback($blacklistService),
            'sdd_sale' => $this->handleTransaction(),
            default => Log::info('Unknown webhook type', ['type' => $this->transactionType]),
        };
    }

    private function handleChargeback(BlacklistService $blacklistService): void
    {
        $data = $this->webhookData;
        $originalTxId = $data['original_transaction_unique_id'] ?? null;

        if (!$originalTxId) {
            Log::error('Chargeback missing original_transaction_unique_id', $data);
            return;
        }

        // Find original billing attempt
        $billingAttempt = BillingAttempt::where('transaction_id', $originalTxId)->first();

        if (!$billingAttempt) {
            Log::warning('Chargeback for unknown transaction', ['unique_id' => $originalTxId]);
            return;
        }

        $errorCode = $data['reason_code'] ?? $data['error_code'] ?? null;

        // Update status to chargebacked
        $billingAttempt->update([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'error_code' => $errorCode,
            'error_message' => $data['reason'] ?? null,
            'meta' => array_merge($billingAttempt->meta ?? [], [
                'chargeback' => [
                    'unique_id' => $data['unique_id'] ?? null,
                    'amount' => $data['amount'] ?? null,
                    'currency' => $data['currency'] ?? null,
                    'reason' => $data['reason'] ?? null,
                    'reason_code' => $errorCode,
                    'received_at' => now()->toIso8601String(),
                ],
            ]),
        ]);

        // Auto-blacklist debtor if error code matches
        $blacklisted = false;
        if ($errorCode && $this->shouldBlacklistCode($errorCode)) {
            $debtor = $billingAttempt->debtor;
            if ($debtor && $debtor->iban) {
                $blacklistService->addDebtor(
                    $debtor,
                    'chargeback',
                    "Auto-blacklisted: {$errorCode}"
                );
                $blacklisted = true;
                Log::info('Debtor auto-blacklisted due to chargeback', [
                    'debtor_id' => $debtor->id,
                    'iban' => $debtor->iban,
                    'name' => $debtor->first_name . ' ' . $debtor->last_name,
                    'error_code' => $errorCode,
                ]);
            }
        }

        Log::info('Chargeback processed', [
            'billing_attempt_id' => $billingAttempt->id,
            'debtor_id' => $billingAttempt->debtor_id,
            'original_tx' => $originalTxId,
            'error_code' => $errorCode,
            'blacklisted' => $blacklisted,
        ]);
    }

    private function handleTransaction(): void
    {
        $data = $this->webhookData;
        $uniqueId = $data['unique_id'] ?? null;
        $status = $data['status'] ?? null;

        if (!$uniqueId) {
            Log::warning('Transaction notification missing unique_id', $data);
            return;
        }

        $billingAttempt = BillingAttempt::where('transaction_id', $uniqueId)->first();

        if (!$billingAttempt) {
            Log::info('Transaction notification for unknown tx', ['unique_id' => $uniqueId]);
            return;
        }

        // Map EMP status to our status
        $mappedStatus = $this->mapEmpStatus($status);

        if ($mappedStatus && $billingAttempt->status !== $mappedStatus) {
            $oldStatus = $billingAttempt->status;
            $billingAttempt->update(['status' => $mappedStatus]);
            Log::info('Transaction status updated', [
                'billing_attempt_id' => $billingAttempt->id,
                'old_status' => $oldStatus,
                'new_status' => $mappedStatus,
            ]);
        }
    }

    private function shouldBlacklistCode(string $code): bool
    {
        $blacklistCodes = config('tether.chargeback.blacklist_codes', []);
        return in_array($code, $blacklistCodes);
    }

    private function mapEmpStatus(?string $empStatus): ?string
    {
        return match ($empStatus) {
            'approved' => BillingAttempt::STATUS_APPROVED,
            'declined' => BillingAttempt::STATUS_DECLINED,
            'error' => BillingAttempt::STATUS_ERROR,
            'voided' => BillingAttempt::STATUS_VOIDED,
            'pending', 'pending_async' => BillingAttempt::STATUS_PENDING,
            default => null,
        };
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessEmpWebhookJob failed', [
            'transaction_type' => $this->transactionType,
            'unique_id' => $this->webhookData['unique_id'] ?? null,
            'error' => $exception->getMessage(),
        ]);
    }
}
```

---

#### Task B2: Refactor EmpWebhookController to be fast
**File**: `app/Http/Controllers/Webhook/EmpWebhookController.php`
**Priority**: 🔴 Critical
**Estimated Time**: 30 minutes

**New implementation**:
```php
<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessEmpWebhookJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EmpWebhookController extends Controller
{
    /**
     * Handle incoming EMP notification.
     *
     * This endpoint MUST be fast (<100ms) to avoid EMP timeouts.
     * All business logic is handled asynchronously by ProcessEmpWebhookJob.
     */
    public function handle(Request $request): JsonResponse
    {
        $data = $request->all();

        // Step 1: Verify signature (FAST - simple hash check)
        if (!$this->verifySignature($request)) {
            Log::warning('EMP webhook invalid signature', [
                'ip' => $request->ip(),
                'unique_id' => $data['unique_id'] ?? null,
            ]);
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // Step 2: Check idempotency (FAST - Redis lookup)
        $uniqueKey = $this->getWebhookUniqueKey($data);
        if (Cache::has($uniqueKey)) {
            Log::info('EMP webhook duplicate ignored', [
                'key' => $uniqueKey,
                'unique_id' => $data['unique_id'] ?? null,
            ]);
            return response()->json(['status' => 'ok', 'message' => 'Already processed']);
        }

        // Step 3: Mark as received (FAST - Redis write)
        Cache::put($uniqueKey, [
            'received_at' => now()->toIso8601String(),
            'type' => $data['transaction_type'] ?? 'unknown',
        ], now()->addHours(24));

        // Step 4: Quick log (FAST - async write)
        Log::info('EMP webhook received', [
            'unique_id' => $data['unique_id'] ?? null,
            'transaction_type' => $data['transaction_type'] ?? null,
            'status' => $data['status'] ?? null,
        ]);

        // Step 5: Dispatch async job (FAST - queue push)
        ProcessEmpWebhookJob::dispatch(
            $data,
            $data['transaction_type'] ?? 'unknown'
        );

        // Step 6: Return immediately (FAST)
        return response()->json(['status' => 'ok']);
    }

    /**
     * Verify EMP webhook signature.
     */
    private function verifySignature(Request $request): bool
    {
        $signature = $request->input('signature');
        $uniqueId = $request->input('unique_id');
        $apiPassword = config('services.emp.password');

        if (!$signature || !$uniqueId || !$apiPassword) {
            return false;
        }

        $expectedSignature = hash('sha1', $uniqueId . $apiPassword);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Generate unique cache key for webhook deduplication.
     */
    private function getWebhookUniqueKey(array $data): string
    {
        $uniqueId = $data['unique_id'] ?? '';
        $type = $data['transaction_type'] ?? 'unknown';
        return "webhook:{$type}:{$uniqueId}";
    }
}
```

**What was removed**:
- ❌ `handleChargeback()` → Moved to ProcessEmpWebhookJob
- ❌ `handleTransaction()` → Moved to ProcessEmpWebhookJob
- ❌ `mapEmpStatus()` → Moved to ProcessEmpWebhookJob
- ❌ `shouldBlacklistCode()` → Moved to ProcessEmpWebhookJob
- ❌ `handleUnknown()` → No longer needed (handled in job)
- ❌ Constructor dependencies (BlacklistService, IbanValidator) → No longer needed

**What was added**:
- ✅ `getWebhookUniqueKey()` → Idempotency key generation
- ✅ Cache-based deduplication
- ✅ ProcessEmpWebhookJob dispatch

**Performance comparison**:
- **Before**: 200-500ms (DB queries, updates, blacklisting)
- **After**: <50ms (signature check + Redis check + queue dispatch)

---

### C) Webhook Idempotency

#### Task C1: Redis-based deduplication
**Status**: ✅ Already implemented in Task B2

**How it works**:
1. Generate unique key: `webhook:{transaction_type}:{unique_id}`
2. Check if key exists in Redis: `Cache::has($uniqueKey)`
3. If exists → return 200 (already processed)
4. If not exists → store key with 24-hour TTL: `Cache::put($uniqueKey, [...], now()->addHours(24))`
5. Dispatch job for processing

**Why 24 hours?**
- EMP typically retries webhooks within minutes/hours
- 24 hours provides safety buffer
- Old entries auto-expire (no cleanup needed)

---

#### Task C2: Database audit table (Optional - v2)
**File**: `database/migrations/xxxx_create_webhook_events_table.php` (new)
**Priority**: 🟢 Nice to have (defer to v1.1)
**Estimated Time**: 30 minutes

**Use case**: Permanent audit trail of all webhook events

**Schema**:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('unique_id')->index();
            $table->string('transaction_type');
            $table->string('status')->default('received');
            $table->json('payload');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            // Unique constraint for idempotency
            $table->unique(['unique_id', 'transaction_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
```

**Note**: For v1, Redis is sufficient. Add this in v1.1 if audit trail is needed.

---

## Configuration Changes

### D1: Environment Variables (.env)

#### Production settings
**File**: `.env` (production server)
**Priority**: 🔴 Critical

```bash
# ============================================
# APPLICATION
# ============================================
APP_NAME=Tether
APP_ENV=production
APP_KEY=base64:your_production_key_here
APP_DEBUG=false
APP_URL=https://api.yourdomain.com

# ============================================
# LOGGING
# ============================================
LOG_CHANNEL=stderr
LOG_LEVEL=info
LOG_DEPRECATIONS_CHANNEL=null
LOG_STACK=single

# ============================================
# DATABASE
# ============================================
DB_CONNECTION=pgsql
DB_HOST=postgres  # Or managed Postgres host
DB_PORT=5432
DB_DATABASE=tether
DB_USERNAME=tether_user
DB_PASSWORD=your_secure_password

# PgBouncer (recommended for connection pooling)
# If using PgBouncer, point DB_HOST to PgBouncer instead
# DB_HOST=pgbouncer
# DB_PORT=6432

# ============================================
# FILE STORAGE (MinIO / S3)
# ============================================
FILESYSTEM_DISK=s3

# MinIO Configuration
AWS_ACCESS_KEY_ID=your_minio_access_key
AWS_SECRET_ACCESS_KEY=your_minio_secret_key
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=tether-uploads
AWS_ENDPOINT=http://minio:9000  # Internal network
AWS_URL=http://minio:9000/tether-uploads
AWS_USE_PATH_STYLE_ENDPOINT=true

# ============================================
# CACHE & SESSION
# ============================================
CACHE_DRIVER=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120

# ============================================
# QUEUE
# ============================================
QUEUE_CONNECTION=redis

# ============================================
# REDIS
# ============================================
REDIS_CLIENT=phpredis
REDIS_HOST=redis  # Internal network
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_DB=0
REDIS_CACHE_DB=1

# ============================================
# EMERCHANTPAY (EMP)
# ============================================
EMP_TERMINAL_TOKEN=your_emp_terminal_token
EMP_API_PASSWORD=your_emp_api_password
EMP_API_URL=https://gate.emerchantpay.net/
EMP_TIMEOUT=30
EMP_CONNECT_TIMEOUT=10

# ============================================
# RATE LIMITING
# ============================================
EMP_RATE_LIMIT=50  # Requests per second to EMP API
RECONCILIATION_RATE_LIMIT=20

# ============================================
# HORIZON
# ============================================
HORIZON_BALANCE=auto
HORIZON_ENVIRONMENTS=production
```

---

#### Update .env.example
**File**: `.env.example`
**Priority**: 🟡 Important

Add comprehensive comments and all new variables:
```bash
# ... (same as above, but with example values and comments)

# File Storage
# Use 's3' for production (MinIO/S3), 'local' for development
FILESYSTEM_DISK=s3

# MinIO Configuration (S3-compatible object storage)
# Internal network endpoint (not exposed to public)
AWS_ENDPOINT=http://minio:9000
AWS_USE_PATH_STYLE_ENDPOINT=true  # Required for MinIO
```

---

### D2: Horizon Configuration

#### Configure queue priorities
**File**: `config/horizon.php`
**Priority**: 🔴 Critical
**Estimated Time**: 15 minutes

**Update environments section**:
```php
<?php

return [
    'environments' => [
        'production' => [
            // High priority: Webhooks
            'webhooks' => [
                'connection' => 'redis',
                'queue' => ['webhooks'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'processes' => 3,
                'minProcesses' => 2,
                'maxProcesses' => 5,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
                'tries' => 3,
                'timeout' => 120,
            ],

            // Medium priority: Billing & Reconciliation
            'billing' => [
                'connection' => 'redis',
                'queue' => ['billing', 'reconciliation'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'processes' => 5,
                'minProcesses' => 3,
                'maxProcesses' => 10,
                'tries' => 3,
                'timeout' => 600,
            ],

            // Standard priority: Upload processing, VOP
            'default' => [
                'connection' => 'redis',
                'queue' => ['default', 'vop'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'processes' => 3,
                'minProcesses' => 2,
                'maxProcesses' => 8,
                'tries' => 3,
                'timeout' => 600,
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'connection' => 'redis',
                'queue' => ['default', 'webhooks', 'billing', 'reconciliation', 'vop'],
                'balance' => 'auto',
                'processes' => 3,
                'tries' => 3,
                'timeout' => 600,
            ],
        ],
    ],

    // ... rest of config
];
```

**Key decisions**:
- **Webhooks queue**: Separate supervisor with dedicated workers
- **Process counts**: Tuned for webhook throughput (adjust based on load testing)
- **Timeout**: 120s for webhooks (should be much faster), 600s for others

---

### D3: Filesystems Configuration

#### Verify S3 disk setup
**File**: `config/filesystems.php`
**Priority**: 🟡 Important
**Estimated Time**: 5 minutes

**Verify this exists** (already in codebase):
```php
'disks' => [
    's3' => [
        'driver' => 's3',
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION'),
        'bucket' => env('AWS_BUCKET'),
        'url' => env('AWS_URL'),
        'endpoint' => env('AWS_ENDPOINT'),
        'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
        'throw' => false,
    ],
],
```

**Action**: No changes needed if above exists. Just verify it's correct.

---

## Infrastructure Requirements

### Minimum Setup for Go-Live v1

```
┌─────────────────────────────────────────────────────────┐
│                    Load Balancer (Nginx)                │
│              Public IP + SSL (Let's Encrypt)            │
│                  Health check: /health                  │
└─────────────────────────────────────────────────────────┘
                            │
        ┌───────────────────┼───────────────────┐
        │                   │                   │
        ▼                   ▼                   ▼
┌───────────────┐   ┌───────────────┐   ┌───────────────┐
│  API Node 1   │   │  API Node 2   │   │ Worker Node(s)│
│  (Laravel)    │   │  (Laravel)    │   │  (Horizon)    │
│  - Nginx      │   │  - Nginx      │   │               │
│  - PHP-FPM    │   │  - PHP-FPM    │   │  Processes:   │
│  - Stateless  │   │  - Stateless  │   │  - webhooks   │
│               │   │               │   │  - billing    │
│  Private IP   │   │  Private IP   │   │  - default    │
└───────────────┘   └───────────────┘   └───────────────┘
        │                   │                   │
        └───────────────────┼───────────────────┘
                            │
        ┌───────────────────┼───────────────────┐
        │                   │                   │
        ▼                   ▼                   ▼
┌───────────────┐   ┌───────────────┐   ┌───────────────┐
│     Redis     │   │     MinIO     │   │   Postgres    │
│   (Queue +    │   │  (S3 Storage) │   │   + pgBouncer │
│    Cache)     │   │               │   │               │
│  Private IP   │   │  Private IP   │   │  Private IP   │
└───────────────┘   └───────────────┘   └───────────────┘
        │                   │                   │
        └───────────────────┴───────────────────┘
                   Private Network Only
                   (No public access)
```

### Component Details

#### Load Balancer
- **Software**: Nginx or HAProxy
- **SSL**: Let's Encrypt (automated renewal)
- **Health check**: `GET /health` every 10 seconds
- **Fail threshold**: 3 consecutive failures
- **Timeout**: 60 seconds
- **Algorithm**: Round-robin or least connections

**Nginx config example**:
```nginx
upstream api_backend {
    least_conn;
    server api-node-1:80 max_fails=3 fail_timeout=30s;
    server api-node-2:80 max_fails=3 fail_timeout=30s;
}

server {
    listen 443 ssl http2;
    server_name api.yourdomain.com;

    ssl_certificate /etc/letsencrypt/live/api.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.yourdomain.com/privkey.pem;

    location / {
        proxy_pass http://api_backend;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_connect_timeout 60s;
        proxy_send_timeout 60s;
        proxy_read_timeout 60s;
    }

    location /health {
        proxy_pass http://api_backend;
        access_log off;
    }
}
```

---

#### API Nodes (2x minimum)
- **OS**: Ubuntu 22.04 LTS
- **Web server**: Nginx
- **PHP**: 8.2+ with FPM
- **Extensions**: pdo_pgsql, redis, gd, mbstring, xml, zip
- **CPU**: 2-4 vCPUs
- **RAM**: 4-8 GB
- **Disk**: 20 GB (minimal, since files on S3)
- **Network**: Private network + access to LB

**Key requirement**: STATELESS (no local file storage)

---

#### Worker Nodes (1+ recommended)
- **Same specs as API nodes**
- **No Nginx** (only Horizon workers)
- **Horizon**: 3 supervisors (webhooks, billing, default)
- **Auto-restart**: Supervisor or systemd

**Scaling**: Start with 1 worker, add more based on queue depth

---

#### Redis
- **Version**: 7.x
- **RAM**: 2-4 GB (for queue + cache)
- **Persistence**: RDB snapshots (optional, queue can replay)
- **Network**: Private IP only
- **Managed service recommended**: DigitalOcean Managed Redis, AWS ElastiCache

**Config**:
```conf
maxmemory 2gb
maxmemory-policy allkeys-lru
```

---

#### MinIO (S3 Storage)
- **Version**: Latest stable
- **Storage**: 50-100 GB (start small, scale as needed)
- **RAM**: 2 GB minimum
- **Network**: Private IP only
- **Bucket**: `tether-uploads` (create before go-live)

**Setup**:
```bash
# Create bucket
mc alias set myminio http://minio:9000 ACCESS_KEY SECRET_KEY
mc mb myminio/tether-uploads
mc policy set download myminio/tether-uploads  # Or keep private
```

---

#### Postgres + pgBouncer
- **Postgres version**: 14+
- **RAM**: 4-8 GB
- **Disk**: 50-100 GB SSD
- **Network**: Private IP only
- **Managed service recommended**: DigitalOcean Managed Postgres, AWS RDS

**pgBouncer** (connection pooling):
- **Pool mode**: Transaction
- **Max connections**: 100-200
- **Default pool size**: 25

**Why pgBouncer?**
- Laravel apps can leak connections
- Workers open many connections
- pgBouncer pools connections efficiently

---

#### Next.js Frontend
- **Node.js**: 18+ LTS
- **RAM**: 2 GB
- **CPU**: 1-2 vCPUs
- **Network**: Public IP or behind same LB

**Not covered in this document** (separate deployment)

---

### Security Checklist

- [ ] Redis not publicly accessible (private network only)
- [ ] MinIO not publicly accessible (private network only)
- [ ] Postgres not publicly accessible (private network only)
- [ ] SSH key-only access (disable password auth)
- [ ] Firewall rules: Allow only necessary ports
- [ ] SSL certificate auto-renewal enabled
- [ ] EMP webhook endpoint uses HTTPS
- [ ] Environment variables not committed to Git
- [ ] Strong passwords for DB, Redis, MinIO

---

## Testing Plan

### Unit Tests

#### Test E1: Webhook Idempotency
**File**: `tests/Feature/WebhookIdempotencyTest.php` (new)
**Priority**: 🔴 Critical

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_webhook_is_ignored(): void
    {
        Queue::fake();

        $payload = [
            'unique_id' => 'test-12345',
            'transaction_type' => 'sdd_sale',
            'status' => 'approved',
            'signature' => $this->generateSignature('test-12345'),
        ];

        // First request
        $response1 = $this->postJson('/api/webhooks/emp', $payload);
        $response1->assertStatus(200);

        // Second request (duplicate)
        $response2 = $this->postJson('/api/webhooks/emp', $payload);
        $response2->assertStatus(200);
        $response2->assertJson(['message' => 'Already processed']);

        // Job dispatched only once
        Queue::assertPushed(\App\Jobs\ProcessEmpWebhookJob::class, 1);
    }

    public function test_different_webhooks_are_processed(): void
    {
        Queue::fake();

        $payload1 = [
            'unique_id' => 'test-11111',
            'transaction_type' => 'sdd_sale',
            'status' => 'approved',
            'signature' => $this->generateSignature('test-11111'),
        ];

        $payload2 = [
            'unique_id' => 'test-22222',
            'transaction_type' => 'sdd_sale',
            'status' => 'approved',
            'signature' => $this->generateSignature('test-22222'),
        ];

        $this->postJson('/api/webhooks/emp', $payload1)->assertStatus(200);
        $this->postJson('/api/webhooks/emp', $payload2)->assertStatus(200);

        Queue::assertPushed(\App\Jobs\ProcessEmpWebhookJob::class, 2);
    }

    private function generateSignature(string $uniqueId): string
    {
        return hash('sha1', $uniqueId . config('services.emp.password'));
    }
}
```

---

#### Test E2: S3 File Upload
**File**: `tests/Feature/S3FileUploadTest.php` (new)
**Priority**: 🔴 Critical

```php
<?php

namespace Tests\Feature;

use App\Models\Upload;
use App\Services\FileUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class S3FileUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
    }

    public function test_file_is_stored_in_s3(): void
    {
        $file = UploadedFile::fake()->create('test.csv', 100);

        $service = app(FileUploadService::class);
        $result = $service->process($file);

        $upload = $result['upload'];
        $this->assertNotNull($upload->file_path);

        // Assert file exists in S3
        Storage::disk('s3')->assertExists($upload->file_path);
    }

    public function test_upload_job_can_read_from_s3(): void
    {
        Storage::fake('s3');

        $csvContent = "IBAN,Name,Amount\nDE89370400440532013000,John Doe,100.00";
        Storage::disk('s3')->put('uploads/test.csv', $csvContent);

        $upload = Upload::create([
            'filename' => 'test.csv',
            'original_filename' => 'test.csv',
            'file_path' => 'uploads/test.csv',
            'file_size' => strlen($csvContent),
            'mime_type' => 'text/csv',
            'status' => Upload::STATUS_PENDING,
            'total_records' => 1,
        ]);

        // Job should be able to read from S3
        $job = new \App\Jobs\ProcessUploadJob($upload, ['IBAN' => 'iban', 'Name' => 'name', 'Amount' => 'amount']);
        $job->handle(
            app(\App\Services\SpreadsheetParserService::class),
            app(\App\Services\IbanValidator::class),
            app(\App\Services\BlacklistService::class),
            app(\App\Services\DeduplicationService::class)
        );

        $this->assertEquals(Upload::STATUS_COMPLETED, $upload->fresh()->status);
    }
}
```

---

### Integration Tests

#### Test E3: Webhook End-to-End Flow
**Priority**: 🟡 Important

**Manual test**:
1. Send test webhook to staging
2. Verify controller returns 200 in <100ms
3. Check Redis for idempotency key
4. Verify job is queued in Horizon
5. Verify job processes successfully
6. Verify database updated correctly

---

### Load Testing

#### Test E4: Webhook Load Test
**Tool**: Apache Bench or k6
**Priority**: 🔴 Critical

**Test script (k6)**:
```javascript
// webhook-load-test.js
import http from 'k6/http';
import { check } from 'k6';
import { crypto } from 'k6/experimental/webcrypto';

export let options = {
  stages: [
    { duration: '1m', target: 50 },   // Ramp up to 50 RPS
    { duration: '3m', target: 50 },   // Stay at 50 RPS
    { duration: '1m', target: 100 },  // Ramp up to 100 RPS
    { duration: '3m', target: 100 },  // Stay at 100 RPS
    { duration: '1m', target: 0 },    // Ramp down
  ],
  thresholds: {
    http_req_duration: ['p(95)<100'], // 95% of requests under 100ms
    http_req_failed: ['rate<0.01'],   // Less than 1% failures
  },
};

const API_URL = 'https://staging-api.yourdomain.com/api/webhooks/emp';
const API_PASSWORD = 'your_emp_password';

export default function () {
  const uniqueId = `test-${Date.now()}-${Math.random()}`;
  const signature = generateSignature(uniqueId);

  const payload = JSON.stringify({
    unique_id: uniqueId,
    transaction_type: 'sdd_sale',
    status: 'approved',
    signature: signature,
  });

  const params = {
    headers: { 'Content-Type': 'application/json' },
  };

  const res = http.post(API_URL, payload, params);

  check(res, {
    'status is 200': (r) => r.status === 200,
    'response time < 100ms': (r) => r.timings.duration < 100,
  });
}

function generateSignature(uniqueId) {
  // Note: k6 crypto is limited, you may need to use external script
  // For now, pre-calculate or use simple hash
  return 'placeholder'; // Replace with actual SHA1(uniqueId + password)
}
```

**Run**:
```bash
k6 run webhook-load-test.js
```

**Success criteria**:
- 95th percentile response time < 100ms
- 99th percentile response time < 200ms
- 0% error rate
- All webhooks processed successfully (check Horizon)

---

### Manual Testing Checklist

**Pre-deployment**:
- [ ] MinIO bucket created and accessible from API nodes
- [ ] MinIO bucket created and accessible from worker nodes
- [ ] Redis accessible from all nodes
- [ ] Postgres accessible from all nodes
- [ ] Load balancer health check returns 200

**Post-deployment**:
- [ ] Upload CSV file → File appears in MinIO (check MinIO console)
- [ ] Upload CSV file → ProcessUploadJob processes correctly
- [ ] Upload CSV file → Download works (if download endpoint exists)
- [ ] Send test webhook → Returns 200 in <100ms
- [ ] Send duplicate webhook → Returns 200 with "Already processed"
- [ ] Check Horizon → Webhook job queued and processed
- [ ] Check database → BillingAttempt updated correctly
- [ ] Send 100 webhooks → All processed successfully
- [ ] Kill API node 1 → Traffic routes to node 2
- [ ] Restart API node 1 → Traffic resumes to both nodes
- [ ] Restart Horizon → Jobs resume processing
- [ ] Check logs → No errors, all logs in structured format

**Load test**:
- [ ] Run k6 webhook load test → Meets performance criteria
- [ ] Monitor queue depth during load test → Doesn't grow unbounded
- [ ] Monitor Redis memory → Stays within limits
- [ ] Monitor Postgres connections → pgBouncer pooling works

---

## Timeline

### December 30-31, 2024: Code Changes
**Owner**: Development Team

**Day 1 (Dec 30)**:
- ✅ Task A1: Update FileUploadService (15 min)
- ✅ Task A2: Update ProcessUploadJob (30 min)
- ✅ Task A4: Update file download endpoints (15 min)
- ✅ Task B1: Create ProcessEmpWebhookJob (45 min)
- ✅ Task B2: Refactor EmpWebhookController (30 min)

**Day 2 (Dec 31)**:
- ✅ Task D1: Update .env.example (15 min)
- ✅ Task D2: Configure Horizon (15 min)
- ✅ Task E1: Write webhook idempotency tests (30 min)
- ✅ Task E2: Write S3 upload tests (30 min)
- ✅ Run all tests locally
- ✅ Code review and merge

**Estimated total**: 3-4 hours of focused work

---

### December 31, 2024 - January 2, 2026: Infrastructure Setup
**Owner**: DevOps Team

**Dec 31**:
- Provision servers (LB, API nodes, worker nodes)
- Set up private network
- Install dependencies (Nginx, PHP, Postgres, Redis, MinIO)

**Jan 1**:
- Configure load balancer + SSL
- Deploy Redis cluster
- Deploy MinIO + create buckets
- Deploy Postgres + pgBouncer

**Jan 2**:
- Deploy Laravel to staging
- Configure Horizon on worker nodes
- Test connectivity (all services can talk)
- Smoke tests (health check, basic upload, basic webhook)

---

### January 3-4, 2026: Testing & Tuning
**Owner**: Full Team

**Jan 3**:
- Integration testing (E3)
- Load testing (E4)
- Fix any issues found
- Monitor logs and metrics

**Jan 4**:
- Final load test (sustained 100 RPS for 1 hour)
- Tune Horizon worker counts based on results
- Tune PHP-FPM pool sizes
- Document any workarounds or issues

---

### January 5-6, 2026: Go-Live
**Owner**: Full Team

**Jan 5 (Monday)** - Target go-live:
- Deploy to production (early morning)
- Monitor for 24 hours
- Keep dev team on standby

**Jan 6 (Tuesday)** - Backup date:
- If issues on Jan 5, use this day to fix and re-deploy

---

## Go-Live Checklist

### Pre-Go-Live (Day Before)

**Code**:
- [ ] All code changes merged to main branch
- [ ] All tests passing (unit + integration)
- [ ] .env.example updated with all required variables

**Infrastructure**:
- [ ] Load balancer configured with SSL
- [ ] 2 API nodes deployed and running
- [ ] 1+ worker nodes deployed with Horizon
- [ ] Redis accessible from all nodes
- [ ] MinIO accessible from all nodes with bucket created
- [ ] Postgres + pgBouncer accessible from all nodes
- [ ] Private network configured (Redis, MinIO, Postgres not public)

**Configuration**:
- [ ] Production .env configured correctly
- [ ] FILESYSTEM_DISK=s3
- [ ] QUEUE_CONNECTION=redis
- [ ] LOG_CHANNEL=stderr, LOG_LEVEL=info
- [ ] APP_DEBUG=false
- [ ] Horizon configured for webhooks queue

**Monitoring**:
- [ ] Log aggregation set up (Papertrail, Logtail, or similar)
- [ ] Basic metrics dashboard (CPU, memory, disk)
- [ ] Horizon dashboard accessible

---

### Go-Live Day

**Deployment** (Early morning, low traffic):
- [ ] Pull latest code on all nodes
- [ ] Run migrations: `php artisan migrate --force`
- [ ] Clear caches: `php artisan cache:clear`, `php artisan config:clear`
- [ ] Restart PHP-FPM: `sudo systemctl restart php8.2-fpm`
- [ ] Restart Horizon: `php artisan horizon:terminate` (supervisor auto-restarts)
- [ ] Verify health check: `curl https://api.yourdomain.com/health`

**Smoke Tests** (15 minutes):
- [ ] Upload test CSV → Verify in MinIO
- [ ] Process upload → Check Horizon, verify debtors created
- [ ] Send test webhook → Check logs, verify job processed
- [ ] Send duplicate webhook → Verify idempotency works

**Load Test** (30 minutes):
- [ ] Run k6 load test at 50 RPS for 10 minutes
- [ ] Check metrics: response time, error rate, queue depth
- [ ] If all green, increase to 100 RPS for 10 minutes

**Monitoring** (First 4 hours):
- [ ] Monitor logs for errors every 15 minutes
- [ ] Monitor Horizon queue depth every 15 minutes
- [ ] Monitor API response times
- [ ] Monitor Redis memory usage
- [ ] Monitor Postgres connection count

**End of Day**:
- [ ] Review all logs for unexpected errors
- [ ] Document any issues encountered
- [ ] Plan fixes for any non-critical issues

---

### Post-Go-Live (First Week)

**Daily**:
- [ ] Check logs for errors
- [ ] Check Horizon for failed jobs
- [ ] Monitor queue depth
- [ ] Monitor API response times

**Weekly**:
- [ ] Review performance metrics
- [ ] Tune worker counts if needed
- [ ] Plan for v1.1 improvements (e.g., webhook audit table)

---

## Rollback Plan

### If Critical Issue After Go-Live

**Scenario**: Webhooks failing, files not uploading, etc.

**Immediate action**:
1. Revert to previous deployment (if available)
2. Or: Quick fix + hot deploy

**Preparation**:
- Keep previous Docker image or Git commit tagged
- Have rollback script ready: `git checkout v1.0.0 && deploy.sh`

**Communication**:
- Notify stakeholders immediately
- Estimate time to fix
- Provide regular updates

---

## Success Metrics

### Day 1 Success Criteria
- ✅ Zero 500 errors
- ✅ Webhook p95 response time < 100ms
- ✅ All uploaded files accessible from all nodes
- ✅ No failed jobs in Horizon (or <1% failure rate)
- ✅ Load balancer distributing traffic correctly

### Week 1 Success Criteria
- ✅ 99.9% uptime
- ✅ Webhook p99 response time < 200ms
- ✅ Zero data loss (all webhooks processed exactly once)
- ✅ Successful horizontal scaling test (add 3rd API node)

---

## Future Improvements (v1.1+)

**Not required for go-live, but nice to have**:

1. **Webhook audit table**: Permanent log of all webhook events (Task C2)
2. **Enhanced health check**: Check DB, Redis, MinIO connectivity (Task F1)
3. **File migration script**: Move existing files to S3 (Task A5)
4. **Metrics dashboard**: Grafana + Prometheus for detailed metrics
5. **Auto-scaling**: Kubernetes or Docker Swarm for automatic scaling
6. **Multi-region**: Deploy to multiple regions for redundancy
7. **CDN**: CloudFlare or AWS CloudFront for static assets

---

## Contact & Escalation

**Development Team**:
- Lead: [Your Name]
- Email: [your-email@domain.com]

**DevOps Team**:
- Lead: [DevOps Lead Name]
- Email: [devops@domain.com]

**Emergency Contact**:
- On-call: [Phone number]
- Slack: #tether-production

**Escalation Path**:
1. Dev team attempts fix (30 minutes)
2. If unresolved, escalate to DevOps
3. If still unresolved, consider rollback

---

## Appendix: Quick Commands

### Deploy to Production
```bash
# On all API nodes + worker nodes
cd /var/www/tether
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:clear
php artisan cache:clear
sudo systemctl restart php8.2-fpm

# On worker nodes only
php artisan horizon:terminate  # Supervisor auto-restarts
```

### Check System Health
```bash
# Health check
curl https://api.yourdomain.com/health

# Horizon status
php artisan horizon:status

# Queue depth
php artisan queue:work --once --queue=webhooks

# Redis memory
redis-cli info memory

# Postgres connections
psql -c "SELECT count(*) FROM pg_stat_activity;"
```

### View Logs
```bash
# Laravel logs (if not using external aggregation)
tail -f storage/logs/laravel.log

# Nginx access logs
tail -f /var/log/nginx/access.log

# Nginx error logs
tail -f /var/log/nginx/error.log

# PHP-FPM logs
tail -f /var/log/php8.2-fpm.log
```

### Emergency: Clear Stuck Queue
```bash
# Clear all jobs from queue (USE WITH CAUTION)
php artisan queue:clear redis --queue=webhooks

# Restart Horizon
php artisan horizon:terminate

# Retry failed jobs
php artisan queue:retry all
```

---

**Document End**

For questions or updates, contact the development team.
