# API Documentation

## Overview

Tether API uses REST architecture with JSON responses. All endpoints require authentication via Bearer token.

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
    "message": "Unauthenticated.",
    "status": 401
}
```

## Pagination

All list endpoints support pagination:

| Parameter | Default | Description |
|-----------|---------|-------------|
| `page` | 1 | Page number |
| `per_page` | 20 | Items per page (max: 100) |

**Example:**
```
GET /api/admin/debtors?page=2&per_page=50
```

---

## Endpoints

### Dashboard

#### Get Dashboard Statistics
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
            "failed": 2
        },
        "debtors": {
            "total": 5000,
            "by_status": {
                "pending": 3000,
                "processing": 500,
                "recovered": 1200,
                "failed": 300
            },
            "by_country": {
                "ES": 2000,
                "DE": 1500,
                "NL": 800,
                "FR": 500,
                "IT": 200
            }
        },
        "recent_activity": {
            "latest_uploads": [...],
            "latest_debtors": [...]
        }
    }
}
```

---

### Stats

#### Get Chargeback Rates by Country
```
GET /api/admin/stats/chargeback-rates
Authorization: Bearer {token}
```

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `period` | string | `7d` | Time period: `24h`, `7d`, `30d`, `90d` |

**Response:**
```json
{
    "data": {
        "period": "7d",
        "start_date": "2025-12-09T00:00:00+00:00",
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
            },
            {
                "country": "DE",
                "total": 50,
                "approved": 30,
                "declined": 5,
                "errors": 0,
                "chargebacks": 15,
                "cb_rate_total": 30.0,
                "cb_rate_approved": 50.0,
                "alert": true
            }
        ],
        "totals": {
            "total": 150,
            "approved": 115,
            "declined": 15,
            "errors": 3,
            "chargebacks": 17,
            "cb_rate_total": 11.33,
            "cb_rate_approved": 14.78,
            "alert": false
        }
    }
}
```

| Field | Description |
|-------|-------------|
| `period` | Requested time period |
| `start_date` | Start date for the period |
| `threshold` | Alert threshold percentage (default: 25%) |
| `countries` | Stats grouped by country |
| `totals` | Aggregate totals across all countries |
| `cb_rate_total` | Chargebacks / Total transactions * 100 |
| `cb_rate_approved` | Chargebacks / Approved transactions * 100 |
| `alert` | True if rate exceeds threshold |

---

#### Get Chargeback Codes Statistics
```
GET /api/admin/stats/chargeback-codes
Authorization: Bearer {token}
```

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `period` | string | `7d` | Time period: `24h`, `7d`, `30d`, `90d` |

**Response:**
```json
{
    "data": {
        "period": "7d",
        "start_date": "2025-12-09T00:00:00+00:00",
        "codes": [
            {
                "chargeback_code": "AC04",
                "chargeback_reason": "Account closed",
                "occurrences": 15,
                "total_amount": 2500.00
            },
            {
                "chargeback_code": "MD01",
                "chargeback_reason": "No mandate",
                "occurrences": 8,
                "total_amount": 1200.00
            }
        ],
        "totals": {
            "occurrences": 23,
            "total_amount": 3700.00
        }
    }
}
```

| Field | Description |
|-------|-------------|
| `codes` | Stats grouped by error code |
| `chargeback_code` | SEPA error code |
| `chargeback_reason` | Human-readable description |
| `occurrences` | Number of chargebacks with this code |
| `total_amount` | Sum of chargeback amounts |

---

#### Get Chargeback Bank Statistics
```
GET /api/admin/stats/chargeback-banks
Authorization: Bearer {token}
```

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `period`  | string | `7d`  | Time period: `24h`, `7d`, `30d`, `90d` |

**Response:**
```json
{
    "data": {
        "period": "7d",
        "start_date": "2025-12-09T00:00:00+00:00",
        "banks": [
            {
                "bank_name": "N26",
                "total_amount": 21953.99,
                "chargebacks": 10,
                "cb_rate": 13.16
            },
            {
                "bank_name": "Sparkasse",
                "total_amount": 12734.32,
                "chargebacks": 4,
                "cb_rate": 10.53
            },
            {
                "bank_name": "Volksbank",
                "total_amount": 12924.53,
                "chargebacks": 6,
                "cb_rate": 13.04
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

| Field | Description |
|-------|-------------|
| `banks` | Stats grouped by bank name |
| `bank_name` | Bank name |
| `total_amount` | Total amount of this bank |
| `chargebacks` | Number of chargebacks with this bank |
| `cb_rate` | Chargebacks / Total transactions * 100 |

---


### Uploads

#### List Uploads
```
GET /api/admin/uploads
```

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `status` | string | Filter by status: `pending`, `processing`, `completed`, `failed` |

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
            "processing_started_at": "2025-12-04T10:00:00Z",
            "processing_completed_at": "2025-12-04T10:05:00Z",
            "created_at": "2025-12-04T09:59:00Z",
            "updated_at": "2025-12-04T10:05:00Z",
            "debtors_count": 100,
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

#### Get Upload
```
GET /api/admin/uploads/{id}
```

**Response:** Single upload object with same structure.

#### Create Upload
```
POST /api/admin/uploads
Content-Type: multipart/form-data
Authorization: Bearer {token}

file: (binary)
```

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

**Skipped Reasons:**

| Reason | Block Type | Description |
|--------|------------|-------------|
| `blacklisted` | Permanent | IBAN exists in blacklist table |
| `chargebacked` | Permanent | IBAN has previous chargeback |
| `already_recovered` | Permanent | Debt already recovered for this IBAN |
| `recently_attempted` | 30-day cooldown | Billing attempt within last 30 days |

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

#### Get Upload Status
```
GET /api/admin/uploads/{id}/status
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

#### Filter Chargebacks

Remove chargebacked debtors from an upload (for retry scenarios).
```
POST /api/admin/uploads/{id}/filter-chargebacks
Authorization: Bearer {token}
```

**Response:**
```json
{
    "message": "Removed 5 chargebacked records",
    "data": {
        "removed": 5
    }
}
```

**Use Case:**
1. Upload CSV with 100 records
2. Send payments to EMP
3. 5 chargebacks received over time
4. Before retry: call filter-chargebacks to remove those 5 records
5. Retry only processes clean records

---

### Debtors

#### List Debtors
```
GET /api/admin/debtors
```

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `upload_id` | integer | Filter by upload ID |
| `status` | string | Filter: `pending`, `processing`, `recovered`, `failed` |
| `country` | string | Filter by country code (ES, DE, FR, NL, IT) |
| `risk_class` | string | Filter: `low`, `medium`, `high` |

**Response:**
```json
{
    "data": [
        {
            "id": 101,
            "upload_id": 1,
            
            "iban_masked": "ES05****0723",
            "bank_name": "DEUTSCHE BANK",
            "bank_code": "0019",
            "bic": "DEUTESBBXXX",
            
            "first_name": "Maria",
            "last_name": "Rodriguez",
            "full_name": "Maria Rodriguez",
            "email": null,
            "phone": "638549256",
            "primary_phone": "638549256",
            "national_id": "52268154X",
            "birth_date": "1964-01-12",
            
            "street": "JUAN RAMON JIMENEZ",
            "street_number": "7",
            "floor": null,
            "door": null,
            "apartment": null,
            "postcode": "21740",
            "city": "HINOJOS",
            "province": "Huelva",
            "country": "ES",
            "full_address": "JUAN RAMON JIMENEZ 7\n21740, HINOJOS, Huelva, ES",
            
            "amount": 150.00,
            "currency": "EUR",
            "sepa_type": "CORE",
            
            "status": "pending",
            "risk_class": "medium",
            "iban_valid": true,
            "name_matched": true,
            
            "external_reference": "ORDER-12345",
            "created_at": "2025-12-04T10:00:00Z",
            "updated_at": "2025-12-04T10:00:00Z"
        }
    ],
    "meta": { ... }
}
```

#### Get Debtor
```
GET /api/admin/debtors/{id}
```

**Response:** Single debtor with related `upload`, `vopLogs`, `billingAttempts`.

---

### VOP Logs

VOP (Verification of Payee) logs contain IBAN validation results.

#### List VOP Logs
```
GET /api/admin/vop-logs
```

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `upload_id` | integer | Filter by upload ID |
| `debtor_id` | integer | Filter by debtor ID |
| `result` | string | Filter: `verified`, `likely_verified`, `inconclusive`, `mismatch`, `rejected` |

**Response:**
```json
{
    "data": [
        {
            "id": 1,
            "debtor_id": 101,
            "upload_id": 1,
            "iban_masked": "ES05****0723",
            "iban_valid": true,
            "bank_identified": true,
            "bank_name": "Deutsche Bank",
            "bic": "DEUTESBBXXX",
            "country": "ES",
            "vop_score": 85,
            "score_label": "high",
            "result": "verified",
            "is_positive": true,
            "is_negative": false,
            "created_at": "2025-12-04T10:01:00Z"
        }
    ],
    "meta": { ... }
}
```

**VOP Score Ranges:**

| Score | Label | Result |
|-------|-------|--------|
| 80-100 | high | verified |
| 60-79 | medium | likely_verified |
| 40-59 | low | inconclusive |
| 20-39 | low | mismatch |
| 0-19 | low | rejected |

---

### Billing Attempts

#### List Billing Attempts
```
GET /api/admin/billing-attempts
```

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `upload_id` | integer | Filter by upload ID |
| `debtor_id` | integer | Filter by debtor ID |
| `status` | string | Filter: `pending`, `approved`, `declined`, `error`, `voided`, `chargebacked` |

**Response:**
```json
{
    "data": [
        {
            "id": 1,
            "debtor_id": 101,
            "upload_id": 1,
            "transaction_id": "TXN-ABC123",
            "unique_id": "EMG-XYZ789",
            "amount": 150.00,
            "currency": "EUR",
            "status": "approved",
            "attempt_number": 2,
            "mid_reference": null,
            "error_code": null,
            "error_message": null,
            "is_approved": true,
            "is_final": true,
            "can_retry": false,
            "processed_at": "2025-12-04T10:05:00Z",
            "created_at": "2025-12-04T10:05:00Z"
        }
    ],
    "meta": { ... }
}
```

**Common Error Codes:**

| Code | Description | Auto-Blacklist |
|------|-------------|----------------|
| AC04 | Account closed | Yes |
| AC06 | Account blocked | Yes |
| AG01 | Transaction forbidden | Yes |
| MD01 | No mandate | Yes |
| AM04 | Insufficient funds | No |
| MS03 | Reason not specified | No |

---

## Webhooks

### EMP (emerchantpay) Webhook
```
POST /api/webhooks/emp
Content-Type: application/json
```

Receives notifications from emerchantpay about transaction status changes and chargebacks.

**Chargeback Notification:**
```json
{
    "unique_id": "cb_unique_456",
    "transaction_type": "chargeback",
    "status": "approved",
    "original_transaction_unique_id": "tx_original_123",
    "amount": 10000,
    "currency": "EUR",
    "reason": "Account closed",
    "reason_code": "AC04",
    "signature": "sha1_hash"
}
```

**Transaction Status Update:**
```json
{
    "unique_id": "tx_123",
    "transaction_type": "sdd_sale",
    "status": "approved",
    "signature": "sha1_hash"
}
```

**Signature Verification:**
```
signature = SHA1(unique_id + EMP_PASSWORD)
```

**Response:**
```json
{
    "status": "ok",
    "message": "Chargeback processed"
}
```

**Webhook Flow:**
1. EMP sends POST request to `/api/webhooks/emp`
2. System verifies signature
3. For chargebacks:
   - Find original transaction
   - Update billing_attempt status to `chargebacked`
   - Store error_code and error_message
   - **Auto-blacklist IBAN** if error code is in blacklist_codes (AC04, AC06, AG01, MD01)
4. For transactions: updates billing_attempt status

**Auto-Blacklist Codes:**

Configurable in `config/tether.php`:
```php
'chargeback' => [
    'blacklist_codes' => ['AC04', 'AC06', 'AG01', 'MD01'],
]
```

When a chargeback is received with one of these codes, the debtor's IBAN is automatically added to the blacklist with reason "chargeback" and source "Auto-blacklisted: {code}".

---

## HTTP Status Codes

| Code | Description |
|------|-------------|
| 200 | Success |
| 201 | Created (sync upload) |
| 202 | Accepted (async upload queued) |
| 401 | Unauthorized (missing/invalid token) |
| 404 | Resource not found |
| 422 | Validation error |
| 500 | Server error |

---

## Two-Stage Processing Flow

### Stage 1: Upload (Deduplication)

During CSV upload, IBANs are checked against deduplication rules. Records that match are **skipped** (not created in database).

**Deduplication Rules:**

| Rule | Block Type | Check |
|------|------------|-------|
| Blacklisted | Permanent | `blacklists.iban_hash` exists |
| Chargebacked | Permanent | `billing_attempts.status = 'chargebacked'` |
| Already Recovered | Permanent | `debtors.status = 'recovered'` |
| Recently Attempted | 30-day cooldown | `billing_attempts.created_at > now() - 30 days` |

### Stage 2: Validation

After upload, records are validated for data quality. This happens automatically when viewing upload details.

#### Get Upload Debtors
```
GET /api/admin/uploads/{id}/debtors
```

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `validation_status` | string | Filter: `pending`, `valid`, `invalid` |
| `exclude_chargebacked` | boolean | Exclude debtors with chargebacked billing attempts |
| `search` | string | Search by name, IBAN, email |
| `per_page` | integer | Items per page (default: 50) |

**Response:**
```json
{
    "data": [
        {
            "id": 101,
            "upload_id": 1,
            "iban_masked": "ES05****0723",
            "first_name": "Maria",
            "last_name": "Rodriguez",
            "amount": 150.00,
            "validation_status": "valid",
            "validation_errors": null,
            "validated_at": "2025-12-11T10:00:00Z",
            "raw_data": {
                "first_name": "Maria",
                "last_name": "Rodriguez",
                "iban": "ES0500190050054010130723",
                "amount": "150.00",
                "custom_field": "extra_value"
            },
            "latest_billing": {
                "id": 1,
                "status": "approved",
                "amount": 150.00
            }
        }
    ],
    "meta": { ... }
}
```

#### Validate Upload
```
POST /api/admin/uploads/{id}/validate
Authorization: Bearer {token}
```

Triggers validation for all debtors with `validation_status=pending`.

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

**Error (upload still processing):**
```json
{
    "message": "Upload is still processing. Please wait.",
    "status": 422
}
```

#### Get Validation Stats
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
        "ready_for_sync": 85,
        "skipped": {
            "total": 3,
            "blacklisted": 1,
            "chargebacked": 1,
            "already_recovered": 0,
            "recently_attempted": 1
        }
    }
}
```

| Field | Description |
|-------|-------------|
| `total` | Total debtors created in upload |
| `valid` | Passed all validation rules |
| `invalid` | Failed validation |
| `pending` | Not yet validated |
| `blacklisted` | Invalid due to blacklist |
| `chargebacked` | Has billing_attempt with status='chargebacked' |
| `ready_for_sync` | Valid + pending status (ready for billing) |
| `skipped` | Records skipped during upload (from meta) |

---

### Individual Debtor Validation

#### Validate Single Debtor
```
POST /api/admin/debtors/{id}/validate
Authorization: Bearer {token}
```

**Response:**
```json
{
    "message": "Validation completed",
    "data": {
        "id": 101,
        "validation_status": "invalid",
        "validation_errors": [
            "IBAN is required",
            "City is required"
        ],
        "validated_at": "2025-12-11T10:00:00Z"
    }
}
```

#### Update Debtor (via raw_data)
```
PUT /api/admin/debtors/{id}
Content-Type: application/json
Authorization: Bearer {token}

{
    "raw_data": {
        "first_name": "Johann",
        "last_name": "Mueller",
        "iban": "DE89370400440532013000",
        "amount": "200.00",
        "city": "Berlin",
        "postcode": "10115",
        "address": "Main St 1"
    }
}
```

Updates debtor fields from raw_data and triggers re-validation.

**Response:** Updated debtor object with new `validation_status`.

---

## Validation Rules

Debtors are validated against these rules:

| Field | Rule | Error Message |
|-------|------|---------------|
| IBAN | Required, valid checksum, SEPA country | "IBAN is required" / "IBAN is invalid" |
| Name | first_name OR last_name required | "Name is required" |
| Name | Max 35 characters each | "First/Last name cannot exceed 35 characters" |
| Name | No numbers or symbols | "First/Last name contains numbers or symbols" |
| Amount | Required, > 0, ≤ 50000 | "Amount is required" / "Amount must be positive" |
| City | Required | "City is required" |
| Postcode | Required | "Postal code is required" |
| Address | street OR address required | "Address is required" |
| Email | Valid format (if provided) | "Email format is invalid" |
| Encoding | No broken UTF-8 characters | "Field contains encoding issues" |
| Blacklist | IBAN not in blacklist | "IBAN is blacklisted" |

**Name Character Validation:**

Names must contain only:
- English letters A-Z, a-z
- Spaces
- Hyphens `-` and apostrophes `'`
- Periods `.`

Rejected characters:
- Numbers 0-9
- Symbols: `*#@$%^&+=[]{}|\<>`
- Accented characters: `áàâäçèéêëîïíóòôöúùûüÿñ` (and uppercase variants)

---

## VOP Verification (BAV API)

VOP (Verification of Payee) uses iban.com BAV API to verify bank accounts with issuing banks.

### Supported Countries

AT, BE, CY, DE, EE, ES, FI, FR, GR, HR, IE, IT, LT, LU, LV, MT, NL, PT, SI, SK

### Get VOP Stats for Upload
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

| Field | Description |
|-------|-------------|
| `total_eligible` | Debtors with validation_status=valid and supported country |
| `verified` | Total VOP logs created |
| `pending` | Eligible but not yet verified |
| `by_result` | Count by VOP result category |

### Start VOP Verification for Upload
```
POST /api/admin/uploads/{id}/verify-vop
Authorization: Bearer {token}
Content-Type: application/json

{
    "force": false
}
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `force` | boolean | false | Re-verify even if already cached |

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

**Processing:**
- Job queued on `vop` queue
- Processes in chunks of 50 debtors
- 500ms delay between API calls (rate limiting)
- Results cached by IBAN hash (same IBAN = same bank)

### Get VOP Logs for Upload
```
GET /api/admin/uploads/{id}/vop-logs
Authorization: Bearer {token}
```

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
            "bank_name": null,
            "bic": "COBADEFFXXX",
            "country": "DE",
            "vop_score": 100,
            "result": "verified",
            "meta": {
                "name_match": "yes",
                "iban_hash": "abc123..."
            },
            "created_at": "2025-12-18T10:00:00Z"
        }
    ],
    "meta": { ... }
}
```

### Verify Single IBAN (Testing)
```
POST /api/admin/vop/verify-single
Authorization: Bearer {token}
Content-Type: application/json

{
    "iban": "DE89370400440532013000",
    "name": "Max Mustermann",
    "use_mock": true
}
```

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `iban` | string | Yes | IBAN to verify |
| `name` | string | Yes | Account holder name |
| `use_mock` | boolean | No | Use mock response (no API credit used) |

**Response:**
```json
{
    "data": {
        "success": true,
        "valid": true,
        "name_match": "yes",
        "bic": "COBADEFFXXX",
        "vop_score": 100,
        "vop_result": "verified",
        "error": null
    },
    "meta": {
        "mock_mode": true,
        "credits_used": 0
    }
}
```

**VOP Score Calculation:**

| name_match | Score | Result |
|------------|-------|--------|
| yes | 100 | verified |
| partial | 70 | likely_verified |
| unavailable | 50 | inconclusive |
| no | 20 | mismatch |
| (invalid IBAN) | 0 | rejected |

**API Response Times:**

BAV API response time varies: 200ms to 3 minutes depending on the bank.

**Environment Variables:**
```
IBAN_API_KEY=your_api_key
IBAN_API_URL=https://api.iban.com/clients/api/verify/v3/
IBAN_API_MOCK=true  # true for dev, false for production
```
