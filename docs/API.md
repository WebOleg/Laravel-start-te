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
            "debtors_count": 100
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
        "created": 98,
        "failed": 2,
        "errors": [
            {"row": 15, "message": "IBAN is invalid", "data": {...}},
            {"row": 42, "message": "IBAN is blacklisted", "data": {...}}
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
| `status` | string | Filter: `pending`, `approved`, `declined`, `error`, `voided` |

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

| Code | Description |
|------|-------------|
| AC04 | Account closed |
| AC06 | Account blocked |
| AG01 | Transaction forbidden |
| AM04 | Insufficient funds |
| MD01 | No mandate |

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

### Upload Validation (Two-Stage Flow)

The upload process uses two stages:
- **Stage A (Upload):** Accept ALL rows, save with `validation_status=pending`
- **Stage B (Validation):** Run validation on demand, update statuses

#### Get Upload Debtors
```
GET /api/admin/uploads/{id}/debtors
```

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `validation_status` | string | Filter: `pending`, `valid`, `invalid` |
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
        "pending": 3,
        "blacklisted": 2,
        "ready_for_sync": 85
    }
}
```

| Field | Description |
|-------|-------------|
| `total` | Total debtors in upload |
| `valid` | Passed all validation rules |
| `invalid` | Failed validation (excluding blacklisted) |
| `pending` | Not yet validated |
| `blacklisted` | IBAN in blacklist |
| `ready_for_sync` | Valid + pending status (ready for billing) |

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
| IBAN | Required, valid checksum | "IBAN is required" / "IBAN is invalid" |
| Name | first_name OR last_name required | "Name is required" |
| Amount | Required, > 0, ≤ 50000 | "Amount is required" / "Amount must be positive" |
| City | Required, 2-100 chars, valid encoding | "City is required" / "City contains invalid characters" |
| Postcode | Required, 3-20 chars | "Postal code is required" |
| Address | Required, 5-200 chars, valid encoding | "Address is required" |
| Blacklist | IBAN not in blacklist | "IBAN is blacklisted" |

