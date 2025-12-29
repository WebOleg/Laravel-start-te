# API Documentation

## Overview

Tether API uses REST architecture with JSON responses. All endpoints require authentication via Bearer token.

**Base URL:** `http://localhost:8000/api`

## Table of Contents

1. [Authentication](#authentication)
2. [Response Format](#response-format)
3. [Dashboard](#dashboard)
4. [Uploads](#uploads)
5. [Debtors](#debtors)
6. [Validation](#validation)
7. [VOP Verification](#vop-verification)
8. [Billing](#billing)
9. [Statistics](#statistics)
10. [Webhooks](#webhooks)
11. [Processing Flow](#processing-flow)

---

## Authentication

All API requests must include the `Authorization` header:
```
Authorization: Bearer {token}
```

### Login
```
POST /api/login
Content-Type: application/json

{
    "email": "admin@tether.test",
    "password": "password"
}
```

**Response:**
```json
{
    "token": "1|abc123...",
    "user": {
        "id": 1,
        "name": "Admin User",
        "email": "admin@tether.test"
    }
}
```

### Logout
```
POST /api/logout
Authorization: Bearer {token}
```

### Get Current User
```
GET /api/user
Authorization: Bearer {token}
```

---

## Response Format

### Success Response
```json
{
    "data": { ... },
    "meta": {
        "current_page": 1,
        "per_page": 20,
        "total": 100
    }
}
```

### Error Response
```json
{
    "message": "Validation failed.",
    "errors": ["Field is required"],
    "status": 422
}
```

### Pagination

All list endpoints support pagination:

| Parameter | Default | Description |
|-----------|---------|-------------|
| `page` | 1 | Page number |
| `per_page` | 20 | Items per page (max: 100) |

**Example:**
```
GET /api/admin/debtors?page=2&per_page=50
```

### HTTP Status Codes

| Code | Description |
|------|-------------|
| 200 | Success |
| 201 | Created |
| 202 | Accepted (async job queued) |
| 401 | Unauthorized |
| 404 | Resource not found |
| 409 | Conflict (duplicate operation) |
| 422 | Validation error |
| 500 | Server error |

---

## Dashboard

### Get Dashboard Statistics
```
GET /api/admin/dashboard
Authorization: Bearer {token}
```

**Response:**
```json
{
    "data": {
        "uploads": {
            "total": 25,
            "pending": 2,
            "processing": 1,
            "completed": 20,
            "failed": 2,
            "today": 3,
            "this_week": 12
        },
        "debtors": {
            "total": 5000,
            "by_status": {
                "pending": 3000,
                "processing": 500,
                "recovered": 1200,
                "failed": 300
            },
            "total_amount": 750000.00,
            "recovered_amount": 180000.00,
            "recovery_rate": 24.0,
            "by_country": {
                "ES": 2000,
                "DE": 1500,
                "NL": 800
            },
            "valid_iban_rate": 94.5
        },
        "vop": {
            "total": 4500,
            "by_result": {
                "verified": 3500,
                "likely_verified": 500,
                "inconclusive": 300,
                "mismatch": 150,
                "rejected": 50
            },
            "verification_rate": 88.9,
            "average_score": 82.5,
            "today": 150
        },
        "billing": {
            "total_attempts": 8000,
            "by_status": {
                "approved": 6500,
                "pending": 800,
                "declined": 500,
                "error": 150,
                "chargebacked": 50
            },
            "approval_rate": 81.25,
            "total_approved_amount": 975000.00,
            "today": 250
        },
        "recent_activity": {
            "recent_uploads": [...],
            "recent_billing": [...]
        },
        "trends": [...]
    }
}
```

---

## Uploads

### List Uploads
```
GET /api/admin/uploads
Authorization: Bearer {token}
```

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `status` | string | Filter: `pending`, `processing`, `completed`, `failed` |
| `page` | integer | Page number |
| `per_page` | integer | Items per page |

**Response:**
```json
{
    "data": [
        {
            "id": 1,
            "filename": "uuid.csv",
            "original_filename": "december_debtors.csv",
            "file_size": 79400,
            "mime_type": "text/csv",
            "status": "completed",
            "total_records": 100,
            "processed_records": 98,
            "failed_records": 2,
            "success_rate": 96.0,
            "headers": ["iban", "name", "amount", "city"],
            "processing_started_at": "2025-12-04T10:00:00Z",
            "processing_completed_at": "2025-12-04T10:05:00Z",
            "created_at": "2025-12-04T09:59:00Z",
            "skipped": {
                "total": 5,
                "blacklisted": 2,
                "chargebacked": 1,
                "already_recovered": 1,
                "recently_attempted": 1
            }
        }
    ],
    "meta": { ... }
}
```

### Get Upload
```
GET /api/admin/uploads/{id}
Authorization: Bearer {token}
```

### Create Upload
```
POST /api/admin/uploads
Content-Type: multipart/form-data
Authorization: Bearer {token}

file: (binary)
```

**Supported Formats:** CSV, XLSX, XLS, TXT

**Required Columns:** IBAN, Amount, Name (first_name/last_name or combined)

**Response (sync, ≤100 rows):**
```json
{
    "data": { ... },
    "meta": {
        "queued": false,
        "created": 95,
        "failed": 2,
        "skipped": {
            "total": 3,
            "blacklisted": 1,
            "chargebacked": 1,
            "already_recovered": 0,
            "recently_attempted": 1
        },
        "errors": [
            {"row": 15, "message": "Parse error", "data": {...}}
        ]
    }
}
```

**Response (async, >100 rows):**
```json
{
    "data": { "id": 15, "status": "pending", ... },
    "meta": {
        "queued": true,
        "message": "File queued for processing. Check status for updates."
    }
}
```

**Pre-Validation Errors (422):**

| Error | Description |
|-------|-------------|
| `Unsupported file type.` | Not CSV/XLSX/XLS/TXT |
| `File is empty or has no headers.` | No rows |
| `Missing required column: IBAN.` | No IBAN column |
| `Missing required column: amount.` | No amount column |
| `Missing required column: name.` | No name column |

### Get Upload Status
```
GET /api/admin/uploads/{id}/status
Authorization: Bearer {token}
```

**Response:**
```json
{
    "data": {
        "id": 15,
        "status": "processing",
        "total_records": 5000,
        "processed_records": 2500,
        "failed_records": 3,
        "progress": 50.06,
        "is_complete": false
    }
}
```

### Delete Upload
```
DELETE /api/admin/uploads/{id}
Authorization: Bearer {token}
```

### Filter Chargebacks
```
POST /api/admin/uploads/{id}/filter-chargebacks
Authorization: Bearer {token}
```

Removes chargebacked debtors from upload (soft delete).

**Response:**
```json
{
    "message": "Removed 5 chargebacked records",
    "data": {
        "removed": 5
    }
}
```

---

## Debtors

### List Debtors
```
GET /api/admin/debtors
Authorization: Bearer {token}
```

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `upload_id` | integer | Filter by upload |
| `status` | string | Filter: `pending`, `processing`, `recovered`, `failed` |
| `validation_status` | string | Filter: `pending`, `valid`, `invalid` |
| `country` | string | Filter by country code |
| `risk_class` | string | Filter: `low`, `medium`, `high` |
| `search` | string | Search name, IBAN, email |

**Response:**
```json
{
    "data": [
        {
            "id": 101,
            "upload_id": 1,
            "iban_masked": "ES05****0723",
            "iban_valid": true,
            "first_name": "Maria",
            "last_name": "Rodriguez",
            "full_name": "Maria Rodriguez",
            "email": "maria@example.com",
            "phone": "638549256",
            "street": "JUAN RAMON JIMENEZ",
            "street_number": "7",
            "postcode": "21740",
            "city": "HINOJOS",
            "province": "Huelva",
            "country": "ES",
            "amount": 150.00,
            "currency": "EUR",
            "status": "pending",
            "validation_status": "valid",
            "validation_errors": null,
            "risk_class": "medium",
            "bank_name": "DEUTSCHE BANK",
            "bic": "DEUTESBBXXX",
            "raw_data": { ... },
            "created_at": "2025-12-04T10:00:00Z",
            "latest_vop": { ... },
            "latest_billing": { ... }
        }
    ],
    "meta": { ... }
}
```

### Get Debtor
```
GET /api/admin/debtors/{id}
Authorization: Bearer {token}
```

### Update Debtor
```
PUT /api/admin/debtors/{id}
Content-Type: application/json
Authorization: Bearer {token}

{
    "raw_data": {
        "first_name": "Johann",
        "last_name": "Mueller",
        "iban": "DE89370400440532013000",
        "amount": "200.00"
    }
}
```

Updates debtor fields and triggers re-validation.

### Delete Debtor
```
DELETE /api/admin/debtors/{id}
Authorization: Bearer {token}
```

---

## Validation

### Get Upload Debtors
```
GET /api/admin/uploads/{id}/debtors
Authorization: Bearer {token}
```

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `validation_status` | string | Filter: `pending`, `valid`, `invalid` |
| `search` | string | Search by name, IBAN |
| `per_page` | integer | Items per page |

### Validate Upload
```
POST /api/admin/uploads/{id}/validate
Authorization: Bearer {token}
```

Triggers validation for all pending debtors.

**Response:**
```json
{
    "message": "Validation completed",
    "data": {
        "total": 100,
        "valid": 85,
        "invalid": 15
    }
}
```

### Get Validation Stats
```
GET /api/admin/uploads/{id}/validation-stats
Authorization: Bearer {token}
```

**Response:**
```json
{
    "data": {
        "total": 100,
        "valid": 85,
        "invalid": 10,
        "pending": 5,
        "blacklisted": 2,
        "chargebacked": 3,
        "ready_for_sync": 80
    }
}
```

### Validate Single Debtor
```
POST /api/admin/debtors/{id}/validate
Authorization: Bearer {token}
```

### Validation Rules

| Field | Rule | Error |
|-------|------|-------|
| IBAN | Required, valid checksum, SEPA country | "IBAN is required/invalid" |
| Name | first_name OR last_name required | "Name is required" |
| Name | Max 35 chars, no numbers/symbols | "Name contains invalid characters" |
| Amount | Required, > 0, ≤ 50000 | "Amount must be positive" |
| Email | Valid format (if provided) | "Email format is invalid" |
| Encoding | Valid UTF-8 | "Field contains encoding issues" |

---

## VOP Verification

VOP (Verification of Payee) verifies IBAN ownership via bank APIs.

**Supported Countries:** AT, BE, CY, DE, EE, ES, FI, FR, GR, HR, IE, IT, LT, LU, LV, MT, NL, PT, SI, SK

### Get VOP Stats
```
GET /api/admin/uploads/{id}/vop-stats
Authorization: Bearer {token}
```

**Response:**
```json
{
    "data": {
        "total_eligible": 85,
        "verified": 70,
        "pending": 15,
        "by_result": {
            "verified": 50,
            "likely_verified": 15,
            "inconclusive": 3,
            "mismatch": 2,
            "rejected": 0
        }
    }
}
```

### Start VOP Verification
```
POST /api/admin/uploads/{id}/verify-vop
Authorization: Bearer {token}
Content-Type: application/json

{
    "force": false
}
```

**Response (202 Accepted):**
```json
{
    "message": "VOP verification started",
    "data": {
        "upload_id": 15,
        "force_refresh": false
    }
}
```

### Get VOP Logs
```
GET /api/admin/uploads/{id}/vop-logs
Authorization: Bearer {token}
```
```
GET /api/admin/vop-logs
Authorization: Bearer {token}
```

**Query Parameters:** `upload_id`, `debtor_id`, `result`

**Response:**
```json
{
    "data": [
        {
            "id": 1,
            "debtor_id": 101,
            "upload_id": 15,
            "iban_masked": "DE89****3000",
            "iban_valid": true,
            "bank_identified": true,
            "bank_name": "Commerzbank",
            "bic": "COBADEFFXXX",
            "country": "DE",
            "vop_score": 100,
            "score_label": "high",
            "result": "verified",
            "is_positive": true,
            "is_negative": false,
            "created_at": "2025-12-18T10:00:00Z"
        }
    ]
}
```

### Verify Single IBAN
```
POST /api/admin/vop/verify-single
Authorization: Bearer {token}
Content-Type: application/json

{
    "iban": "DE89370400440532013000",
    "name": "Max Mustermann",
    "use_mock": false
}
```

**VOP Score Mapping:**

| name_match | Score | Result |
|------------|-------|--------|
| yes | 100 | verified |
| partial | 70 | likely_verified |
| unavailable | 50 | inconclusive |
| no | 20 | mismatch |
| invalid | 0 | rejected |

---

## Billing

Billing sends SEPA Direct Debit transactions to emerchantpay gateway.

### Start Billing (Sync to Gateway)
```
POST /api/admin/uploads/{id}/sync
Authorization: Bearer {token}
```

Dispatches async billing job for all eligible debtors.

**Eligibility Criteria:**
- `validation_status = valid`
- `status = pending`
- No existing `pending` or `approved` billing attempt

**Response (202 Accepted):**
```json
{
    "message": "Billing queued for 50 debtors",
    "data": {
        "upload_id": 31,
        "eligible": 50,
        "queued": true
    }
}
```

**Response (No eligible):**
```json
{
    "message": "No eligible debtors to bill",
    "data": {
        "upload_id": 31,
        "eligible": 0,
        "queued": false
    }
}
```

**Response (409 Conflict - Already processing):**
```json
{
    "message": "Billing already in progress",
    "data": {
        "upload_id": 31,
        "queued": true,
        "duplicate": true
    }
}
```

### Get Billing Stats
```
GET /api/admin/uploads/{id}/billing-stats
Authorization: Bearer {token}
```

**Response:**
```json
{
    "data": {
        "upload_id": 31,
        "is_processing": false,
        "total_attempts": 50,
        "approved": 0,
        "approved_amount": 0,
        "pending": 50,
        "pending_amount": 497.50,
        "declined": 0,
        "declined_amount": 0,
        "error": 0,
        "error_amount": 0
    }
}
```

| Field | Description |
|-------|-------------|
| `is_processing` | True if billing job is currently running |
| `total_attempts` | Total billing attempts created |
| `pending` | Awaiting bank confirmation (2-5 days for SEPA) |
| `approved` | Successfully processed |
| `declined` | Rejected by bank |
| `error` | Technical errors |

### List Billing Attempts
```
GET /api/admin/billing-attempts
Authorization: Bearer {token}
```

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `upload_id` | integer | Filter by upload |
| `debtor_id` | integer | Filter by debtor |
| `status` | string | Filter: `pending`, `approved`, `declined`, `error`, `voided`, `chargebacked` |

**Response:**
```json
{
    "data": [
        {
            "id": 1,
            "debtor_id": 101,
            "upload_id": 31,
            "transaction_id": "tether_101_20251229_Abc123",
            "unique_id": "09dcbd928440e5eb8faaf4be0698a58c",
            "amount": 9.99,
            "currency": "EUR",
            "status": "pending",
            "attempt_number": 1,
            "error_code": null,
            "error_message": null,
            "is_approved": false,
            "is_final": false,
            "can_retry": false,
            "processed_at": "2025-12-29T07:44:00Z",
            "created_at": "2025-12-29T07:44:00Z"
        }
    ],
    "meta": { ... }
}
```

### Get Billing Attempt
```
GET /api/admin/billing-attempts/{id}
Authorization: Bearer {token}
```

### Retry Failed Billing
```
POST /api/admin/billing-attempts/{id}/retry
Authorization: Bearer {token}
```

Retries a failed billing attempt (declined/error status only).

**Response (201 Created):**
```json
{
    "message": "Retry initiated successfully",
    "data": {
        "id": 52,
        "debtor_id": 101,
        "status": "pending",
        "attempt_number": 2,
        ...
    }
}
```

**Response (422 - Cannot retry):**
```json
{
    "message": "This billing attempt cannot be retried",
    "data": {
        "id": 14,
        "status": "approved",
        "can_retry": false
    }
}
```

### Billing Statuses

| Status | Description | Final | Can Retry |
|--------|-------------|-------|-----------|
| `pending` | Sent to gateway, awaiting bank | No | No |
| `approved` | Successfully processed | Yes | No |
| `declined` | Rejected by bank | Yes | Yes |
| `error` | Technical error | Yes | Yes |
| `voided` | Cancelled before processing | Yes | No |
| `chargebacked` | Reversed after approval | Yes | No |

### SEPA Error Codes

| Code | Description | Auto-Blacklist |
|------|-------------|----------------|
| AC04 | Account closed | Yes |
| AC06 | Account blocked | Yes |
| AG01 | Transaction forbidden | Yes |
| MD01 | No mandate | Yes |
| AM04 | Insufficient funds | No |
| MS03 | Reason not specified | No |

---

## Statistics

### Chargeback Rates by Country
```
GET /api/admin/stats/chargeback-rates
Authorization: Bearer {token}
```

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `period` | string | `7d` | `24h`, `7d`, `30d`, `90d` |

**Response:**
```json
{
    "data": {
        "period": "7d",
        "start_date": "2025-12-22T00:00:00Z",
        "threshold": 25,
        "countries": [
            {
                "country": "ES",
                "total": 100,
                "approved": 85,
                "declined": 10,
                "errors": 3,
                "chargebacks": 2,
                "cb_rate_total": 2.0,
                "cb_rate_approved": 2.35,
                "alert": false
            }
        ],
        "totals": { ... }
    }
}
```

### Chargeback Codes
```
GET /api/admin/stats/chargeback-codes
Authorization: Bearer {token}
```

**Response:**
```json
{
    "data": {
        "period": "7d",
        "codes": [
            {
                "chargeback_code": "AC04",
                "chargeback_reason": "Account closed",
                "occurrences": 15,
                "total_amount": 2500.00
            }
        ],
        "totals": {
            "occurrences": 23,
            "total_amount": 3700.00
        }
    }
}
```

### Chargeback Banks
```
GET /api/admin/stats/chargeback-banks
Authorization: Bearer {token}
```

**Response:**
```json
{
    "data": {
        "period": "7d",
        "banks": [
            {
                "bank_name": "N26",
                "total_amount": 21953.99,
                "chargebacks": 10,
                "cb_rate": 13.16
            }
        ],
        "totals": {
            "total": 600,
            "total_amount": 164859.14,
            "chargebacks": 65,
            "total_cb_rate": 10.83
        }
    }
}
```

---

## Webhooks

### EMP Webhook
```
POST /api/webhooks/emp
Content-Type: application/json
```

Receives emerchantpay notifications.

**Transaction Update:**
```json
{
    "unique_id": "tx_123",
    "transaction_type": "sdd_sale",
    "status": "approved",
    "signature": "sha1_hash"
}
```

**Chargeback Notification:**
```json
{
    "unique_id": "cb_456",
    "transaction_type": "chargeback",
    "status": "approved",
    "original_transaction_unique_id": "tx_123",
    "amount": 10000,
    "currency": "EUR",
    "reason": "Account closed",
    "reason_code": "AC04",
    "signature": "sha1_hash"
}
```

**Signature:** `SHA1(unique_id + EMP_PASSWORD)`

**Chargeback Processing:**
1. Find original transaction by `unique_id`
2. Update billing_attempt status to `chargebacked`
3. Store error_code and error_message
4. Auto-blacklist IBAN if code in `['AC04', 'AC06', 'AG01', 'MD01']`

---

## Processing Flow

### Complete Workflow
```
┌─────────────────────────────────────────────────────────────┐
│                    1. UPLOAD CSV                             │
│  POST /api/admin/uploads                                     │
│  → Pre-validation (structure check)                          │
│  → Deduplication (blacklist, chargeback, cooldown)          │
│  → Create debtors                                           │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                    2. VALIDATE                               │
│  POST /api/admin/uploads/{id}/validate                       │
│  → IBAN checksum, name format, amount range                 │
│  → Updates validation_status: valid/invalid                  │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                 3. VOP VERIFY (Optional)                     │
│  POST /api/admin/uploads/{id}/verify-vop                     │
│  → Verifies IBAN ownership with bank                        │
│  → Reduces chargebacks by 30-50%                            │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                    4. SYNC TO GATEWAY                        │
│  POST /api/admin/uploads/{id}/sync                           │
│  → Creates billing_attempts                                  │
│  → Sends SDD transactions to emerchantpay                   │
│  → Status: pending (awaiting bank)                          │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                    5. WEBHOOK UPDATES                        │
│  POST /api/webhooks/emp                                      │
│  → Updates status: approved/declined/chargebacked           │
│  → Auto-blacklist on certain error codes                    │
└─────────────────────────────────────────────────────────────┘
```

### Deduplication Rules (Stage 1)

| Rule | Block Type | Description |
|------|------------|-------------|
| Blacklisted | Permanent | IBAN in blacklist table |
| Chargebacked | Permanent | IBAN has previous chargeback |
| Already Recovered | Permanent | Debt recovered for IBAN |
| Recently Attempted | 7-day cooldown | Billing within last 7 days |

### Billing Processing

- **Async:** Jobs run on `billing` queue
- **Chunked:** 50 debtors per chunk
- **Rate Limited:** 50 requests/second to EMP
- **Circuit Breaker:** 10 failures → 5 minute pause
- **Idempotency:** Cache lock prevents duplicate dispatches (5 min)
- **SEPA DD:** Pending status for 2-5 business days

---

## Environment Variables
```bash
# Database
DB_CONNECTION=pgsql
DB_HOST=db
DB_DATABASE=tether
DB_USERNAME=tether
DB_PASSWORD=secret

# emerchantpay
EMP_USERNAME=your_username
EMP_PASSWORD=your_password
EMP_TERMINAL_TOKEN=your_token
EMP_API_URL=https://staging.gate.emerchantpay.net/process/
EMP_MOCK_MODE=false

# IBAN.com VOP
IBAN_API_KEY=your_api_key
IBAN_API_URL=https://api.iban.com/clients/api/v4/iban/
IBAN_API_MOCK=true

# Queue
QUEUE_CONNECTION=redis
REDIS_HOST=redis
```
