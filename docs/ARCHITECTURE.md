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
│  ┌────────────┐ ┌─────────────────┐                                     │
│  │ blacklists │ │ bank_references │                                     │
│  └────────────┘ └─────────────────┘                                     │
└─────────────────────────────────────────────────────────────────────────┘
```

## Directory Structure
```
tether-laravel/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Admin/                 # Admin panel controllers
│   │   │   │   ├── DashboardController.php
│   │   │   │   ├── UploadController.php
│   │   │   │   ├── DebtorController.php
│   │   │   │   ├── VopController.php
│   │   │   │   ├── VopLogController.php
│   │   │   │   └── BillingAttemptController.php
│   │   │   └── Api/                   # Public API controllers
│   │   │       └── AuthController.php
│   │   └── Resources/                 # JSON transformers
│   │       ├── UploadResource.php
│   │       ├── DebtorResource.php
│   │       ├── VopLogResource.php
│   │       └── BillingAttemptResource.php
│   ├── Models/                        # Eloquent models
│   │   ├── User.php
│   │   ├── Upload.php
│   │   ├── Debtor.php
│   │   ├── VopLog.php
│   │   ├── BillingAttempt.php
│   │   ├── Blacklist.php
│   │   └── BankReference.php
│   ├── Services/                      # Business logic
│   │   ├── IbanValidator.php
│   │   ├── IbanApiService.php
│   │   ├── VopScoringService.php
│   │   ├── VopVerificationService.php
│   │   ├── SpreadsheetParserService.php
│   │   ├── FilePreValidationService.php  # Lightweight pre-validation
│   │   ├── FileUploadService.php
│   │   ├── DebtorValidationService.php
│   │   ├── DeduplicationService.php
│   │   └── BlacklistService.php
│   ├── Jobs/                          # Queue jobs
│   │   ├── ProcessUploadJob.php
│   │   ├── ProcessUploadChunkJob.php
│   │   ├── ProcessVopJob.php
│   │   └── ProcessVopChunkJob.php
│   └── Traits/                        # Shared traits
│       └── ParsesDebtorData.php
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
      │               └──────┬──────┘
      │                      │
      │                      │ 1:N
      │                      ▼
      │               ┌─────────────┐
      │               │   debtors   │
      │               ├─────────────┤
      │               │ id          │
      │               │ upload_id   │◀─────────────────────┐
      │               │ iban        │                      │
      │               │ first_name  │                      │
      │               │ last_name   │                      │
      │               │ amount      │                      │
      │               │ status      │                      │
      │               └──────┬──────┘                      │
      │                      │                             │
      │       ┌──────────────┴──────────────┐              │
      │       │ 1:N                    1:N  │              │
      │       ▼                             ▼              │
      │ ┌─────────────┐              ┌──────────────────┐  │
      │ │  vop_logs   │              │ billing_attempts │  │
      │ ├─────────────┤              ├──────────────────┤  │
      │ │ id          │              │ id               │  │
      │ │ debtor_id   │              │ debtor_id        │  │
      │ │ upload_id   │──────────────│ upload_id        │──┘
      │ │ result      │              │ status           │
      │ └─────────────┘              └──────────────────┘
      │
      │  ┌─────────────┐    ┌─────────────────┐
      └─▶│ blacklists  │    │ bank_references │ (standalone)
         ├─────────────┤    ├─────────────────┤
         │ id          │    │ id              │
         │ iban        │    │ country_iso     │
         │ iban_hash   │    │ bank_code       │
         │ first_name  │    │ bank_name       │
         │ last_name   │    │ sepa_sdd        │
         │ email       │    └─────────────────┘
         │ reason      │
         │ added_by    │
         └─────────────┘
```

### Table Descriptions

#### `uploads`

Stores information about uploaded CSV/XLSX files containing debtor data.

| Column            | Type    | Description                            |
| ----------------- | ------- | -------------------------------------- |
| id                | bigint  | Primary key                            |
| filename          | string  | System-generated filename              |
| original_filename | string  | User's original filename               |
| file_path         | string  | Storage path                           |
| file_size         | integer | File size in bytes                     |
| mime_type         | string  | File MIME type                         |
| status            | enum    | pending, processing, completed, failed |
| total_records     | integer | Total rows in file                     |
| processed_records | integer | Successfully processed rows            |
| failed_records    | integer | Failed rows                            |
| uploaded_by       | bigint  | User FK (nullable)                     |
| column_mapping    | jsonb   | CSV column to field mapping            |
| headers           | jsonb   | CSV column headers for dynamic UI      |
| meta              | jsonb   | Additional metadata                    |

#### `debtors`

Individual debtor records extracted from CSV/XLSX uploads.

| Column             | Type          | Description                            |
| ------------------ | ------------- | -------------------------------------- |
| **Identity**       |               |                                        |
| id                 | bigint        | Primary key                            |
| upload_id          | bigint        | FK to uploads                          |
| external_reference | string        | Client's internal ID                   |
| **IBAN & Bank**    |               |                                        |
| iban               | string        | Bank account number                    |
| iban_hash          | string        | SHA256 for duplicate detection         |
| old_iban           | string        | Previous IBAN (if migrated)            |
| bank_name          | string        | Bank name                              |
| bank_code          | string        | National bank code                     |
| bic                | string        | BIC/SWIFT code                         |
| **Personal Info**  |               |                                        |
| first_name         | string        | Debtor first name                      |
| last_name          | string        | Debtor last name                       |
| national_id        | string        | DNI/NIE/ID number                      |
| birth_date         | date          | Date of birth                          |
| email              | string        | Contact email                          |
| phone              | string        | Primary phone                          |
| phone_2            | string        | Secondary phone                        |
| phone_3            | string        | Tertiary phone                         |
| phone_4            | string        | Additional phone                       |
| primary_phone      | string        | Preferred contact phone                |
| **Address**        |               |                                        |
| address            | string        | Full address (legacy)                  |
| street             | string        | Street name                            |
| street_number      | string        | Building number                        |
| floor              | string        | Floor number                           |
| door               | string        | Door identifier                        |
| apartment          | string        | Apartment number                       |
| postcode           | string        | Postal code                            |
| city               | string        | City name                              |
| province           | string        | Province/state                         |
| country            | string(2)     | ISO country code                       |
| **Financial**      |               |                                        |
| amount             | decimal(12,2) | Debt amount                            |
| currency           | string(3)     | Currency code (EUR)                    |
| sepa_type          | string        | SEPA type (CORE, B2B)                  |
| **Status**         |               |                                        |
| status             | enum          | pending, processing, recovered, failed |
| risk_class         | enum          | low, medium, high                      |
| iban_valid         | boolean       | Pre-validated IBAN flag                |
| name_matched       | boolean       | Pre-validated name match               |
| validation_status  | string        | pending, valid, invalid                |
| validation_errors  | jsonb         | Array of error messages                |
| validated_at       | timestamp     | When last validated                    |
| **Meta**           |               |                                        |
| raw_data           | jsonb         | Original CSV row data                  |
| meta               | jsonb         | Additional flexible data               |
| created_at         | timestamp     | Record created                         |
| updated_at         | timestamp     | Record updated                         |
| deleted_at         | timestamp     | Soft delete                            |

#### `vop_logs`

IBAN verification results from VOP (Verification of Payee) service.

| Column          | Type      | Description                                                 |
| --------------- | --------- | ----------------------------------------------------------- |
| id              | bigint    | Primary key                                                 |
| debtor_id       | bigint    | FK to debtors                                               |
| upload_id       | bigint    | FK to uploads                                               |
| iban_masked     | string    | Masked IBAN for display                                     |
| iban_valid      | boolean   | Checksum validation                                         |
| bank_identified | boolean   | Bank found in registry                                      |
| bank_name       | string    | Bank name (if identified)                                   |
| bic             | string    | Bank BIC code                                               |
| country         | string(2) | Country code                                                |
| vop_score       | tinyint   | Confidence score 0-100                                      |
| result          | enum      | verified, likely_verified, inconclusive, mismatch, rejected |
| meta            | jsonb     | Additional metadata                                         |
| created_at      | timestamp | When verified                                               |

#### `billing_attempts`

SEPA Direct Debit payment attempts.

| Column         | Type      | Description                                              |
| -------------- | --------- | -------------------------------------------------------- |
| id             | bigint    | Primary key                                              |
| debtor_id      | bigint    | FK to debtors                                            |
| upload_id      | bigint    | FK to uploads                                            |
| transaction_id | string    | Internal transaction ID                                  |
| unique_id      | string    | Payment gateway ID                                       |
| amount         | decimal   | Amount charged                                           |
| currency       | string    | Currency code                                            |
| status         | enum      | pending, approved, declined, error, voided, chargebacked |
| attempt_number | integer   | Retry counter                                            |
| error_code     | string    | Bank error code                                          |
| error_message  | string    | Human-readable error                                     |
| can_retry      | boolean   | Eligible for retry                                       |
| processed_at   | timestamp | When processed                                           |

#### `blacklists`

Blocked IBANs, names, and emails that should be rejected during upload processing.

| Column     | Type       | Description                                    |
| ---------- | ---------- | ---------------------------------------------- |
| id         | bigint     | Primary key                                    |
| iban       | string     | Normalized IBAN (unique)                       |
| iban_hash  | string(64) | SHA-256 hash for fast lookup (unique, indexed) |
| first_name | string     | First name (nullable, indexed)                 |
| last_name  | string     | Last name (nullable, indexed)                  |
| email      | string     | Email address (nullable, indexed)              |
| reason     | string     | Reason for blocking (nullable)                 |
| source     | string     | Source: Manual, System, Chargeback (nullable)  |
| added_by   | bigint     | FK to users (nullable)                         |
| created_at | timestamp  | When added                                     |
| updated_at | timestamp  | Last updated                                   |

**Indexes:**
- `iban_hash` (unique) — fast O(1) IBAN lookup
- `(first_name, last_name)` — name-based lookup
- `email` — email-based lookup

#### `bank_references`

Local cache for bank information from iban.com API.

| Column      | Type        | Description                  |
| ----------- | ----------- | ---------------------------- |
| id          | bigint      | Primary key                  |
| country_iso | char(2)     | Country code (DE, ES, FR...) |
| bank_code   | string(20)  | National bank code from IBAN |
| bic         | string(11)  | BIC/SWIFT code               |
| bank_name   | string(255) | Bank name                    |
| branch      | string(255) | Branch name (nullable)       |
| address     | string(255) | Bank address (nullable)      |
| city        | string(128) | City (nullable)              |
| zip         | string(20)  | Postal code (nullable)       |
| sepa_sct    | boolean     | SEPA Credit Transfer support |
| sepa_sdd    | boolean     | SEPA Direct Debit support    |
| sepa_cor1   | boolean     | SEPA COR1 support            |
| sepa_b2b    | boolean     | SEPA B2B support             |
| sepa_scc    | boolean     | SEPA Card Clearing support   |
| created_at  | timestamp   | When cached                  |
| updated_at  | timestamp   | Last updated                 |

**Unique constraint:** `country_iso` + `bank_code`

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

-   Consistent structure
-   Hidden sensitive fields (IBAN)
-   Computed fields (full_name, iban_masked)

### Factory Pattern

Factories generate test data with realistic values and states.

## Security

### Authentication

-   Laravel Sanctum for API tokens
-   Tokens stored in `personal_access_tokens` table
-   Stateless authentication for API

### Data Protection

-   IBAN never exposed in API (masked)
-   IBAN hash for duplicate detection
-   Soft deletes preserve audit trail
-   Blacklist prevents processing of blocked IBANs, names, and emails

### Authorization (Future)

-   Role-based access control
-   Admin vs Client permissions

---

## Services Layer

### FilePreValidationService

Location: `app/Services/FilePreValidationService.php`

Lightweight pre-validation service that runs BEFORE heavy file processing. Prevents wasted compute, queue congestion, and partial system pollution.

**Purpose:**
- Fast-fail for obviously invalid files
- No database queries (pure file validation)
- Reads only headers + first 10 rows (sample)

**Validation Rules:**

| Check | Description | Error Message |
|-------|-------------|---------------|
| Headers exist | File has at least one row | "File is empty or has no headers." |
| IBAN column | Required column present | "Missing required column: IBAN." |
| Amount column | amount/sum/total/price present | "Missing required column: amount." |
| Name column | name/first_name/last_name present | "Missing required column: name." |
| Data rows | At least one data row after headers | "File has headers but no data rows." |
| IBAN format | Sample rows have valid IBAN format | "Row N: Invalid IBAN format." |
| Amount format | Sample rows have numeric amount | "Row N: Invalid amount format." |
| No duplicates | No duplicate IBANs in sample | "Row N: Duplicate IBAN in file." |

**Methods:**

| Method | Return | Description |
|--------|--------|-------------|
| `validate(UploadedFile $file)` | array | Full pre-validation |

**Return Format:**
```php
[
    'valid' => true|false,
    'errors' => ['Error message 1', 'Error message 2'],
    'headers' => ['iban', 'first_name', 'amount', ...],
    'sample_count' => 10
]
```

**Usage:**
```php
$service = app(FilePreValidationService::class);
$result = $service->validate($uploadedFile);

if (!$result['valid']) {
    return response()->json([
        'message' => 'File validation failed.',
        'errors' => $result['errors'],
    ], 422);
}
```

**Performance:**
- O(1) header check — reads 1 row only
- O(10) sample validation — reads 10 rows only
- 0 database queries
- ~50ms for any file size

---

### IbanValidator

Location: `app/Services/IbanValidator.php`

IBAN validation service wrapping `jschaedl/iban-validation` library for production-ready validation.

**Methods:**

| Method                         | Return  | Description                  |
| ------------------------------ | ------- | ---------------------------- |
| `validate(string $iban)`       | array   | Full validation with details |
| `isValid(string $iban)`        | bool    | Quick validation check       |
| `isSepa(string $iban)`         | bool    | Check SEPA zone membership   |
| `getCountryCode(string $iban)` | string  | Extract country code         |
| `getCountryName(string $iban)` | ?string | Get country name             |
| `getBankId(string $iban)`      | ?string | Extract bank identifier      |
| `normalize(string $iban)`      | string  | Uppercase, remove spaces     |
| `format(string $iban)`         | string  | Format for display           |
| `mask(string $iban)`           | string  | Mask for secure display      |
| `hash(string $iban)`           | string  | SHA-256 for deduplication    |

**Usage:**
```php
use App\Services\IbanValidator;

$validator = new IbanValidator();

// Full validation
$result = $validator->validate('DE89 3704 0044 0532 0130 00');
// Returns: ['valid' => true, 'country_code' => 'DE', 'is_sepa' => true, ...]

// Quick check
if ($validator->isValid($iban)) {
    // Process IBAN
}

// For deduplication
$hash = $validator->hash($iban);
```

---

### IbanApiService

Location: `app/Services/IbanApiService.php`

IBAN.com API V4 integration for bank account validation and bank information lookup. Uses **IBAN SUITE (UNLIMITED)** subscription.

**Configuration (`.env`):**
```
IBAN_API_KEY=your_api_key
IBAN_API_URL=https://api.iban.com/clients/api/v4/iban/
IBAN_API_MOCK=false
```

**Features:**

-   Bank name and BIC lookup via iban.com API
-   SEPA support detection (SCT, SDD, B2B, COR1, SCC)
-   Local database cache (BankReference table)
-   Memory cache (24 hours)
-   Retry logic with exponential backoff
-   Mock mode for development/testing

**Methods:**

| Method                                       | Return  | Description                      |
| -------------------------------------------- | ------- | -------------------------------- |
| `verify(string $iban, bool $skipLocalCache)` | array   | Full verification with bank data |
| `getBankName(string $iban)`                  | ?string | Get bank name                    |
| `getBic(string $iban)`                       | ?string | Get BIC/SWIFT code               |
| `isValid(string $iban)`                      | bool    | Check IBAN validity via API      |
| `supportsSepaSdd(string $iban)`              | bool    | Check SEPA Direct Debit support  |

**Cache Flow:**
```
IBAN → extract country_iso + bank_code
         ↓
    Check bank_references table (source: database)
         ↓
    Not found? → Check memory cache (source: memory)
         ↓
    Not found? → Call iban.com API (source: api)
         ↓
    Save to bank_references + memory cache
```

**Usage:**
```php
use App\Services\IbanApiService;

$service = app(IbanApiService::class);

// Full verification
$result = $service->verify('DE89370400440532013000');
// Returns: [
//   'success' => true,
//   'bank_data' => ['bank' => 'Commerzbank', 'bic' => 'COBADEFFXXX', ...],
//   'sepa_data' => ['SDD' => 'YES', 'SCT' => 'YES', ...],
//   'validations' => [...],
//   'cached' => true,
//   'source' => 'database'
// ]

// Quick lookups
$bankName = $service->getBankName($iban); // "Commerzbank"
$bic = $service->getBic($iban);           // "COBADEFFXXX"

// Check SEPA support
if ($service->supportsSepaSdd($iban)) {
    // Can process SEPA Direct Debit
}
```

---

### VopScoringService

Location: `app/Services/VopScoringService.php`

VOP Scoring Engine for calculating debtor verification scores (0-100).

**Score Breakdown:**

| Criterion         | Points | Description                         |
| ----------------- | ------ | ----------------------------------- |
| IBAN Valid        | +20    | Local checksum validation           |
| Bank Identified   | +25    | Bank found via API                  |
| SEPA SDD Support  | +25    | Bank supports Direct Debit          |
| Country Supported | +15    | Country in SEPA zone                |
| Name Match        | +15    | Reserved for future BAV integration |

**Result Thresholds:**

| Score  | Result          | Action          |
| ------ | --------------- | --------------- |
| 80-100 | verified        | Safe to bill    |
| 60-79  | likely_verified | Probably safe   |
| 40-59  | inconclusive    | Review needed   |
| 20-39  | mismatch        | Issues detected |
| 0-19   | rejected        | Do not bill     |

**Methods:**

| Method                                      | Return | Description               |
| ------------------------------------------- | ------ | ------------------------- |
| `score(Debtor $debtor, bool $forceRefresh)` | VopLog | Calculate and save VopLog |
| `calculate(Debtor $debtor)`                 | array  | Dry run with breakdown    |
| `getScoreBreakdown()`                       | array  | Get scoring criteria      |
| `getResultThresholds()`                     | array  | Get result ranges         |

**Usage:**
```php
use App\Services\VopScoringService;

$service = app(VopScoringService::class);

// Score and create VopLog
$vopLog = $service->score($debtor);
// Creates VopLog with: vop_score=85, result='verified', bank_name='Deutsche Bank'

// Dry run (no VopLog created)
$result = $service->calculate($debtor);
// Returns: [
//   'score' => 85,
//   'result' => 'verified',
//   'breakdown' => [
//     'iban_valid' => ['passed' => true, 'points' => 20],
//     'bank_identified' => ['passed' => true, 'points' => 25],
//     'sepa_sdd' => ['passed' => true, 'points' => 25],
//     'country_supported' => ['passed' => true, 'points' => 15],
//   ]
// ]
```

---

### VopVerificationService

Location: `app/Services/VopVerificationService.php`

Main orchestrator for VOP verification process. Manages caching, delegation to scoring service, and VopLog management.

**Dependencies:**

-   `VopScoringService` - calculates scores using IbanApiService
-   `IbanValidator` - IBAN validation and hashing

**Methods:**

| Method                                       | Return  | Description                                  |
| -------------------------------------------- | ------- | -------------------------------------------- |
| `verify(Debtor $debtor, bool $forceRefresh)` | ?VopLog | Verify debtor, returns cached or new VopLog  |
| `canVerify(Debtor $debtor)`                  | bool    | Check if debtor is eligible for verification |
| `hasVopLog(Debtor $debtor)`                  | bool    | Check if debtor already has VopLog           |
| `getUploadStats(int $uploadId)`              | array   | Get verification stats for upload            |

**Usage:**
```php
use App\Services\VopVerificationService;

$service = app(VopVerificationService::class);

// Verify single debtor
$vopLog = $service->verify($debtor);

// Force refresh (ignore cache)
$vopLog = $service->verify($debtor, forceRefresh: true);

// Check eligibility
if ($service->canVerify($debtor)) {
    // Debtor has validation_status=valid and valid IBAN
}

// Get upload stats
$stats = $service->getUploadStats($uploadId);
// Returns: [
//   'total_eligible' => 100,
//   'verified' => 85,
//   'pending' => 15,
//   'by_result' => ['verified' => 70, 'likely_verified' => 10, ...],
//   'avg_score' => 82,
// ]
```

---

### DebtorValidationService

Location: `app/Services/DebtorValidationService.php`

Validates debtor data in Stage B (after upload). Performs comprehensive validation including IBAN, required fields, encoding, and blacklist checks.

**Methods:**

| Method                              | Return | Description                                  |
| ----------------------------------- | ------ | -------------------------------------------- |
| `validateUpload(Upload $upload)`    | array  | Validate all pending debtors in upload       |
| `validateAndUpdate(Debtor $debtor)` | void   | Validate single debtor and update status     |
| `validate(Debtor $debtor)`          | array  | Returns ['valid' => bool, 'errors' => [...]] |

**Validation Rules:**

| Field     | Rule                               | Error Message                          |
| --------- | ---------------------------------- | -------------------------------------- |
| IBAN      | Required, valid checksum           | "IBAN is required" / "IBAN is invalid" |
| Name      | first_name OR last_name            | "Name is required"                     |
| Amount    | Required, > 0, ≤ 50000             | "Amount is required" / "Amount must be positive" |
| Blacklist | IBAN, name, email not in blacklist | "IBAN/Name/Email is blacklisted"       |

---

### BlacklistService

Location: `app/Services/BlacklistService.php`

Manages IBAN, name, and email blacklist for blocking fraudulent or problematic accounts.

**Methods:**

| Method                                              | Return    | Description                      |
| --------------------------------------------------- | --------- | -------------------------------- |
| `isBlacklisted(string $iban)`                       | bool      | Check if IBAN is blocked         |
| `isNameBlacklisted(string $firstName, $lastName)`   | bool      | Check if name is blocked         |
| `isEmailBlacklisted(string $email)`                 | bool      | Check if email is blocked        |
| `checkDebtor(Debtor\|array $debtor)`                | array     | Check all criteria, return matches |
| `isDebtorBlacklisted(Debtor\|array $debtor)`        | bool      | Check if debtor matches any blacklist |
| `add(string $iban, ...)`                            | Blacklist | Add entry with IBAN only         |
| `addDebtor(Debtor $debtor, $reason, $source)`       | Blacklist | Add full debtor data to blacklist |
| `remove(string $iban)`                              | bool      | Remove IBAN from blacklist       |
| `find(string $iban)`                                | ?Blacklist | Find entry by IBAN              |

**Usage:**
```php
use App\Services\BlacklistService;

$service = app(BlacklistService::class);

// Check single criterion
$service->isBlacklisted($iban);           // by IBAN
$service->isNameBlacklisted('John', 'Doe'); // by name
$service->isEmailBlacklisted('john@test.com'); // by email

// Check all criteria at once
$check = $service->checkDebtor($debtor);
// Returns: [
//   'iban' => true,
//   'name' => false,
//   'email' => false,
//   'reasons' => ['IBAN is blacklisted']
// ]

// Add to blacklist with all data
$service->addDebtor($debtor, 'chargeback', 'Auto-blacklisted: AC04');

// Add manually with all fields
$service->add(
    iban: 'DE89...',
    reason: 'manual',
    source: 'Support request',
    firstName: 'John',
    lastName: 'Doe',
    email: 'john@test.com'
);
```

---

### DeduplicationService

Location: `app/Services/DeduplicationService.php`

Service for IBAN/name/email deduplication during file upload. Implements skip logic for Stage 1.

**Skip Reasons:**

| Constant                  | Value                | Description                      |
| ------------------------- | -------------------- | -------------------------------- |
| `SKIP_BLACKLISTED`        | `blacklisted`        | IBAN in blacklist                |
| `SKIP_BLACKLISTED_NAME`   | `blacklisted_name`   | Name in blacklist                |
| `SKIP_BLACKLISTED_EMAIL`  | `blacklisted_email`  | Email in blacklist               |
| `SKIP_CHARGEBACKED`       | `chargebacked`       | IBAN had chargeback              |
| `SKIP_RECOVERED`          | `already_recovered`  | IBAN already recovered           |
| `SKIP_RECENTLY_ATTEMPTED` | `recently_attempted` | IBAN attempted in last 30 days   |

**Methods:**

| Method                                           | Return | Description                               |
| ------------------------------------------------ | ------ | ----------------------------------------- |
| `checkIban(string $iban, ?int $excludeUploadId)` | ?array | Check single IBAN                         |
| `checkDebtor(array $data, ?int $excludeUploadId)`| ?array | Check IBAN + name + email                 |
| `checkBatch(array $ibanHashes, ?int $exclude)`   | array  | Batch check IBANs (legacy)                |
| `checkDebtorBatch(array $debtors, ?int $exclude)`| array  | Batch check IBAN + name + email           |
| `isBlacklisted(string $ibanHash)`                | bool   | Check if IBAN hash is blacklisted         |
| `isChargebacked(string $ibanHash)`               | bool   | Check if IBAN has chargeback              |
| `isRecovered(string $ibanHash, ?int $exclude)`   | bool   | Check if IBAN already recovered           |
| `getRecentAttempt(string $ibanHash, ?int $exclude)` | ?array | Get recent billing attempt info       |

**Usage:**
```php
use App\Services\DeduplicationService;

$service = app(DeduplicationService::class);

// Check single debtor (IBAN + name + email)
$result = $service->checkDebtor([
    'iban' => 'DE89...',
    'first_name' => 'John',
    'last_name' => 'Doe',
    'email' => 'john@test.com'
]);
// Returns: ['reason' => 'blacklisted_name', 'permanent' => true] or null

// Batch check for upload processing (optimized)
$results = $service->checkDebtorBatch($debtorDataArray, $uploadId);
// Returns: [0 => ['reason' => 'blacklisted', ...], 5 => ['reason' => 'chargebacked', ...]]
```

**Performance:**
- Uses batch SQL queries for efficiency
- Single query fetches all blacklisted IBANs, names, emails
- O(1) lookup in PHP arrays
- 1000 debtors = ~4 queries instead of 1000

---

### SpreadsheetParserService

Location: `app/Services/SpreadsheetParserService.php`

Parses CSV and Excel files into standardized row arrays.

**Supported formats:** CSV (comma/semicolon), TXT, XLSX, XLS

**Methods:**

| Method                           | Return | Description         |
| -------------------------------- | ------ | ------------------- |
| `parse(UploadedFile $file)`      | array  | Parse uploaded file |
| `parseCsv(string $path)`         | array  | Parse CSV file      |
| `parseExcel(string $path)`       | array  | Parse XLSX/XLS file |
| `detectType(UploadedFile $file)` | string | Detect file type    |

---

### FileUploadService

Location: `app/Services/FileUploadService.php`

Orchestrates file upload processing: parse, validate, create debtors.

**Features:**

-   Automatic column mapping (English headers)
-   Name splitting (full name → first_name + last_name)
-   Country extraction from IBAN
-   IBAN validation and enrichment
-   Blacklist checking (IBAN + name + email)
-   European/US amount format parsing
-   Date format detection

---

## VOP System Architecture
```
┌─────────────────────────────────────────────────────────────────────────┐
│                           FRONTEND                                       │
│                                                                          │
│   Upload Detail Page                    VOP Logs Page                    │
│   ┌─────────────────┐                  ┌─────────────────┐              │
│   │ [Verify VOP]    │                  │ Score | Result  │              │
│   │                 │                  │  85   | verified│              │
│   │ Stats:          │                  │  72   | likely  │              │
│   │ - Verified: 85  │                  │  45   | inconc. │              │
│   │ - Pending: 15   │                  └─────────────────┘              │
│   └─────────────────┘                                                    │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                          API LAYER                                       │
│                                                                          │
│   POST /uploads/{id}/verify-vop  →  VopController::verify()             │
│   GET  /uploads/{id}/vop-stats   →  VopController::stats()              │
│   GET  /uploads/{id}/vop-logs    →  VopController::logs()               │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                       SERVICE LAYER                                      │
│                                                                          │
│   ┌─────────────────────────────────────────────────────────────────┐   │
│   │              VopVerificationService (Orchestrator)               │   │
│   │                                                                  │   │
│   │   - Manages VopLog caching (by iban_hash)                       │   │
│   │   - Delegates scoring to VopScoringService                      │   │
│   │   - Provides upload stats                                       │   │
│   └─────────────────────────────────────────────────────────────────┘   │
│                                    │                                     │
│                                    ▼                                     │
│   ┌─────────────────────────────────────────────────────────────────┐   │
│   │                   VopScoringService                              │   │
│   │                                                                  │   │
│   │   Score = IBAN(20) + Bank(25) + SEPA(25) + Country(15)          │   │
│   │   Creates VopLog record                                         │   │
│   └─────────────────────────────────────────────────────────────────┘   │
│                                    │                                     │
│                                    ▼                                     │
│   ┌─────────────────────────────────────────────────────────────────┐   │
│   │                    IbanApiService                                │   │
│   │                                                                  │   │
│   │   1. Check BankReference table (local cache)                    │   │
│   │   2. Not found? Call iban.com API V4 (UNLIMITED)                │   │
│   │   3. Save to BankReference for future                           │   │
│   └─────────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                        DATABASE                                          │
│                                                                          │
│   ┌────────────┐  ┌────────────┐  ┌─────────────────┐                  │
│   │  debtors   │  │  vop_logs  │  │ bank_references │                  │
│   │            │  │            │  │                 │                  │
│   │ iban_hash ─┼──│ debtor_id  │  │ country_iso     │                  │
│   │            │  │ vop_score  │  │ bank_code       │                  │
│   │            │  │ result     │  │ bank_name       │                  │
│   │            │  │ bank_name  │  │ sepa_sdd        │                  │
│   └────────────┘  └────────────┘  └─────────────────┘                  │
└─────────────────────────────────────────────────────────────────────────┘
```

### VOP Verification Flow
```
1. VopController receives POST /uploads/{id}/verify-vop
2. Dispatches ProcessVopJob (background)
3. For each eligible debtor:
   ┌─────────────────────────────────────────────────────────────────┐
   │ VopVerificationService.verify(debtor)                           │
   │                                                                 │
   │  a) Check cache by iban_hash                                    │
   │     → Found? Link existing VopLog to debtor (no API call)       │
   │                                                                 │
   │  b) Not cached? Call VopScoringService.score(debtor)            │
   │     → IbanApiService.verify(iban)                               │
   │       → Check BankReference table (local cache)                 │
   │       → Not found? Call iban.com API (UNLIMITED)                │
   │       → Save to BankReference for future                        │
   │     → Calculate score (IBAN + bank + SEPA + country)            │
   │     → Create VopLog with result                                 │
   └─────────────────────────────────────────────────────────────────┘
4. Stats updated, frontend polls for progress
```

### Caching Strategy

| Level         | Storage       | Purpose                       | TTL       |
| ------------- | ------------- | ----------------------------- | --------- |
| VopLog cache  | PostgreSQL    | Same IBAN = reuse VopLog data | Permanent |
| BankReference | PostgreSQL    | Same bank = no API call       | Permanent |
| API response  | Laravel Cache | Reduce repeated API calls     | 24 hours  |

---

## Queue Jobs

### ProcessUploadJob

Location: `app/Jobs/ProcessUploadJob.php`

Background job for processing uploaded CSV/XLSX files with deduplication.

**Configuration:**

| Property   | Value         | Description                  |
| ---------- | ------------- | ---------------------------- |
| `$tries`   | 3             | Max retry attempts           |
| `$timeout` | 300           | Max execution time (seconds) |
| `$backoff` | [30, 60, 120] | Delay between retries        |

**Deduplication:** Uses `DeduplicationService::checkDebtorBatch()` to skip blacklisted IBANs, names, and emails.

### ProcessUploadChunkJob

Location: `app/Jobs/ProcessUploadChunkJob.php`

Processes chunk of rows for large file uploads with deduplication.

**Configuration:**

| Property   | Value | Description                  |
| ---------- | ----- | ---------------------------- |
| `$tries`   | 3     | Max retry attempts           |
| `$timeout` | 120   | Max execution time (seconds) |

**Deduplication:** Uses `DeduplicationService::checkDebtorBatch()` to skip blacklisted IBANs, names, and emails.

### ProcessVopJob

Location: `app/Jobs/ProcessVopJob.php`

Main job for batch VOP verification of upload. Filters eligible debtors and dispatches chunk jobs.

**Configuration:**

| Property   | Value | Description                  |
| ---------- | ----- | ---------------------------- |
| `$tries`   | 3     | Max retry attempts           |
| `$timeout` | 300   | Max execution time (seconds) |
| CHUNK_SIZE | 50    | Debtors per chunk            |

### ProcessVopChunkJob

Location: `app/Jobs/ProcessVopChunkJob.php`

Processes chunk of debtors for VOP verification. Called by ProcessVopJob.

**Configuration:**

| Property     | Value | Description                  |
| ------------ | ----- | ---------------------------- |
| `$timeout`   | 180   | Max execution time (seconds) |
| API_DELAY_MS | 500   | Delay between API calls      |

### Queue Worker Commands
```bash
php artisan queue:work                      # Default queue
php artisan queue:work --queue=vop,default  # VOP queue priority
php artisan queue:work --queue=default,vop  # Both queues
```

---

## Five-Stage Upload Flow
```
┌─────────────────────────────────────────────────────────────────┐
│                  STAGE 0: PRE-VALIDATION                         │
│                                                                  │
│  FilePreValidationService runs BEFORE processing:               │
│  - Check headers (1 row only)                                   │
│  - Validate required columns: IBAN, amount, name                │
│  - Sample validation (first 10 rows)                            │
│                                                                  │
│  ❌ FAIL → Return 422 (no processing starts)                    │
│  ✅ PASS → Continue to Stage A                                  │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                     STAGE A: UPLOAD                              │
│                                                                  │
│  1. Parse CSV/XLSX file                                         │
│  2. Check deduplication (IBAN + name + email)                   │
│  3. Skip blacklisted, chargebacked, recovered, recently attempted│
│  4. Save valid rows with validation_status = 'pending'          │
│  5. Store raw_data (original CSV row)                           │
│  6. Store headers (column names)                                │
│                                                                  │
│  Skipped rows saved to meta.skipped_rows with reason            │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    STAGE B: VALIDATION                           │
│                                                                  │
│  1. User clicks "Validate" button                               │
│  2. DebtorValidationService runs all rules                      │
│  3. Check blacklist again (IBAN + name + email)                 │
│  4. Update validation_status = 'valid' or 'invalid'             │
│  5. Store validation_errors (if any)                            │
│  6. Set validated_at timestamp                                  │
│                                                                  │
│  Result: User sees validation results, can fix and re-validate  │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    STAGE C: VOP VERIFICATION                     │
│                                                                  │
│  1. User clicks "Verify VOP" button                             │
│  2. ProcessVopJob dispatched                                    │
│  3. VopVerificationService scores each debtor                   │
│  4. VopLog created with score and result                        │
│                                                                  │
│  Result: User sees VOP scores, bank names, SEPA support         │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    STAGE D: SYNC (Future)                        │
│                                                                  │
│  Only debtors with:                                             │
│    - validation_status = 'valid'                                │
│    - vop_result = 'verified' or 'likely_verified'               │
│    - status = 'pending'                                         │
│                                                                  │
│  Are sent to payment gateway                                    │
└─────────────────────────────────────────────────────────────────┘
```

### Stage A Deduplication Flow
```
CSV Row
   │
   ▼
┌─────────────────────────────────────────────────────────────────┐
│               DeduplicationService.checkDebtorBatch()            │
│                                                                  │
│  1. Check IBAN against blacklist      → SKIP_BLACKLISTED        │
│  2. Check IBAN against chargebacks    → SKIP_CHARGEBACKED       │
│  3. Check IBAN against recovered      → SKIP_RECOVERED          │
│  4. Check IBAN against recent (30d)   → SKIP_RECENTLY_ATTEMPTED │
│  5. Check name against blacklist      → SKIP_BLACKLISTED_NAME   │
│  6. Check email against blacklist     → SKIP_BLACKLISTED_EMAIL  │
└─────────────────────────────────────────────────────────────────┘
   │
   ▼
Pass? → Create Debtor
Skip? → Add to meta.skipped_rows with reason
```

---

## Debtor Constants
```php
class Debtor extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_RECOVERED = 'recovered';
    const STATUS_FAILED = 'failed';

    const VALIDATION_PENDING = 'pending';
    const VALIDATION_VALID = 'valid';
    const VALIDATION_INVALID = 'invalid';

    const RISK_CLASSES = ['low', 'medium', 'high'];
}
```

## VopLog Constants
```php
class VopLog extends Model
{
    const RESULT_VERIFIED = 'verified';
    const RESULT_LIKELY_VERIFIED = 'likely_verified';
    const RESULT_INCONCLUSIVE = 'inconclusive';
    const RESULT_MISMATCH = 'mismatch';
    const RESULT_REJECTED = 'rejected';
}
```

## DeduplicationService Constants
```php
class DeduplicationService
{
    const SKIP_BLACKLISTED = 'blacklisted';
    const SKIP_BLACKLISTED_NAME = 'blacklisted_name';
    const SKIP_BLACKLISTED_EMAIL = 'blacklisted_email';
    const SKIP_CHARGEBACKED = 'chargebacked';
    const SKIP_RECOVERED = 'already_recovered';
    const SKIP_RECENTLY_ATTEMPTED = 'recently_attempted';
    
    const COOLDOWN_DAYS = 30;
}
```

---

## API Endpoints (VOP)

| Method | Endpoint                 | Controller                 | Description                |
| ------ | ------------------------ | -------------------------- | -------------------------- |
| GET    | /uploads/{id}/vop-stats  | VopController@stats        | Get VOP verification stats |
| POST   | /uploads/{id}/verify-vop | VopController@verify       | Start VOP verification     |
| GET    | /uploads/{id}/vop-logs   | VopController@logs         | Get VOP logs for upload    |
| POST   | /vop/verify-single       | VopController@verifySingle | Verify single IBAN         |
| GET    | /vop-logs                | VopLogController@index     | List all VOP logs          |
| GET    | /vop-logs/{id}           | VopLogController@show      | Get single VOP log         |
