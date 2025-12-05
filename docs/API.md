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
| 401 | Unauthorized (missing/invalid token) |
| 404 | Resource not found |
| 422 | Validation error |
| 500 | Server error |
