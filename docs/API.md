# API Documentation

## Overview

Tether API uses REST architecture with JSON responses. All endpoints require authentication via Bearer token.

## Authentication

All API requests must include the `Authorization` header:
```
Authorization: Bearer {token}
```

### Obtaining a Token
```bash
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
| `country` | string | Filter by country code (DE, AT, CH, NL) |
| `risk_class` | string | Filter: `low`, `medium`, `high` |

**Response:**
```json
{
    "data": [
        {
            "id": 101,
            "upload_id": 1,
            "iban_masked": "DE89****3000",
            "first_name": "Hans",
            "last_name": "Mueller",
            "full_name": "Hans Mueller",
            "email": "hans@example.com",
            "phone": "+4915112345678",
            "address": "Berliner Str. 15",
            "zip_code": "10115",
            "city": "Berlin",
            "country": "DE",
            "amount": 150.00,
            "currency": "EUR",
            "status": "pending",
            "risk_class": "medium",
            "external_reference": "ORDER-12345",
            "created_at": "2025-12-04T10:00:00Z"
        }
    ],
    "meta": { ... }
}
```

#### Get Debtor
```
GET /api/admin/debtors/{id}
```

**Response:** Single debtor with related `upload`, `vop_logs`, `billing_attempts`.

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
            "iban_masked": "DE89****3000",
            "iban_valid": true,
            "bank_identified": true,
            "bank_name": "Deutsche Bank",
            "bic": "DEUTDEDB",
            "country": "DE",
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
