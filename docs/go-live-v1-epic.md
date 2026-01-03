# Epic: Go-Live v1 - Production-Ready Stateless Application

**Epic ID**: THR-GO-LIVE-V1
**Target Date**: January 5-6, 2026
**Priority**: 🔴 Critical
**Status**: Not Started

---

## Epic Overview

**Goal**: Make Tether Laravel application production-ready by implementing horizontal scalability through stateless architecture.

**Success Criteria**:
- ✅ Application is fully stateless (no local disk storage)
- ✅ Webhook response time <100ms (p95)
- ✅ Support 2+ API nodes behind load balancer
- ✅ Zero data loss (idempotent webhook processing)
- ✅ 99.9% uptime

**Dependencies**:
- Infrastructure provisioning (LB, Redis, MinIO, Postgres)
- Production environment access
- Staging environment for testing

---

## Stories & Tasks

### Story 1: File Storage Migration to S3/MinIO
**Story ID**: THR-100
**Priority**: 🔴 Critical
**Points**: 5
**Owner**: Backend Dev

**Description**:
Migrate all file storage from local disk to S3-compatible MinIO to enable stateless architecture and horizontal scaling.

**Acceptance Criteria**:
- [ ] Files uploaded to S3 instead of local disk
- [ ] Jobs can read files from S3
- [ ] File downloads work from S3
- [ ] Tests pass with S3 fake storage

---

#### Task 1.1: Update FileUploadService to use S3
**Task ID**: THR-101
**Priority**: 🔴 Critical
**Points**: 1
**Estimated Time**: 15 minutes
**File**: `app/Services/FileUploadService.php:157`

**Description**:
Change `storeFile()` method to store files on S3 disk instead of local disk.

**Implementation**:
```php
// Change line 157 from:
return $file->storeAs('uploads', $filename, 'local');

// To:
return $file->storeAs('uploads', $filename, 's3');
```

**Testing**:
- Run unit test with Storage::fake('s3')
- Verify file appears in MinIO console

**Status**: ⬜ Not Started

---

#### Task 1.2: Update ProcessUploadJob to read from S3
**Task ID**: THR-102
**Priority**: 🔴 Critical
**Points**: 2
**Estimated Time**: 30 minutes
**File**: `app/Jobs/ProcessUploadJob.php:62`

**Description**:
Modify job to download files from S3 to temporary location for parsing, then clean up.

**Implementation**:
```php
// Replace line 62-66
use Illuminate\Support\Facades\Storage;

// Check S3 file exists
if (!Storage::disk('s3')->exists($this->upload->file_path)) {
    throw new \RuntimeException("File not found in S3: {$this->upload->file_path}");
}

// Download to temp location
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

**Testing**:
- Test with Storage::fake('s3')
- Test with real MinIO in staging
- Verify temp files are cleaned up

**Status**: ⬜ Not Started

---

#### Task 1.3: Verify SpreadsheetParserService compatibility
**Task ID**: THR-103
**Priority**: 🟡 Important
**Points**: 1
**Estimated Time**: 15 minutes
**File**: `app/Services/SpreadsheetParserService.php`

**Description**:
Verify that CSV/Excel parsers work with temporary file paths or need adjustments for S3.

**Testing**:
- Test parseCsv() with temp file
- Test parseExcel() with temp file
- Document any limitations

**Status**: ⬜ Not Started

---

#### Task 1.4: Update file download endpoints
**Task ID**: THR-104
**Priority**: 🟡 Important
**Points**: 1
**Estimated Time**: 15 minutes

**Description**:
Find and update any controllers that serve file downloads to use S3 instead of local storage.

**Implementation**:
```bash
# Search for download endpoints
grep -r "Storage::download" app/Http/Controllers/
grep -r "response()->download" app/Http/Controllers/
```

**Change from**:
```php
return Storage::disk('local')->download($path);
```

**To**:
```php
return Storage::disk('s3')->download($path);
// Or use dynamic default disk:
return Storage::download($path);
```

**Testing**:
- Test file download in browser
- Verify correct file served

**Status**: ⬜ Not Started

---

#### Task 1.5: Write S3 upload integration test
**Task ID**: THR-105
**Priority**: 🔴 Critical
**Points**: 2
**Estimated Time**: 30 minutes
**File**: `tests/Feature/S3FileUploadTest.php` (new)

**Description**:
Create integration test to verify file upload, storage, and retrieval from S3.

**Test Cases**:
1. File uploaded → stored in S3
2. ProcessUploadJob → reads from S3 correctly
3. File download → serves from S3

**Implementation**:
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

        $job = new \App\Jobs\ProcessUploadJob($upload, [
            'IBAN' => 'iban',
            'Name' => 'name',
            'Amount' => 'amount'
        ]);

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

**Status**: ⬜ Not Started

---

### Story 2: Async Webhook Processing with Idempotency
**Story ID**: THR-200
**Priority**: 🔴 Critical
**Points**: 8
**Owner**: Backend Dev

**Description**:
Optimize EMP webhook endpoint to respond <100ms by offloading business logic to background jobs, and implement Redis-based idempotency.

**Acceptance Criteria**:
- [ ] Webhook responds in <100ms (signature check + queue dispatch)
- [ ] Duplicate webhooks are ignored (idempotency)
- [ ] All webhook business logic executes in background job
- [ ] Load test passes: 100 RPS with <1% errors

---

#### Task 2.1: Create ProcessEmpWebhookJob
**Task ID**: THR-201
**Priority**: 🔴 Critical
**Points**: 3
**Estimated Time**: 45 minutes
**File**: `app/Jobs/ProcessEmpWebhookJob.php` (new)

**Description**:
Create new queue job to handle webhook business logic asynchronously.

**Implementation**:
- Move `handleChargeback()` logic from controller
- Move `handleTransaction()` logic from controller
- Move `mapEmpStatus()` helper
- Move `shouldBlacklistCode()` helper
- Use `webhooks` queue (dedicated high-priority)
- 3 retries with backoff [10, 30, 60]

**Key Methods**:
- `handle()` - Main entry point
- `handleChargeback()` - Process chargeback notifications
- `handleTransaction()` - Process transaction status updates
- `failed()` - Log permanent failures

**Full implementation**: See `docs/go-live-v1-plan.md` Task B1

**Testing**:
- Unit test: Job processes chargeback correctly
- Unit test: Job processes transaction update correctly
- Unit test: Job fails gracefully on invalid data

**Status**: ⬜ Not Started

---

#### Task 2.2: Refactor EmpWebhookController for speed
**Task ID**: THR-202
**Priority**: 🔴 Critical
**Points**: 2
**Estimated Time**: 30 minutes
**File**: `app/Http/Controllers/Webhook/EmpWebhookController.php`

**Description**:
Refactor controller to only perform fast operations: signature check, idempotency check, job dispatch.

**New Flow**:
1. Verify signature (FAST - hash check)
2. Check idempotency via Redis (FAST - cache lookup)
3. Store webhook key in Redis with 24h TTL (FAST - cache write)
4. Log minimal data (FAST - async write)
5. Dispatch ProcessEmpWebhookJob (FAST - queue push)
6. Return 200 immediately

**Remove**:
- ❌ `handleChargeback()` → Moved to job
- ❌ `handleTransaction()` → Moved to job
- ❌ `mapEmpStatus()` → Moved to job
- ❌ `shouldBlacklistCode()` → Moved to job
- ❌ Constructor dependencies → No longer needed

**Add**:
- ✅ `getWebhookUniqueKey()` → Generate idempotency key

**Full implementation**: See `docs/go-live-v1-plan.md` Task B2

**Testing**:
- Benchmark: Response time <100ms
- Test: Duplicate webhook returns immediately
- Test: Invalid signature rejected

**Status**: ⬜ Not Started

---

#### Task 2.3: Write webhook idempotency test
**Task ID**: THR-203
**Priority**: 🔴 Critical
**Points**: 2
**Estimated Time**: 30 minutes
**File**: `tests/Feature/WebhookIdempotencyTest.php` (new)

**Description**:
Create integration test to verify webhook idempotency works correctly.

**Test Cases**:
1. First webhook → processed
2. Duplicate webhook → ignored, returns "Already processed"
3. Different webhooks → both processed
4. Invalid signature → rejected before idempotency check

**Implementation**: See `docs/go-live-v1-plan.md` Test E1

**Testing**:
- Use Queue::fake() to verify job dispatch count
- Use Cache::fake() to verify idempotency keys
- Test signature generation matches EMP format

**Status**: ⬜ Not Started

---

#### Task 2.4: Configure Horizon for webhooks queue
**Task ID**: THR-204
**Priority**: 🔴 Critical
**Points**: 1
**Estimated Time**: 15 minutes
**File**: `config/horizon.php`

**Description**:
Add dedicated supervisor for high-priority webhook queue.

**Configuration**:
```php
'environments' => [
    'production' => [
        'webhooks' => [
            'connection' => 'redis',
            'queue' => ['webhooks'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'processes' => 3,
            'minProcesses' => 2,
            'maxProcesses' => 5,
            'tries' => 3,
            'timeout' => 120,
        ],
        'billing' => [
            'connection' => 'redis',
            'queue' => ['billing', 'reconciliation'],
            'balance' => 'auto',
            'processes' => 5,
            'tries' => 3,
            'timeout' => 600,
        ],
        'default' => [
            'connection' => 'redis',
            'queue' => ['default', 'vop'],
            'balance' => 'auto',
            'processes' => 3,
            'tries' => 3,
            'timeout' => 600,
        ],
    ],
],
```

**Testing**:
- Verify Horizon shows webhooks queue
- Verify jobs are picked up by webhook workers
- Verify separate from other queues

**Status**: ⬜ Not Started

---

### Story 3: Production Configuration
**Story ID**: THR-300
**Priority**: 🔴 Critical
**Points**: 3
**Owner**: Backend Dev / DevOps

**Description**:
Update application configuration for production deployment with proper logging, queue, cache, and storage settings.

**Acceptance Criteria**:
- [ ] .env.example updated with all required variables
- [ ] Production .env configured correctly
- [ ] Logs output to stderr (for container log aggregation)
- [ ] All services use Redis for queue/cache
- [ ] Default filesystem disk is S3

---

#### Task 3.1: Update .env.example with production settings
**Task ID**: THR-301
**Priority**: 🔴 Critical
**Points**: 1
**Estimated Time**: 15 minutes
**File**: `.env.example`

**Description**:
Add comprehensive production environment variables with comments.

**Key Sections**:
```bash
# ============================================
# APPLICATION
# ============================================
APP_ENV=production
APP_DEBUG=false
LOG_CHANNEL=stderr
LOG_LEVEL=info

# ============================================
# FILE STORAGE (MinIO / S3)
# ============================================
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=your_minio_access_key
AWS_SECRET_ACCESS_KEY=your_minio_secret_key
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=tether-uploads
AWS_ENDPOINT=http://minio:9000
AWS_URL=http://minio:9000/tether-uploads
AWS_USE_PATH_STYLE_ENDPOINT=true

# ============================================
# QUEUE & CACHE
# ============================================
QUEUE_CONNECTION=redis
CACHE_DRIVER=redis
SESSION_DRIVER=redis

# ============================================
# REDIS
# ============================================
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_DB=0
REDIS_CACHE_DB=1

# ============================================
# DATABASE
# ============================================
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=tether
DB_USERNAME=tether_user
DB_PASSWORD=your_secure_password
```

**Testing**:
- Copy .env.example to .env
- Verify all required variables present
- Verify comments are helpful

**Status**: ⬜ Not Started

---

#### Task 3.2: Verify filesystems config for S3
**Task ID**: THR-302
**Priority**: 🟡 Important
**Points**: 0.5
**Estimated Time**: 5 minutes
**File**: `config/filesystems.php`

**Description**:
Verify S3 disk configuration exists and supports MinIO.

**Check**:
- `'endpoint' => env('AWS_ENDPOINT')` present
- `'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT')` present
- Default disk uses FILESYSTEM_DISK env var

**Action**: No changes needed if configuration already exists. Just verify.

**Status**: ⬜ Not Started

---

#### Task 3.3: Create production .env on servers
**Task ID**: THR-303
**Priority**: 🔴 Critical
**Points**: 1.5
**Estimated Time**: 30 minutes

**Description**:
Create production .env files on API and worker nodes with correct values.

**Security Checklist**:
- [ ] No sensitive values committed to Git
- [ ] .env file permissions 600 (owner read/write only)
- [ ] Strong passwords used for DB, Redis, MinIO
- [ ] EMP credentials correct for production

**Required Variables**: See Task 3.1 for full list

**Status**: ⬜ Not Started

---

### Story 4: Load Testing & Performance Validation
**Story ID**: THR-400
**Priority**: 🔴 Critical
**Points**: 5
**Owner**: QA / Backend Dev

**Description**:
Validate application performance under load, especially webhook endpoint throughput and response times.

**Acceptance Criteria**:
- [ ] Webhook p95 response time <100ms at 50 RPS
- [ ] Webhook p99 response time <200ms at 100 RPS
- [ ] Zero errors during 10-minute sustained load test
- [ ] Queue workers keep up with incoming jobs
- [ ] No memory leaks or resource exhaustion

---

#### Task 4.1: Create k6 load test script
**Task ID**: THR-401
**Priority**: 🔴 Critical
**Points**: 2
**Estimated Time**: 1 hour
**File**: `tests/load/webhook-load-test.js` (new)

**Description**:
Create k6 load test script to simulate EMP webhook traffic.

**Test Stages**:
1. Ramp up to 50 RPS over 1 minute
2. Sustain 50 RPS for 3 minutes
3. Ramp up to 100 RPS over 1 minute
4. Sustain 100 RPS for 3 minutes
5. Ramp down to 0 over 1 minute

**Thresholds**:
- `http_req_duration` p95 <100ms
- `http_req_duration` p99 <200ms
- `http_req_failed` <1%

**Implementation**: See `docs/go-live-v1-plan.md` Test E4

**Testing**:
- Run against staging environment
- Monitor Horizon queue depth
- Monitor Redis memory
- Monitor API node CPU/memory

**Status**: ⬜ Not Started

---

#### Task 4.2: Run load test on staging
**Task ID**: THR-402
**Priority**: 🔴 Critical
**Points**: 2
**Estimated Time**: 2 hours
**Dependencies**: THR-401, THR-500 (infrastructure)

**Description**:
Execute load test on staging environment and tune configuration based on results.

**Steps**:
1. Deploy all code changes to staging
2. Verify infrastructure ready (Redis, MinIO, etc.)
3. Run k6 load test
4. Monitor metrics during test
5. Analyze results
6. Tune configuration if needed (worker counts, timeouts)
7. Re-run test to validate improvements

**Success Criteria**:
- All thresholds pass
- No errors in logs
- Queue depth remains stable
- Resource usage within acceptable limits

**Deliverable**: Load test report with screenshots

**Status**: ⬜ Not Started

---

#### Task 4.3: Document performance tuning
**Task ID**: THR-403
**Priority**: 🟡 Important
**Points**: 1
**Estimated Time**: 30 minutes
**File**: `docs/performance-tuning.md` (new)

**Description**:
Document optimal configuration discovered during load testing.

**Contents**:
- Horizon worker counts per queue
- PHP-FPM pool sizes
- Redis memory settings
- Timeout values
- Rate limiting settings

**Status**: ⬜ Not Started

---

### Story 5: Infrastructure Provisioning & Deployment
**Story ID**: THR-500
**Priority**: 🔴 Critical
**Points**: 13
**Owner**: DevOps

**Description**:
Set up production infrastructure with load balancer, API nodes, worker nodes, and supporting services (Redis, MinIO, Postgres).

**Acceptance Criteria**:
- [ ] Load balancer with SSL configured
- [ ] 2 API nodes deployed and load balanced
- [ ] 1+ worker nodes with Horizon
- [ ] Redis accessible from all nodes (private network)
- [ ] MinIO accessible from all nodes (private network)
- [ ] Postgres + pgBouncer accessible (private network)
- [ ] Health checks working
- [ ] Logs aggregated centrally

---

#### Task 5.1: Provision load balancer
**Task ID**: THR-501
**Priority**: 🔴 Critical
**Points**: 2
**Estimated Time**: 2 hours

**Description**:
Set up Nginx load balancer with SSL certificate and health checks.

**Requirements**:
- Public IP with DNS pointing to it (api.yourdomain.com)
- SSL certificate (Let's Encrypt)
- Health check to /health every 10 seconds
- Load balancing algorithm: least_conn
- Fail threshold: 3 consecutive failures
- Timeout: 60 seconds

**Deliverable**:
- Nginx config file
- SSL auto-renewal configured
- Health check working

**Status**: ⬜ Not Started

---

#### Task 5.2: Provision API nodes (2x)
**Task ID**: THR-502
**Priority**: 🔴 Critical
**Points**: 3
**Estimated Time**: 3 hours

**Description**:
Set up 2 API nodes with Nginx, PHP-FPM, and Laravel application.

**Specs Per Node**:
- Ubuntu 22.04 LTS
- 2-4 vCPUs
- 4-8 GB RAM
- 20 GB disk
- Nginx + PHP 8.2 + FPM
- Extensions: pdo_pgsql, redis, gd, mbstring, xml, zip

**Setup**:
- Install dependencies
- Clone Laravel repo
- Configure .env
- Run migrations (on one node only)
- Configure Nginx site
- Test PHP-FPM pool
- Register with load balancer

**Deliverable**:
- 2 working API nodes
- Both registered in LB upstream

**Status**: ⬜ Not Started

---

#### Task 5.3: Provision worker nodes (1+)
**Task ID**: THR-503
**Priority**: 🔴 Critical
**Points**: 2
**Estimated Time**: 2 hours

**Description**:
Set up worker node(s) with Horizon queue workers.

**Specs Per Node**:
- Same as API nodes (Ubuntu, PHP, etc.)
- No Nginx needed
- Horizon configured with 3 supervisors

**Setup**:
- Clone Laravel repo
- Configure .env (same as API nodes)
- Configure Horizon
- Set up Supervisor or systemd for auto-restart
- Start Horizon: `php artisan horizon`

**Horizon Supervisors**:
- webhooks queue (high priority)
- billing + reconciliation queues
- default + vop queues

**Deliverable**:
- 1+ worker nodes with Horizon running
- Supervisors configured and auto-restarting

**Status**: ⬜ Not Started

---

#### Task 5.4: Deploy Redis cluster
**Task ID**: THR-504
**Priority**: 🔴 Critical
**Points**: 1
**Estimated Time**: 1 hour

**Description**:
Set up Redis for queue and cache storage.

**Requirements**:
- Redis 7.x
- 2-4 GB RAM
- Private network only (no public access)
- RDB persistence enabled (optional)
- Managed service preferred (DigitalOcean, AWS ElastiCache)

**Configuration**:
```conf
maxmemory 2gb
maxmemory-policy allkeys-lru
```

**Testing**:
- Connect from API node: `redis-cli -h redis ping`
- Verify no public access

**Deliverable**:
- Redis instance accessible from all nodes
- Connection details documented

**Status**: ⬜ Not Started

---

#### Task 5.5: Deploy MinIO (S3 storage)
**Task ID**: THR-505
**Priority**: 🔴 Critical
**Points**: 2
**Estimated Time**: 2 hours

**Description**:
Set up MinIO for S3-compatible object storage.

**Requirements**:
- MinIO latest stable version
- 50-100 GB storage (start small, scale later)
- 2 GB RAM minimum
- Private network only (no public access)

**Setup**:
- Install MinIO server
- Create access key + secret
- Create bucket: `tether-uploads`
- Configure bucket policy (private or download-only)
- Configure lifecycle rules if needed

**Testing**:
- Upload test file via mc client
- Download test file
- Verify from Laravel app

**Deliverable**:
- MinIO instance running
- Bucket created
- Credentials documented

**Status**: ⬜ Not Started

---

#### Task 5.6: Deploy Postgres + pgBouncer
**Task ID**: THR-506
**Priority**: 🔴 Critical
**Points**: 2
**Estimated Time**: 2 hours

**Description**:
Set up Postgres database with pgBouncer connection pooling.

**Requirements**:
- Postgres 14+
- 4-8 GB RAM
- 50-100 GB SSD storage
- Private network only
- Managed service preferred (DigitalOcean, AWS RDS)

**pgBouncer Configuration**:
- Pool mode: transaction
- Max connections: 100-200
- Default pool size: 25

**Setup**:
- Create database: `tether`
- Create user with appropriate permissions
- Install pgBouncer (if not managed)
- Configure connection pooling
- Point Laravel to pgBouncer instead of direct Postgres

**Testing**:
- Connect from API node
- Run migrations
- Verify connection pooling works

**Deliverable**:
- Postgres database ready
- pgBouncer configured
- Connection details documented

**Status**: ⬜ Not Started

---

#### Task 5.7: Configure private networking
**Task ID**: THR-507
**Priority**: 🔴 Critical
**Points**: 1
**Estimated Time**: 1 hour

**Description**:
Set up private network so Redis, MinIO, and Postgres are not publicly accessible.

**Security Requirements**:
- Redis: Private IP only, no public access
- MinIO: Private IP only, no public access
- Postgres: Private IP only, no public access
- API nodes: Private IP for internal communication
- Worker nodes: Private IP only
- Load balancer: Public IP for HTTPS

**Testing**:
- Verify Redis not accessible from public internet
- Verify MinIO not accessible from public internet
- Verify Postgres not accessible from public internet
- Verify API nodes can reach all services

**Deliverable**:
- Network diagram
- Firewall rules documented

**Status**: ⬜ Not Started

---

### Story 6: Manual Testing & Go-Live
**Story ID**: THR-600
**Priority**: 🔴 Critical
**Points**: 8
**Owner**: Full Team

**Description**:
Execute manual testing checklist, deploy to production, and monitor for first 24 hours.

**Acceptance Criteria**:
- [ ] All smoke tests pass
- [ ] Load test passes in production
- [ ] Zero critical errors in first 24 hours
- [ ] Team on standby for issues

---

#### Task 6.1: Create manual testing checklist
**Task ID**: THR-601
**Priority**: 🔴 Critical
**Points**: 1
**Estimated Time**: 30 minutes
**File**: `docs/manual-testing-checklist.md` (new)

**Description**:
Create comprehensive manual testing checklist for pre-go-live validation.

**Sections**:
1. Pre-deployment checks
2. Deployment steps
3. Smoke tests
4. Integration tests
5. Monitoring checks

**Deliverable**: Markdown checklist that can be printed/checked off

**Status**: ⬜ Not Started

---

#### Task 6.2: Execute smoke tests on staging
**Task ID**: THR-602
**Priority**: 🔴 Critical
**Points**: 2
**Estimated Time**: 1 hour
**Dependencies**: THR-500 (infrastructure)

**Description**:
Execute smoke tests on staging environment before production deployment.

**Test Cases**:
1. Health check returns 200
2. Upload CSV file → File in MinIO
3. Process upload → Debtors created
4. Download file → Correct file served
5. Send test webhook → 200 response <100ms
6. Send duplicate webhook → Idempotency works
7. Check Horizon → Job processed successfully
8. Check database → Data correct

**Deliverable**: Test report with pass/fail results

**Status**: ⬜ Not Started

---

#### Task 6.3: Deploy to production
**Task ID**: THR-603
**Priority**: 🔴 Critical
**Points**: 2
**Estimated Time**: 2 hours
**Dependencies**: All previous tasks

**Description**:
Deploy application to production environment.

**Steps**:
1. Deploy code to all nodes
2. Run migrations (one node only)
3. Clear caches
4. Restart PHP-FPM
5. Restart Horizon
6. Verify health checks
7. Run smoke tests (same as staging)

**Rollback Plan**:
- Keep previous Git tag ready
- Document rollback steps

**Deliverable**: Production deployment complete

**Status**: ⬜ Not Started

---

#### Task 6.4: Monitor first 24 hours
**Task ID**: THR-604
**Priority**: 🔴 Critical
**Points**: 3
**Estimated Time**: 24 hours (intermittent)
**Dependencies**: THR-603

**Description**:
Monitor production for first 24 hours after go-live.

**Monitoring Schedule**:
- First 4 hours: Check every 15 minutes
- Next 8 hours: Check every 30 minutes
- Remaining 12 hours: Check every 2 hours

**What to Monitor**:
- Error logs (zero 500 errors expected)
- Horizon queue depth (should remain stable)
- Webhook response times (p95 <100ms)
- Failed jobs (should be <1%)
- API response times
- Resource usage (CPU, memory, disk)

**Escalation**:
- Critical issue (app down): Immediate rollback or hotfix
- Non-critical issue: Document and plan fix

**Deliverable**: Monitoring report

**Status**: ⬜ Not Started

---

## Epic Summary

### Total Story Points: 42

**Breakdown by Priority**:
- 🔴 Critical: 37 points (88%)
- 🟡 Important: 5 points (12%)
- 🟢 Nice to have: 0 points (0%)

**Breakdown by Area**:
- Backend Code: 18 points (43%)
- Infrastructure: 13 points (31%)
- Testing: 8 points (19%)
- Configuration: 3 points (7%)

**Team Allocation**:
- Backend Dev: 26 points (3-4 days)
- DevOps: 13 points (2-3 days)
- QA: 3 points (shared with Backend)

---

## Timeline Estimate

**Velocity Assumption**: 10-12 points per developer per day

**Parallelization**:
- Backend code changes (Stories 1, 2, 3) can happen simultaneously with infrastructure (Story 5)
- Testing (Story 4) requires both code + infrastructure complete

### Realistic Timeline:

**Day 1-2 (Dec 30-31)**:
- Backend code changes (Stories 1, 2, 3)
- Infrastructure provisioning starts (Story 5)

**Day 3-4 (Jan 1-2)**:
- Infrastructure provisioning completes (Story 5)
- Deploy to staging
- Load testing (Story 4)

**Day 5 (Jan 3)**:
- Fix any issues from load testing
- Final staging validation
- Prepare for production

**Day 6-7 (Jan 5-6)**:
- Production deployment (Story 6)
- Monitoring

**Total**: 7 days (Dec 30 → Jan 6)

---

## Dependencies Graph

```
Story 1 (File Storage) ─┐
Story 2 (Webhooks)      ├─> Story 4 (Load Testing) ─> Story 6 (Go-Live)
Story 3 (Config)        ─┤                          ─┘
Story 5 (Infrastructure)─┘
```

**Critical Path**:
1. Story 5 (Infrastructure) - Longest, must start immediately
2. Story 1, 2, 3 (Code) - Can work in parallel with infrastructure
3. Story 4 (Load Testing) - Blocks go-live
4. Story 6 (Go-Live) - Final step

---

## Risks & Mitigations

### Risk 1: Infrastructure delays
**Impact**: High
**Probability**: Medium
**Mitigation**:
- Start infrastructure work immediately
- Use managed services where possible (faster setup)
- Have backup provider ready

### Risk 2: Load test failures
**Impact**: High
**Probability**: Low
**Mitigation**:
- Tune configuration based on staging results
- Have performance expert on call
- Allocate buffer day (Jan 4) for fixes

### Risk 3: S3 parsing issues
**Impact**: Medium
**Probability**: Low
**Mitigation**:
- Test with real MinIO in staging
- Have temp file approach ready (already planned)
- Verify parser compatibility early (Task 1.3)

### Risk 4: Production issues on go-live
**Impact**: High
**Probability**: Low
**Mitigation**:
- Thorough testing in staging
- Rollback plan ready
- Team on standby for 24 hours
- Deploy Monday morning (low traffic)

---

## Success Metrics

### Technical Metrics:
- ✅ Webhook p95 response time <100ms
- ✅ Zero 500 errors in first 24 hours
- ✅ Queue depth remains stable (<100 pending jobs)
- ✅ All uploaded files accessible from all nodes
- ✅ Zero data loss (idempotency working)

### Business Metrics:
- ✅ 99.9% uptime in first week
- ✅ Support horizontal scaling (can add 3rd API node)
- ✅ No customer-facing issues

---

## Export Instructions

### For Jira:
1. Create parent Epic: "Go-Live v1 - Production-Ready Stateless Application"
2. For each Story (THR-100, THR-200, etc.):
   - Create as Epic in Jira
   - Set Story Points and Priority
3. For each Task (THR-101, THR-102, etc.):
   - Create as Story under parent Epic
   - Set Story Points and Priority
   - Add "File" and "Estimated Time" in description
   - Link dependencies
4. Use labels: `priority:critical`, `area:backend`, `area:devops`, `area:testing`

### For Linear:
1. Create parent Issue: "Go-Live v1 - Production-Ready Stateless Application"
2. For each Story: Create child Issue
3. For each Task: Create sub-Issue or add as checklist
4. Set estimates (use hours instead of points)
5. Set priorities using Linear's priority system
6. Link dependencies using "Blocks" relationship

### For GitHub Projects:
1. Create Issues for each Story and Task
2. Add labels:
   - `priority: critical`, `priority: important`
   - `area: backend`, `area: devops`, `area: testing`
   - `story-points: 1`, `story-points: 2`, etc.
3. Create Milestone: "Go-Live v1 (Jan 5-6, 2026)"
4. Add to Project Board with columns:
   - Backlog
   - In Progress
   - In Review
   - Done
5. Use task lists in Issue descriptions for sub-tasks

### For ClickUp:
1. Create parent Task: "Go-Live v1 Epic"
2. For each Story: Create task under parent
3. For each Task: Create subtask
4. Use Custom Fields for Story Points
5. Use Tags for priorities and areas
6. Set dependencies using ClickUp's dependency feature

---

## Quick Reference

### All Tasks by Priority

**🔴 Critical (Must complete for go-live)**:
- THR-101: Update FileUploadService (15 min)
- THR-102: Update ProcessUploadJob (30 min)
- THR-105: S3 upload test (30 min)
- THR-201: Create ProcessEmpWebhookJob (45 min)
- THR-202: Refactor EmpWebhookController (30 min)
- THR-203: Webhook idempotency test (30 min)
- THR-204: Configure Horizon webhooks queue (15 min)
- THR-301: Update .env.example (15 min)
- THR-303: Create production .env (30 min)
- THR-401: Create k6 load test (1 hour)
- THR-402: Run load test (2 hours)
- THR-501: Provision load balancer (2 hours)
- THR-502: Provision API nodes (3 hours)
- THR-503: Provision worker nodes (2 hours)
- THR-504: Deploy Redis (1 hour)
- THR-505: Deploy MinIO (2 hours)
- THR-506: Deploy Postgres (2 hours)
- THR-507: Configure private network (1 hour)
- THR-601: Create testing checklist (30 min)
- THR-602: Execute smoke tests (1 hour)
- THR-603: Deploy to production (2 hours)
- THR-604: Monitor 24 hours (24 hours)

**🟡 Important (Should complete)**:
- THR-103: Verify parser compatibility (15 min)
- THR-104: Update download endpoints (15 min)
- THR-302: Verify filesystems config (5 min)
- THR-403: Document performance tuning (30 min)

---

## Post-Epic: Future Improvements (v1.1)

**Not required for go-live, defer to next sprint**:

1. **Webhook audit table** (THR-700): Database table for permanent webhook log - 3 points
2. **Enhanced health check** (THR-701): Check Redis, Postgres, MinIO connectivity - 2 points
3. **File migration script** (THR-702): Migrate existing local files to S3 - 3 points
4. **Metrics dashboard** (THR-703): Grafana + Prometheus setup - 5 points

**Estimated total**: 13 points (1 week)

---

**Document Owner**: Development Team
**Last Updated**: December 30, 2024
**Next Review**: January 5, 2026 (post-go-live)
