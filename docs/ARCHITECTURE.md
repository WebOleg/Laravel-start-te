# Architecture Overview

## System Architecture
```
┌─────────────────────────────────────────────────────────────────────────┐
│                              CLIENTS                                     │
│                    (Next.js Admin Panel, API)                           │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                              NGINX                                       │
│                         (Reverse Proxy)                                  │
│                         Port: 8000                                       │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                         LARAVEL APPLICATION                              │
│                                                                          │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐    │
│  │   Routes    │→ │ Controllers │→ │   Models    │→ │  Resources  │    │
│  │  (api.php)  │  │   (Admin/)  │  │ (Eloquent)  │  │   (JSON)    │    │
│  └─────────────┘  └─────────────┘  └─────────────┘  └─────────────┘    │
│                                                                          │
│  ┌─────────────────────────────────────────────────────────────────┐   │
│  │                    Laravel Sanctum                               │   │
│  │                  (API Authentication)                            │   │
│  └─────────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                           POSTGRESQL                                     │
│                            Port: 5432                                    │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────────────┐           │
│  │ uploads  │ │ debtors  │ │ vop_logs │ │ billing_attempts │           │
│  └──────────┘ └──────────┘ └──────────┘ └──────────────────┘           │
└─────────────────────────────────────────────────────────────────────────┘
```

## Directory Structure
```
tether-laravel/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Admin/                 # Admin panel controllers
│   │   │   │   ├── UploadController.php
│   │   │   │   ├── DebtorController.php
│   │   │   │   ├── VopLogController.php
│   │   │   │   └── BillingAttemptController.php
│   │   │   └── Api/                   # Public API controllers
│   │   │       └── AuthController.php
│   │   └── Resources/                 # JSON transformers
│   │       ├── UploadResource.php
│   │       ├── DebtorResource.php
│   │       ├── VopLogResource.php
│   │       └── BillingAttemptResource.php
│   └── Models/                        # Eloquent models
│       ├── User.php
│       ├── Upload.php
│       ├── Debtor.php
│       ├── VopLog.php
│       └── BillingAttempt.php
├── bootstrap/
│   └── app.php                        # Application bootstrap
├── database/
│   ├── factories/                     # Test data factories
│   ├── migrations/                    # Database schema
│   └── seeders/                       # Development seeds
├── docs/                              # Documentation
├── routes/
│   ├── api.php                        # API routes
│   └── web.php                        # Web routes
├── tests/
│   └── Feature/
│       └── Admin/                     # API tests
├── docker-compose.yml                 # Docker configuration
└── Makefile                           # Development commands
```

## Database Schema

### Entity Relationship Diagram
```
┌─────────────┐       ┌─────────────┐
│    users    │       │   uploads   │
├─────────────┤       ├─────────────┤
│ id          │───┐   │ id          │
│ name        │   │   │ filename    │
│ email       │   └──▶│ uploaded_by │
│ password    │       │ status      │
└─────────────┘       │ total_records│
                      └──────┬──────┘
                             │
                             │ 1:N
                             ▼
                      ┌─────────────┐
                      │   debtors   │
                      ├─────────────┤
                      │ id          │
                      │ upload_id   │◀─────────────────────┐
                      │ iban        │                      │
                      │ first_name  │                      │
                      │ last_name   │                      │
                      │ amount      │                      │
                      │ status      │                      │
                      └──────┬──────┘                      │
                             │                             │
              ┌──────────────┴──────────────┐              │
              │ 1:N                    1:N  │              │
              ▼                             ▼              │
       ┌─────────────┐              ┌──────────────────┐   │
       │  vop_logs   │              │ billing_attempts │   │
       ├─────────────┤              ├──────────────────┤   │
       │ id          │              │ id               │   │
       │ debtor_id   │              │ debtor_id        │   │
       │ upload_id   │──────────────│ upload_id        │───┘
       │ iban_valid  │              │ transaction_id   │
       │ vop_score   │              │ amount           │
       │ result      │              │ status           │
       └─────────────┘              │ attempt_number   │
                                    └──────────────────┘
```

### Table Descriptions

#### `uploads`
Stores information about uploaded CSV files containing debtor data.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| filename | string | System-generated filename |
| original_filename | string | User's original filename |
| file_path | string | Storage path |
| file_size | integer | File size in bytes |
| mime_type | string | File MIME type |
| status | enum | pending, processing, completed, failed |
| total_records | integer | Total rows in CSV |
| processed_records | integer | Successfully processed rows |
| failed_records | integer | Failed rows |
| uploaded_by | bigint | User FK (nullable) |

#### `debtors`
Individual debtor records extracted from CSV uploads.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| upload_id | bigint | FK to uploads |
| iban | string | Bank account (encrypted) |
| iban_hash | string | For duplicate detection |
| first_name | string | Debtor first name |
| last_name | string | Debtor last name |
| email | string | Contact email |
| phone | string | Contact phone |
| amount | decimal | Debt amount |
| currency | string | Currency code (EUR) |
| status | enum | pending, processing, recovered, failed |
| risk_class | enum | low, medium, high |

#### `vop_logs`
IBAN verification results from VOP (Verification of Payee) service.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| debtor_id | bigint | FK to debtors |
| upload_id | bigint | FK to uploads |
| iban_masked | string | Masked IBAN for display |
| iban_valid | boolean | Checksum validation |
| bank_identified | boolean | Bank found in registry |
| bank_name | string | Bank name (if identified) |
| bic | string | Bank BIC code |
| vop_score | tinyint | Confidence score 0-100 |
| result | enum | verified, likely_verified, inconclusive, mismatch, rejected |

#### `billing_attempts`
SEPA Direct Debit payment attempts.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| debtor_id | bigint | FK to debtors |
| upload_id | bigint | FK to uploads |
| transaction_id | string | Internal transaction ID |
| unique_id | string | Payment gateway ID |
| amount | decimal | Amount charged |
| currency | string | Currency code |
| status | enum | pending, approved, declined, error, voided |
| attempt_number | integer | Retry counter |
| error_code | string | Bank error code |
| error_message | string | Human-readable error |
| processed_at | timestamp | When processed |

## Request Lifecycle
```
1. HTTP Request
       │
       ▼
2. Routes (api.php)
   - Matches URL pattern
   - Applies middleware (auth:sanctum)
       │
       ▼
3. Controller
   - Receives Request object
   - Validates input
   - Calls Model methods
       │
       ▼
4. Model (Eloquent)
   - Builds database query
   - Loads relationships
   - Returns Collection/Model
       │
       ▼
5. Resource
   - Transforms Model to array
   - Hides sensitive fields
   - Formats dates
       │
       ▼
6. JSON Response
   - Wrapped in data/meta structure
   - Sent to client
```

## Design Patterns

### Repository Pattern (Future)
Controllers will use repositories for complex queries.

### Resource Pattern
API Resources transform Models to JSON, ensuring:
- Consistent structure
- Hidden sensitive fields (IBAN)
- Computed fields (full_name, masked_iban)

### Factory Pattern
Factories generate test data with realistic values and states.

## Security

### Authentication
- Laravel Sanctum for API tokens
- Tokens stored in `personal_access_tokens` table
- Stateless authentication for API

### Data Protection
- IBAN never exposed in API (masked)
- IBAN hash for duplicate detection
- Soft deletes preserve audit trail

### Authorization (Future)
- Role-based access control
- Admin vs Client permissions
