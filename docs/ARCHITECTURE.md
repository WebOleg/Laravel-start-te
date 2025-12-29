# Tether Architecture

## Table of Contents

1. [System Overview](#system-overview)
2. [Directory Structure](#directory-structure)
3. [Database Schema](#database-schema)
4. [Processing Flow](#processing-flow)
5. [Services](#services)
6. [Jobs & Queues](#jobs--queues)
7. [API Endpoints](#api-endpoints)
8. [Configuration](#configuration)

---

## System Overview
```
┌─────────────────────────────────────────────────────────────────────────┐
│                         FRONTEND (Next.js)                               │
│                      localhost:3000 / tether.app                         │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                           NGINX + Laravel                                │
│                           localhost:8000                                 │
│                                                                          │
│   ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐   │
│   │   Routes    │→ │ Controllers │→ │  Services   │→ │   Models    │   │
│   │  (api.php)  │  │   (Admin/)  │  │  (Business) │  │ (Eloquent)  │   │
│   └─────────────┘  └─────────────┘  └─────────────┘  └─────────────┘   │
│                                                                          │
│   ┌─────────────────────────────────────────────────────────────────┐   │
│   │                    Laravel Sanctum (Auth)                        │   │
│   └─────────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────────┘
         │                    │                         │
         ▼                    ▼                         ▼
┌─────────────┐      ┌─────────────┐           ┌─────────────┐
│ PostgreSQL  │      │    Redis    │           │ External    │
│   :5432     │      │   :6379     │           │ APIs        │
│             │      │             │           │             │
│ - uploads   │      │ - queues    │           │ - EMP       │
│ - debtors   │      │ - cache     │           │ - Sumsub    │
│ - billing   │      │ - locks     │           │             │
└─────────────┘      └─────────────┘           └─────────────┘
```

**Tech Stack:**
- **Backend:** Laravel 11, PHP 8.3
- **Database:** PostgreSQL 16
- **Queue:** Redis + Laravel Horizon
- **Frontend:** Next.js 16, TypeScript, Tailwind CSS
- **Payments:** emerchantpay Genesis API (SEPA DD)
- **VOP:** Sumsub BAV API

---

## Directory Structure
```
tether-laravel/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Admin/
│   │   │   │   ├── BillingAttemptController.php  # List/show/retry billing
│   │   │   │   ├── BillingController.php         # Sync/stats endpoints
│   │   │   │   ├── DashboardController.php       # Dashboard stats
│   │   │   │   ├── DebtorController.php          # CRUD debtors
│   │   │   │   ├── ReconciliationController.php  # Reconcile transactions
│   │   │   │   ├── StatsController.php           # Chargeback stats
│   │   │   │   ├── UploadController.php          # File upload/validation
│   │   │   │   ├── VopController.php             # VOP verification
│   │   │   │   └── VopLogController.php          # VOP logs
│   │   │   ├── Api/
│   │   │   │   └── AuthController.php            # Login/logout
│   │   │   └── Webhook/
│   │   │       └── EmpWebhookController.php      # EMP notifications
│   │   └── Resources/                            # JSON transformers
│   │
│   ├── Jobs/
│   │   ├── ProcessUploadJob.php                  # Parse CSV/XLSX
│   │   ├── ProcessUploadChunkJob.php             # Chunk processing
│   │   ├── ProcessVopJob.php                     # VOP batch dispatcher
│   │   ├── ProcessVopChunkJob.php                # VOP worker
│   │   ├── ProcessBillingJob.php                 # Billing dispatcher
│   │   ├── ProcessBillingChunkJob.php            # Billing worker
│   │   ├── ProcessReconciliationJob.php          # Reconciliation dispatcher
│   │   └── ProcessReconciliationChunkJob.php     # Reconciliation worker
│   │
│   ├── Models/
│   │   ├── BillingAttempt.php
│   │   ├── Blacklist.php
│   │   ├── BankReference.php
│   │   ├── Debtor.php
│   │   ├── Upload.php
│   │   ├── User.php
│   │   └── VopLog.php
│   │
│   └── Services/
│       ├── BlacklistService.php                  # Blacklist management
│       ├── DebtorValidationService.php           # Validation rules
│       ├── DeduplicationService.php              # Skip logic (30-day cooldown)
│       ├── FilePreValidationService.php          # Pre-upload checks
│       ├── FileUploadService.php                 # Upload orchestration
│       ├── IbanApiService.php                    # Sumsub VOP integration
│       ├── IbanValidator.php                     # IBAN checksum
│       ├── ReconciliationService.php             # EMP status sync
│       ├── SpreadsheetParserService.php          # CSV/XLSX parsing
│       ├── VopScoringService.php                 # Score calculation
│       ├── VopVerificationService.php            # VOP orchestration
│       └── Emp/
│           ├── EmpClient.php                     # HTTP client for EMP
│           └── EmpBillingService.php             # Billing business logic
│
├── database/
│   ├── factories/                                # Test factories
│   ├── migrations/                               # Schema
│   └── seeders/                                  # Dev data
│
├── docs/
│   ├── API.md                                    # API documentation
│   ├── ARCHITECTURE.md                           # This file
│   ├── CODE_STYLE.md                             # Coding standards
│   └── CONTRIBUTING.md                           # Dev guide
│
├── routes/
│   └── api.php                                   # All API routes
│
└── tests/
    └── Feature/Admin/                            # API tests
```

---

## Database Schema

### Entity Relationships
```
┌─────────────┐       ┌─────────────┐       ┌──────────────────┐
│   uploads   │──1:N──│   debtors   │──1:N──│ billing_attempts │
└─────────────┘       └─────────────┘       └──────────────────┘
                             │
                             │──1:N──┌─────────────┐
                             │       │  vop_logs   │
                             │       └─────────────┘
                             
┌─────────────┐       ┌─────────────────┐
│ blacklists  │       │ bank_references │ (standalone cache)
└─────────────┘       └─────────────────┘
```

### Core Tables

#### `uploads`
| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| filename | string | System filename |
| original_filename | string | User filename |
| status | enum | pending, processing, completed, failed |
| total_records | int | Rows in file |
| processed_records | int | Successfully processed |
| headers | jsonb | CSV column names |
| meta | jsonb | Skipped rows info |

#### `debtors`
| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| upload_id | bigint | FK to uploads |
| iban | string | Bank account (encrypted at rest) |
| iban_hash | string | SHA256 for deduplication |
| first_name | string | First name (max 35 chars) |
| last_name | string | Last name (max 35 chars) |
| amount | decimal | Debt amount |
| status | enum | pending, processing, recovered, failed |
| validation_status | enum | pending, valid, invalid |
| validation_errors | jsonb | Error messages |
| raw_data | jsonb | Original CSV row |

#### `billing_attempts`
| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| debtor_id | bigint | FK to debtors |
| upload_id | bigint | FK to uploads |
| transaction_id | string | Internal ID (unique) |
| unique_id | string | EMP gateway ID |
| amount | decimal | Amount charged |
| status | enum | pending, pending_async, approved, declined, error, voided, chargebacked |
| attempt_number | int | Retry counter |
| error_code | string | SEPA error code |
| error_message | string | Human-readable error |
| last_reconciled_at | timestamp | Last reconciliation time |
| reconciliation_attempts | int | Reconciliation counter (max 10) |

#### `vop_logs`
| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| debtor_id | bigint | FK to debtors |
| vop_score | tinyint | Score 0-100 |
| result | enum | verified, likely_verified, inconclusive, mismatch, rejected |
| bank_name | string | Bank name |
| bic | string | BIC/SWIFT code |

#### `blacklists`
| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| iban_hash | string | SHA256 (indexed) |
| first_name | string | Blocked name |
| last_name | string | Blocked name |
| email | string | Blocked email |
| reason | string | Why blocked |
| source | string | Manual, System, Chargeback |

---

## Processing Flow

### Complete Workflow
```
┌─────────────────────────────────────────────────────────────────┐
│              STAGE 0: PRE-VALIDATION (sync, ~50ms)               │
│                                                                  │
│  FilePreValidationService:                                       │
│  ✓ File type (CSV/XLSX/XLS/TXT)                                 │
│  ✓ Headers exist                                                │
│  ✓ Required columns (IBAN, amount, name)                        │
│  ✓ Data rows exist                                              │
│                                                                  │
│  ❌ FAIL → 422 response (no processing starts)                  │
│  ✅ PASS → Continue                                             │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│              STAGE 1: UPLOAD & DEDUPLICATION                     │
│                                                                  │
│  DeduplicationService checks each row:                           │
│  • Blacklisted IBAN/name/email → SKIP                           │
│  • Previous chargeback → SKIP                                   │
│  • Already recovered → SKIP                                      │
│  • Attempted in last 30 days → SKIP (cooldown)                  │
│                                                                  │
│  Creates Debtor records (validation_status='pending')           │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│              STAGE 2: VALIDATION (auto on page load)             │
│                                                                  │
│  DebtorValidationService:                                        │
│  ✓ IBAN checksum valid                                          │
│  ✓ First name max 35 chars, no numbers/symbols                  │
│  ✓ Last name max 35 chars, no numbers/symbols                   │
│  ✓ Amount > 0 and ≤ 50000                                       │
│  ✓ Not blacklisted                                              │
│  ✓ No encoding issues                                           │
│                                                                  │
│  Updates validation_status='valid' or 'invalid'                 │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│              STAGE 3: VOP VERIFICATION (optional)                │
│                                                                  │
│  User clicks "Verify VOP" button                                │
│  ProcessVopJob → ProcessVopChunkJob                             │
│                                                                  │
│  VopScoringService calculates score via Sumsub:                 │
│  +20 IBAN valid | +25 Bank found | +25 SEPA SDD | +15 Country   │
│                                                                  │
│  Creates VopLog with score and result                           │
│  Reduces chargebacks by 30-50%                                  │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│              STAGE 4: SYNC TO GATEWAY                            │
│                                                                  │
│  User clicks "Sync to Gateway" button                           │
│  POST /uploads/{id}/sync → 202 Accepted                         │
│  ProcessBillingJob → ProcessBillingChunkJob                     │
│                                                                  │
│  EmpBillingService.billDebtor() for each eligible:              │
│  • validation_status='valid'                                    │
│  • status='pending'                                             │
│  • No pending/approved billing attempts                         │
│  • Not in 30-day cooldown                                       │
│                                                                  │
│  Creates BillingAttempt (status='pending')                      │
│  Sends SDD transaction to emerchantpay                          │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│              STAGE 5: STATUS UPDATES                             │
│                                                                  │
│  PRIMARY: Webhook from EMP (async, 2-48h)                       │
│  POST /webhooks/emp                                              │
│  → Updates status: approved/declined/chargebacked               │
│  → Auto-blacklist on certain error codes                        │
│                                                                  │
│  BACKUP: Reconciliation (if webhook missed)                     │
│  POST /billing-attempts/{id}/reconcile                          │
│  → Queries EMP Reconcile API directly                           │
│  → Updates status from actual gateway response                  │
│  → Eligible: pending > 2h, < 10 reconciliation attempts         │
└─────────────────────────────────────────────────────────────────┘
```

### Billing Architecture (3-5M tx/month scale)
```
┌─────────────────────────────────────────────────────────────────┐
│                    BillingController::sync()                     │
│                                                                  │
│  1. Check cache lock (prevent duplicates)                       │
│  2. Count eligible debtors                                      │
│  3. Set 5min cache lock                                         │
│  4. Dispatch ProcessBillingJob                                  │
│  5. Return 202 Accepted                                         │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    ProcessBillingJob                             │
│                                                                  │
│  1. Query eligible debtors                                      │
│  2. Split into chunks of 50                                     │
│  3. Dispatch Bus::batch(ProcessBillingChunkJob[])               │
└─────────────────────────────────────────────────────────────────┘
                              │
                    ┌─────────┴─────────┐
                    ▼                   ▼
┌───────────────────────┐   ┌───────────────────────┐
│ ProcessBillingChunkJob│   │ ProcessBillingChunkJob│  ... (parallel)
│                       │   │                       │
│ Rate limit: 50 req/s  │   │ Circuit breaker:      │
│ Retry: 3x backoff     │   │ 10 fails → 5min pause │
└───────────────────────┘   └───────────────────────┘
                    │                   │
                    └─────────┬─────────┘
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    EmpBillingService                             │
│                                                                  │
│  billDebtor():                                                  │
│  1. Create BillingAttempt (status='pending')                    │
│  2. Build XML request with notification_url                     │
│  3. Call EmpClient.sddSale()                                    │
│  4. Update with EMP response (unique_id, status)                │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    EmpClient                                     │
│                                                                  │
│  POST https://staging.gate.emerchantpay.net/process/{terminal}  │
│  Auth: Basic (username:password)                                │
│  Format: XML request/response                                   │
│  Transaction: sdd_sale (SEPA Direct Debit)                      │
└─────────────────────────────────────────────────────────────────┘
```

### Reconciliation Flow
```
┌─────────────────────────────────────────────────────────────────┐
│              ReconciliationController                            │
│                                                                  │
│  Single: POST /billing-attempts/{id}/reconcile                  │
│  Bulk:   POST /uploads/{id}/reconcile                           │
│  All:    POST /reconciliation/bulk                              │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│              ReconciliationService                               │
│                                                                  │
│  reconcile(BillingAttempt):                                     │
│  1. Check eligibility (pending, >2h, <10 attempts)              │
│  2. Call EmpClient.reconcile(unique_id)                         │
│  3. Parse EMP response                                          │
│  4. Update billing_attempt status                               │
│  5. Increment reconciliation_attempts                           │
│  6. Set last_reconciled_at                                      │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    EmpClient.reconcile()                         │
│                                                                  │
│  POST https://staging.gate.emerchantpay.net/reconcile/{terminal}│
│                                                                  │
│  Request:                                                       │
│  <reconcile><unique_id>xxx</unique_id></reconcile>             │
│                                                                  │
│  Response contains actual transaction status from bank          │
└─────────────────────────────────────────────────────────────────┘
```

---

## Services

### Core Services

| Service | Purpose |
|---------|---------|
| **FilePreValidationService** | Quick structure check before processing |
| **SpreadsheetParserService** | Parse CSV/XLSX into arrays |
| **FileUploadService** | Orchestrate upload + deduplication |
| **DeduplicationService** | Skip blacklisted/chargebacked/30-day cooldown |
| **DebtorValidationService** | Validate IBAN, name (35 char limit), amount |
| **BlacklistService** | Manage blocked IBANs/names/emails |
| **ReconciliationService** | Query EMP for actual transaction status |

### VOP Services

| Service | Purpose |
|---------|---------|
| **IbanValidator** | Local IBAN checksum validation |
| **IbanApiService** | Sumsub BAV API integration |
| **VopScoringService** | Calculate VOP score (0-100) |
| **VopVerificationService** | Orchestrate VOP process |

### Billing Services

| Service | Purpose |
|---------|---------|
| **EmpClient** | HTTP client for emerchantpay (billing + reconcile) |
| **EmpBillingService** | Billing business logic |

### VOP Score Calculation
```
Score Component          Points    Condition
─────────────────────────────────────────────
IBAN Valid               +20       Checksum passes
Bank Identified          +25       Bank found in registry
SEPA SDD Support         +25       Bank supports Direct Debit
Country Supported        +15       Country in SEPA zone
Name Match (Sumsub)      +15       BAV name verification
─────────────────────────────────────────────
Total                    100

Result Thresholds:
80-100 → verified        (safe to bill)
60-79  → likely_verified (probably safe)
40-59  → inconclusive    (review needed)
20-39  → mismatch        (issues detected)
0-19   → rejected        (do not bill)
```

---

## Jobs & Queues

### Queue Configuration
```bash
# Queues (priority order)
billing        # Billing jobs (highest priority)
reconciliation # Reconciliation jobs
vop            # VOP verification
default        # Upload processing

# Workers
php artisan queue:work --queue=billing,reconciliation,vop,default
```

### Job Configuration

| Job | Queue | Timeout | Tries | Chunk Size |
|-----|-------|---------|-------|------------|
| ProcessUploadJob | default | 300s | 3 | N/A |
| ProcessUploadChunkJob | default | 120s | 3 | 100 rows |
| ProcessVopJob | vop | 300s | 3 | N/A |
| ProcessVopChunkJob | vop | 180s | 3 | 50 debtors |
| ProcessBillingJob | billing | 600s | 3 | N/A |
| ProcessBillingChunkJob | billing | 120s | 3 | 50 debtors |
| ProcessReconciliationJob | reconciliation | 600s | 3 | N/A |
| ProcessReconciliationChunkJob | reconciliation | 120s | 3 | 50 attempts |

### Rate Limiting & Protection

| Feature | Config | Purpose |
|---------|--------|---------|
| Rate Limit | 50 req/sec | Prevent EMP API overload |
| Circuit Breaker | 10 fails → 5min pause | Stop cascade failures |
| Cache Lock | 5 minutes | Prevent duplicate dispatches |
| Retry Backoff | 10s, 30s, 60s | Handle transient errors |
| Reconciliation Min Age | 2 hours | Don't reconcile too soon |
| Reconciliation Max Attempts | 10 | Stop infinite retries |

---

## API Endpoints

### Authentication
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | /login | Get auth token |
| POST | /logout | Revoke token |
| GET | /user | Current user |

### Uploads
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | /admin/uploads | List uploads |
| POST | /admin/uploads | Create upload |
| GET | /admin/uploads/{id} | Get upload |
| DELETE | /admin/uploads/{id} | Delete upload |
| GET | /admin/uploads/{id}/status | Processing status |
| GET | /admin/uploads/{id}/debtors | List debtors |
| POST | /admin/uploads/{id}/validate | Run validation |
| GET | /admin/uploads/{id}/validation-stats | Validation stats |
| POST | /admin/uploads/{id}/filter-chargebacks | Remove chargebacks |

### VOP
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | /admin/uploads/{id}/vop-stats | VOP statistics |
| POST | /admin/uploads/{id}/verify-vop | Start verification |
| GET | /admin/uploads/{id}/vop-logs | VOP logs for upload |
| POST | /admin/vop/verify-single | Test single IBAN |
| GET | /admin/vop-logs | List all VOP logs |

### Billing
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | /admin/uploads/{id}/sync | Start billing (202) |
| GET | /admin/uploads/{id}/billing-stats | Billing statistics |
| GET | /admin/billing-attempts | List attempts |
| GET | /admin/billing-attempts/{id} | Get attempt |
| POST | /admin/billing-attempts/{id}/retry | Retry failed |

### Reconciliation
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | /admin/reconciliation/stats | Global reconciliation stats |
| GET | /admin/uploads/{id}/reconciliation-stats | Upload reconciliation stats |
| POST | /admin/billing-attempts/{id}/reconcile | Reconcile single |
| POST | /admin/uploads/{id}/reconcile | Reconcile upload (202) |
| POST | /admin/reconciliation/bulk | Bulk reconcile all (202) |

### Debtors
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | /admin/debtors | List debtors |
| GET | /admin/debtors/{id} | Get debtor |
| PUT | /admin/debtors/{id} | Update debtor |
| DELETE | /admin/debtors/{id} | Delete debtor |
| POST | /admin/debtors/{id}/validate | Validate debtor |

### Statistics
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | /admin/dashboard | Dashboard stats |
| GET | /admin/stats/chargeback-rates | By country |
| GET | /admin/stats/chargeback-codes | By error code |
| GET | /admin/stats/chargeback-banks | By bank |

### Webhooks
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | /webhooks/emp | emerchantpay notifications |

---

## Configuration

### Environment Variables
```env
# Database
DB_CONNECTION=pgsql
DB_HOST=db
DB_DATABASE=tether
DB_USERNAME=tether
DB_PASSWORD=secret

# Queue
QUEUE_CONNECTION=redis
REDIS_HOST=redis

# emerchantpay Genesis API
EMP_API_LOGIN=your_api_login
EMP_API_PASSWORD=your_api_password
EMP_TERMINAL_TOKEN=your_terminal_token
EMP_ENVIRONMENT=staging  # or production

# Sumsub VOP API
SUMSUB_APP_TOKEN=your_app_token
SUMSUB_SECRET_KEY=your_secret_key

# Reconciliation
RECONCILIATION_MIN_AGE_HOURS=2
RECONCILIATION_MAX_ATTEMPTS=10
```

### Constants Reference
```php
// Debtor Status
Debtor::STATUS_PENDING     = 'pending'
Debtor::STATUS_PROCESSING  = 'processing'
Debtor::STATUS_RECOVERED   = 'recovered'
Debtor::STATUS_FAILED      = 'failed'

// Validation Status
Debtor::VALIDATION_PENDING = 'pending'
Debtor::VALIDATION_VALID   = 'valid'
Debtor::VALIDATION_INVALID = 'invalid'

// Billing Status
BillingAttempt::STATUS_PENDING       = 'pending'
BillingAttempt::STATUS_PENDING_ASYNC = 'pending_async'
BillingAttempt::STATUS_APPROVED      = 'approved'
BillingAttempt::STATUS_DECLINED      = 'declined'
BillingAttempt::STATUS_ERROR         = 'error'
BillingAttempt::STATUS_VOIDED        = 'voided'
BillingAttempt::STATUS_CHARGEBACKED  = 'chargebacked'

// Reconciliation
BillingAttempt::RECONCILIATION_MIN_AGE_HOURS = 2
BillingAttempt::RECONCILIATION_MAX_ATTEMPTS  = 10

// VOP Result
VopLog::RESULT_VERIFIED        = 'verified'
VopLog::RESULT_LIKELY_VERIFIED = 'likely_verified'
VopLog::RESULT_INCONCLUSIVE    = 'inconclusive'
VopLog::RESULT_MISMATCH        = 'mismatch'
VopLog::RESULT_REJECTED        = 'rejected'

// Skip Reasons
DeduplicationService::SKIP_BLACKLISTED        = 'blacklisted'
DeduplicationService::SKIP_CHARGEBACKED       = 'chargebacked'
DeduplicationService::SKIP_RECOVERED          = 'already_recovered'
DeduplicationService::SKIP_RECENTLY_ATTEMPTED = 'recently_attempted'  // 30-day cooldown
```

### SEPA Error Codes (Auto-Blacklist)

| Code | Description | Action |
|------|-------------|--------|
| AC04 | Account closed | Auto-blacklist |
| AC06 | Account blocked | Auto-blacklist |
| AG01 | Transaction forbidden | Auto-blacklist |
| MD01 | No mandate | Auto-blacklist |
| AM04 | Insufficient funds | Retry allowed |
| MS03 | Reason not specified | Review |

---

## Performance Targets

| Metric | Target |
|--------|--------|
| Transactions/month | 3-5 million |
| Upload processing | <100ms for 100 rows |
| Billing throughput | 50 req/sec |
| VOP verification | 500ms delay between calls |
| Webhook response | <200ms |
| Reconciliation | After 2h, max 10 attempts |
