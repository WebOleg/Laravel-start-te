# Jira Tasks for Go-Live v1 Epic

**Epic**: Go-Live v1 - Production-Ready Stateless Application

---

## Task 1: File Storage Migration to S3/MinIO
**Task ID**: THR-100
**Priority**: Critical
**Story Points**: 5
**Owner**: Backend Dev

**Description**:
Migrate all file storage from local disk to S3-compatible MinIO to enable stateless architecture and horizontal scaling.

**Acceptance Criteria**:
- [ ] Files uploaded to S3 instead of local disk
- [ ] Jobs can read files from S3
- [ ] File downloads work from S3
- [ ] Tests pass with S3 fake storage

**Sub-tasks**:

### Sub-task 1.1: Update FileUploadService to use S3
- **ID**: THR-101
- **Points**: 1
- **Time**: 15 minutes
- **File**: `app/Services/FileUploadService.php:157`
- **Change**: `return $file->storeAs('uploads', $filename, 's3');`

### Sub-task 1.2: Update ProcessUploadJob to read from S3
- **ID**: THR-102
- **Points**: 2
- **Time**: 30 minutes
- **File**: `app/Jobs/ProcessUploadJob.php:62`
- **Details**: Download S3 file to temp location, parse, clean up

### Sub-task 1.3: Verify SpreadsheetParserService compatibility
- **ID**: THR-103
- **Points**: 1
- **Time**: 15 minutes
- **File**: `app/Services/SpreadsheetParserService.php`
- **Details**: Test CSV/Excel parsers with temp files

### Sub-task 1.4: Update file download endpoints
- **ID**: THR-104
- **Points**: 1
- **Time**: 15 minutes
- **Details**: Find and update controllers to use S3

### Sub-task 1.5: Write S3 upload integration test
- **ID**: THR-105
- **Points**: 2
- **Time**: 30 minutes
- **File**: `tests/Feature/S3FileUploadTest.php` (new)

---

## Task 2: Async Webhook Processing with Idempotency
**Task ID**: THR-200
**Priority**: Critical
**Story Points**: 8
**Owner**: Backend Dev

**Description**:
Optimize EMP webhook endpoint to respond <100ms by offloading business logic to background jobs, and implement Redis-based idempotency.

**Acceptance Criteria**:
- [ ] Webhook responds in <100ms (signature check + queue dispatch)
- [ ] Duplicate webhooks are ignored (idempotency)
- [ ] All webhook business logic executes in background job
- [ ] Load test passes: 100 RPS with <1% errors

**Sub-tasks**:

### Sub-task 2.1: Create ProcessEmpWebhookJob
- **ID**: THR-201
- **Points**: 3
- **Time**: 45 minutes
- **File**: `app/Jobs/ProcessEmpWebhookJob.php` (new)
- **Details**: Move all webhook business logic to async job

### Sub-task 2.2: Refactor EmpWebhookController for speed
- **ID**: THR-202
- **Points**: 2
- **Time**: 30 minutes
- **File**: `app/Http/Controllers/Webhook/EmpWebhookController.php`
- **Details**: Keep only signature check, idempotency, job dispatch

### Sub-task 2.3: Write webhook idempotency test
- **ID**: THR-203
- **Points**: 2
- **Time**: 30 minutes
- **File**: `tests/Feature/WebhookIdempotencyTest.php` (new)

### Sub-task 2.4: Configure Horizon for webhooks queue
- **ID**: THR-204
- **Points**: 1
- **Time**: 15 minutes
- **File**: `config/horizon.php`
- **Details**: Add dedicated supervisor for webhook queue

---

## Task 3: Production Configuration
**Task ID**: THR-300
**Priority**: Critical
**Story Points**: 3
**Owner**: Backend Dev / DevOps

**Description**:
Update application configuration for production deployment with proper logging, queue, cache, and storage settings.

**Acceptance Criteria**:
- [ ] .env.example updated with all required variables
- [ ] Production .env configured correctly
- [ ] Logs output to stderr
- [ ] All services use Redis for queue/cache
- [ ] Default filesystem disk is S3

**Sub-tasks**:

### Sub-task 3.1: Update .env.example with production settings
- **ID**: THR-301
- **Points**: 1
- **Time**: 15 minutes
- **File**: `.env.example`

### Sub-task 3.2: Verify filesystems config for S3
- **ID**: THR-302
- **Points**: 0.5
- **Time**: 5 minutes
- **File**: `config/filesystems.php`

### Sub-task 3.3: Create production .env on servers
- **ID**: THR-303
- **Points**: 1.5
- **Time**: 30 minutes
- **Details**: Set up production environment variables on all nodes

---

## Task 4: Load Testing & Performance Validation
**Task ID**: THR-400
**Priority**: Critical
**Story Points**: 5
**Owner**: QA / Backend Dev

**Description**:
Validate application performance under load, especially webhook endpoint throughput and response times.

**Acceptance Criteria**:
- [ ] Webhook p95 response time <100ms at 50 RPS
- [ ] Webhook p99 response time <200ms at 100 RPS
- [ ] Zero errors during 10-minute sustained load test
- [ ] Queue workers keep up with incoming jobs

**Sub-tasks**:

### Sub-task 4.1: Create k6 load test script
- **ID**: THR-401
- **Points**: 2
- **Time**: 1 hour
- **File**: `tests/load/webhook-load-test.js` (new)

### Sub-task 4.2: Run load test on staging
- **ID**: THR-402
- **Points**: 2
- **Time**: 2 hours
- **Dependencies**: THR-401, THR-500
- **Deliverable**: Load test report

### Sub-task 4.3: Document performance tuning
- **ID**: THR-403
- **Points**: 1
- **Time**: 30 minutes
- **File**: `docs/performance-tuning.md` (new)

---

## Task 5: Infrastructure Provisioning & Deployment
**Task ID**: THR-500
**Priority**: Critical
**Story Points**: 13
**Owner**: DevOps

**Description**:
Set up production infrastructure with load balancer, API nodes, worker nodes, and supporting services.

**Acceptance Criteria**:
- [ ] Load balancer with SSL configured
- [ ] 2 API nodes deployed and load balanced
- [ ] 1+ worker nodes with Horizon
- [ ] Redis accessible (private network)
- [ ] MinIO accessible (private network)
- [ ] Postgres + pgBouncer accessible (private network)

**Sub-tasks**:

### Sub-task 5.1: Provision load balancer
- **ID**: THR-501
- **Points**: 2
- **Time**: 2 hours
- **Details**: Nginx with SSL, health checks

### Sub-task 5.2: Provision API nodes (2x)
- **ID**: THR-502
- **Points**: 3
- **Time**: 3 hours
- **Details**: Ubuntu 22.04, Nginx, PHP 8.2, Laravel

### Sub-task 5.3: Provision worker nodes (1+)
- **ID**: THR-503
- **Points**: 2
- **Time**: 2 hours
- **Details**: Same as API nodes, but with Horizon instead of Nginx

### Sub-task 5.4: Deploy Redis cluster
- **ID**: THR-504
- **Points**: 1
- **Time**: 1 hour
- **Details**: Redis 7.x, 2-4 GB RAM, private network

### Sub-task 5.5: Deploy MinIO (S3 storage)
- **ID**: THR-505
- **Points**: 2
- **Time**: 2 hours
- **Details**: MinIO latest, 50-100 GB storage, create bucket

### Sub-task 5.6: Deploy Postgres + pgBouncer
- **ID**: THR-506
- **Points**: 2
- **Time**: 2 hours
- **Details**: Postgres 14+, connection pooling

### Sub-task 5.7: Configure private networking
- **ID**: THR-507
- **Points**: 1
- **Time**: 1 hour
- **Details**: Ensure Redis/MinIO/Postgres not publicly accessible

---

## Task 6: Manual Testing & Go-Live
**Task ID**: THR-600
**Priority**: Critical
**Story Points**: 8
**Owner**: Full Team

**Description**:
Execute manual testing checklist, deploy to production, and monitor for first 24 hours.

**Acceptance Criteria**:
- [ ] All smoke tests pass
- [ ] Load test passes in production
- [ ] Zero critical errors in first 24 hours
- [ ] Team on standby for issues

**Sub-tasks**:

### Sub-task 6.1: Create manual testing checklist
- **ID**: THR-601
- **Points**: 1
- **Time**: 30 minutes
- **File**: `docs/manual-testing-checklist.md` (new)

### Sub-task 6.2: Execute smoke tests on staging
- **ID**: THR-602
- **Points**: 2
- **Time**: 1 hour
- **Dependencies**: THR-500
- **Deliverable**: Test report

### Sub-task 6.3: Deploy to production
- **ID**: THR-603
- **Points**: 2
- **Time**: 2 hours
- **Dependencies**: All previous tasks

### Sub-task 6.4: Monitor first 24 hours
- **ID**: THR-604
- **Points**: 3
- **Time**: 24 hours (intermittent)
- **Deliverable**: Monitoring report

---

## Summary

**Total Tasks**: 6
**Total Sub-tasks**: 24
**Total Story Points**: 42
**Estimated Duration**: 7 days (Dec 30 → Jan 6)

---

## Dependencies

**Task 4** depends on:
- Task 1, 2, 3 (code changes)
- Task 5 (infrastructure)

**Task 6** depends on:
- Task 4 (load testing passed)

**Critical Path**: Task 5 (Infrastructure) is the longest and must start immediately

---

## Labels to Use in Jira

- `priority:critical`
- `priority:important`
- `area:backend`
- `area:devops`
- `area:testing`
- `team:backend-dev`
- `team:devops`
- `team:qa`

---

## How to Import

1. **Create Epic** in Jira: "Go-Live v1 - Production-Ready Stateless Application"

2. **Create 6 Tasks** under the Epic (copy description above for each)

3. **Create Sub-tasks** for each Task:
   - Click on Task → "Create Sub-task"
   - Copy ID, Points, Time, Description
   - Set Priority to Critical/Important
   - Assign to owner

4. **Set Dependencies**:
   - Task 4 blocks on Task 1, 2, 3, 5
   - Task 6 blocks on Task 4

5. **Add Labels** to categorize

6. **Set Sprint/Timeline**:
   - Sprint: "Go-Live v1 Sprint (Dec 30 - Jan 6)"
   - Target date: Jan 5-6, 2026

---

## Quick Copy-Paste for Each Task

### THR-100: File Storage Migration
```
Summary: File Storage Migration to S3/MinIO
Description: Migrate all file storage from local disk to S3-compatible MinIO
Priority: Critical
Story Points: 5
Owner: Backend Dev
Labels: area:backend, priority:critical
```

### THR-200: Async Webhook Processing
```
Summary: Async Webhook Processing with Idempotency
Description: Optimize EMP webhook endpoint to respond <100ms
Priority: Critical
Story Points: 8
Owner: Backend Dev
Labels: area:backend, priority:critical
```

### THR-300: Production Configuration
```
Summary: Production Configuration
Description: Update application configuration for production deployment
Priority: Critical
Story Points: 3
Owner: Backend Dev / DevOps
Labels: area:backend, area:devops, priority:critical
```

### THR-400: Load Testing
```
Summary: Load Testing & Performance Validation
Description: Validate application performance under load
Priority: Critical
Story Points: 5
Owner: QA / Backend Dev
Labels: area:testing, priority:critical
```

### THR-500: Infrastructure Provisioning
```
Summary: Infrastructure Provisioning & Deployment
Description: Set up production infrastructure
Priority: Critical
Story Points: 13
Owner: DevOps
Labels: area:devops, priority:critical
```

### THR-600: Go-Live
```
Summary: Manual Testing & Go-Live
Description: Execute testing, deploy, and monitor
Priority: Critical
Story Points: 8
Owner: Full Team
Labels: area:testing, area:devops, priority:critical
```
