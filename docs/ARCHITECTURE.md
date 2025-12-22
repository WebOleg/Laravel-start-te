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
│  ┌────────────┐                                                         │
│  │ blacklists │                                                         │
│  └────────────┘                                                         │
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
│   │   └── Blacklist.php
│   ├── Services/                      # Business logic
│   │   ├── IbanValidator.php
│   │   ├── SpreadsheetParserService.php
│   │   ├── FileUploadService.php
│   │   └── BlacklistService.php
│   ├── Jobs/                          # Queue jobs
│   │   ├── ProcessUploadJob.php
│   │   └── ProcessUploadChunkJob.php
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
      │  ┌─────────────┐
      └─▶│ blacklists  │
         ├─────────────┤
         │ id          │
         │ iban        │
         │ iban_hash   │
         │ reason      │
         │ added_by    │
         └─────────────┘
```

### Table Descriptions

#### `uploads`
Stores information about uploaded CSV/XLSX files containing debtor data.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| filename | string | System-generated filename |
| original_filename | string | User's original filename |
| file_path | string | Storage path |
| file_size | integer | File size in bytes |
| mime_type | string | File MIME type |
| status | enum | pending, processing, completed, failed |
| total_records | integer | Total rows in file |
| processed_records | integer | Successfully processed rows |
| failed_records | integer | Failed rows |
| uploaded_by | bigint | User FK (nullable) |
| column_mapping | jsonb | CSV column to field mapping |
| meta | jsonb | Additional metadata |

#### `debtors`
Individual debtor records extracted from CSV/XLSX uploads.

| Column | Type | Description |
|--------|------|-------------|
| **Identity** | | |
| id | bigint | Primary key |
| upload_id | bigint | FK to uploads |
| external_reference | string | Client's internal ID |
| **IBAN & Bank** | | |
| iban | string | Bank account number |
| iban_hash | string | SHA256 for duplicate detection |
| old_iban | string | Previous IBAN (if migrated) |
| bank_name | string | Bank name |
| bank_code | string | National bank code |
| bic | string | BIC/SWIFT code |
| **Personal Info** | | |
| first_name | string | Debtor first name |
| last_name | string | Debtor last name |
| national_id | string | DNI/NIE/ID number |
| birth_date | date | Date of birth |
| email | string | Contact email |
| phone | string | Primary phone |
| phone_2 | string | Secondary phone |
| phone_3 | string | Tertiary phone |
| phone_4 | string | Additional phone |
| primary_phone | string | Preferred contact phone |
| **Address** | | |
| address | string | Full address (legacy) |
| street | string | Street name |
| street_number | string | Building number |
| floor | string | Floor number |
| door | string | Door identifier |
| apartment | string | Apartment number |
| postcode | string | Postal code |
| city | string | City name |
| province | string | Province/state |
| country | string(2) | ISO country code |
| **Financial** | | |
| amount | decimal(12,2) | Debt amount |
| currency | string(3) | Currency code (EUR) |
| sepa_type | string | SEPA type (CORE, B2B) |
| **Status** | | |
| status | enum | pending, processing, recovered, failed |
| risk_class | enum | low, medium, high |
| iban_valid | boolean | Pre-validated IBAN flag |
| name_matched | boolean | Pre-validated name match |
| **Meta** | | |
| meta | jsonb | Additional flexible data |
| created_at | timestamp | Record created |
| updated_at | timestamp | Record updated |
| deleted_at | timestamp | Soft delete |

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
| can_retry | boolean | Eligible for retry |
| processed_at | timestamp | When processed |

#### `blacklists`
Blocked IBANs that should be rejected during upload processing.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| iban | string | Normalized IBAN (unique) |
| iban_hash | string(64) | SHA-256 hash for fast lookup (unique, indexed) |
| reason | string | Reason for blocking (nullable) |
| source | string | Source: Manual, System, Chargeback (nullable) |
| added_by | bigint | FK to users (nullable) |
| created_at | timestamp | When added |
| updated_at | timestamp | Last updated |

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
- Computed fields (full_name, iban_masked)

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
- Blacklist prevents processing of blocked IBANs

### Authorization (Future)
- Role-based access control
- Admin vs Client permissions

## Services Layer

### IbanValidator

Location: `app/Services/IbanValidator.php`

IBAN validation service wrapping `jschaedl/iban-validation` library for production-ready validation.

**Methods:**

| Method | Return | Description |
|--------|--------|-------------|
| `validate(string $iban)` | array | Full validation with details |
| `isValid(string $iban)` | bool | Quick validation check |
| `isSepa(string $iban)` | bool | Check SEPA zone membership |
| `getCountryCode(string $iban)` | string | Extract country code |
| `getCountryName(string $iban)` | ?string | Get country name |
| `getBankId(string $iban)` | ?string | Extract bank identifier |
| `normalize(string $iban)` | string | Uppercase, remove spaces |
| `format(string $iban)` | string | Format for display |
| `mask(string $iban)` | string | Mask for secure display |
| `hash(string $iban)` | string | SHA-256 for deduplication |

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

### SpreadsheetParserService

Location: `app/Services/SpreadsheetParserService.php`

Parses CSV and Excel files into standardized row arrays.

**Supported formats:** CSV (comma/semicolon), XLSX, XLS

**Methods:**

| Method | Return | Description |
|--------|--------|-------------|
| `parse(UploadedFile $file)` | array | Parse uploaded file |
| `parseCsv(string $path)` | array | Parse CSV file |
| `parseExcel(string $path)` | array | Parse XLSX/XLS file |
| `detectType(UploadedFile $file)` | string | Detect file type |

### FileUploadService

Location: `app/Services/FileUploadService.php`

Orchestrates file upload processing: parse, validate, create debtors.

**Features:**
- Automatic column mapping (English headers)
- Name splitting (full name → first_name + last_name)
- Country extraction from IBAN
- IBAN validation and enrichment
- Blacklist checking
- European/US amount format parsing
- Date format detection

**Usage:**
```php
$result = $uploadService->process($file, $userId);
// Returns: ['upload' => Upload, 'created' => 10, 'failed' => 2, 'errors' => [...]]
```

### BlacklistService

Location: `app/Services/BlacklistService.php`

Manages IBAN blacklist for blocking fraudulent or problematic accounts.

**Methods:**

| Method | Return | Description |
|--------|--------|-------------|
| `add(string $iban, ?string $reason, ?string $source, ?int $userId)` | Blacklist | Add IBAN to blacklist |
| `remove(string $iban)` | bool | Remove IBAN from blacklist |
| `isBlacklisted(string $iban)` | bool | Check if IBAN is blocked |

**Usage:**
```php
use App\Services\BlacklistService;

$blacklistService = app(BlacklistService::class);

// Add to blacklist
$blacklistService->add('DE89370400440532013000', 'Fraud', 'Manual', $userId);

// Check during upload
if ($blacklistService->isBlacklisted($iban)) {
    throw new \InvalidArgumentException('IBAN is blacklisted');
}

// Remove from blacklist
$blacklistService->remove('DE89370400440532013000');
```

## Traits

### ParsesDebtorData

Location: `app/Traits/ParsesDebtorData.php`

Shared parsing logic used by FileUploadService and ProcessUploadJob.

**Methods:**

| Method | Description |
|--------|-------------|
| `splitFullName(array &$data)` | Split "John Doe" or "Doe, John" into first_name + last_name |
| `enrichCountryFromIban(array &$data)` | Extract country code from IBAN if not provided |
| `castValue(string $field, mixed $value)` | Type cast value based on field name |
| `parseAmount(string $value)` | Parse EU (1.234,56) or US (1,234.56) format |
| `parseDate(string $value)` | Parse various date formats |
| `normalizeName(string $name)` | Convert "JOHN" to "John" |

**Name Splitting Examples:**

| Input | First Name | Last Name |
|-------|------------|-----------|
| JOHN DOE | John | Doe |
| Doe, John | John | Doe |
| Maria Del Pilar Rodriguez | Maria | Del Pilar Rodriguez |
| Madonna | Madonna | Madonna |

## Queue Jobs

### ProcessUploadJob

Location: `app/Jobs/ProcessUploadJob.php`

Background job for processing uploaded CSV/XLSX files.

**Configuration:**

| Property | Value | Description |
|----------|-------|-------------|
| `$tries` | 3 | Max retry attempts |
| `$timeout` | 300 | Max execution time (seconds) |
| `$backoff` | [30, 60, 120] | Delay between retries |

**Flow:**
```
1. Job dispatched with Upload model
2. Update status → processing
3. Parse file (CSV/XLSX)
4. For each row:
   - Map columns to fields
   - Split full name if needed
   - Extract country from IBAN
   - Validate IBAN
   - Check blacklist
   - Create Debtor record
5. Update status → completed/failed
```

**Usage:**
```php
// Async (queued)
ProcessUploadJob::dispatch($upload, $columnMapping);

// Sync (immediate)
ProcessUploadJob::dispatchSync($upload, $columnMapping);
```

**Error Handling:**
- Invalid rows logged to `upload.meta.errors`
- Blacklisted IBANs rejected with "IBAN is blacklisted" error
- Job failure updates upload status to `failed`
- Exception message stored in `upload.meta.error`

## Queue System (Redis + Horizon)

### Architecture
```
┌─────────────────────────────────────────────────────────────┐
│                     LARAVEL HORIZON                          │
│                   Dashboard: /horizon                        │
└─────────────────────────────────────────────────────────────┘
                            │
         ┌──────────────────┼──────────────────┐
         ▼                  ▼                  ▼
   ┌──────────┐      ┌──────────┐      ┌──────────┐
   │ critical │      │   high   │      │ default  │
   │          │      │          │      │          │
   │ Payments │      │   VOP    │      │ Uploads  │
   │ Webhooks │      │  Alerts  │      │ Reports  │
   │          │      │          │      │          │
   │ 5 workers│      │ 3 workers│      │ 3 workers│
   └──────────┘      └──────────┘      └──────────┘
```

### Queue Priorities

| Queue | Purpose | Workers | Timeout | Retries |
|-------|---------|---------|---------|---------|
| `critical` | Payments, webhooks | 5-10 | 60s | 5 |
| `high` | VOP, alerts | 3-5 | 120s | 3 |
| `default` | File processing | 3-5 | 300s | 3 |
| `low` | Reports, cleanup | 2-3 | 600s | 1 |

### Job Batching

Large files (>100 rows) are split into chunks of 500 rows:
```php
// Small file: direct processing
ProcessUploadJob → processes all rows

// Large file: batch processing
ProcessUploadJob → dispatches batch:
  ├── ProcessUploadChunkJob (rows 1-500)
  ├── ProcessUploadChunkJob (rows 501-1000)
  └── ProcessUploadChunkJob (rows 1001-1500)
```

### Commands
```bash
# Start Horizon (development)
php artisan horizon

# Check status
php artisan horizon:status

# Pause/Continue
php artisan horizon:pause
php artisan horizon:continue

# Terminate gracefully
php artisan horizon:terminate
```

### Production (Supervisor)
```ini
[program:horizon]
process_name=%(program_name)s
command=php /var/www/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/storage/logs/horizon.log
stopwaitsecs=3600
```

### DebtorValidationService

Location: `app/Services/DebtorValidationService.php`

Validates debtor data in Stage B (after upload). Performs comprehensive validation including IBAN, required fields, encoding, and blacklist checks.

**Methods:**

| Method | Return | Description |
|--------|--------|-------------|
| `validateUpload(Upload $upload)` | array | Validate all pending debtors in upload |
| `validateAndUpdate(Debtor $debtor)` | void | Validate single debtor and update status |
| `validate(Debtor $debtor)` | array | Returns ['valid' => bool, 'errors' => [...]] |

**Validation Rules:**

| Field | Rule | Error Message |
|-------|------|---------------|
| IBAN | Required, valid checksum | "IBAN is required" / "IBAN is invalid" |
| Name | first_name OR last_name | "Name is required" |
| Amount | Required, > 0, ≤ 50000 | "Amount is required" / "Amount must be positive" |
| City | Required, 2-100 chars, valid encoding | "City is required" / "City contains invalid characters" |
| Postcode | Required, 3-20 chars | "Postal code is required" |
| Address | Required, 5-200 chars, valid encoding | "Address is required" |
| Blacklist | IBAN not in blacklist | "IBAN is blacklisted" |

**Usage:**
```php
use App\Services\DebtorValidationService;

$service = app(DebtorValidationService::class);

// Validate entire upload
$stats = $service->validateUpload($upload);
// Returns: ['total' => 100, 'valid' => 85, 'invalid' => 15]

// Validate single debtor
$service->validateAndUpdate($debtor);
// Updates: validation_status, validation_errors, validated_at
```

## Two-Stage Upload Flow
```
┌─────────────────────────────────────────────────────────────────┐
│                     STAGE A: UPLOAD                              │
│                                                                  │
│  1. Parse CSV/XLSX file                                         │
│  2. Save ALL rows (even invalid)                                │
│  3. Set validation_status = 'pending'                           │
│  4. Store raw_data (original CSV row)                           │
│  5. Store headers (column names)                                │
│                                                                  │
│  Result: User sees ALL data, can edit before validation         │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    STAGE B: VALIDATION                           │
│                                                                  │
│  1. User clicks "Validate" button                               │
│  2. DebtorValidationService runs all rules                      │
│  3. Update validation_status = 'valid' or 'invalid'             │
│  4. Store validation_errors (if any)                            │
│  5. Set validated_at timestamp                                  │
│                                                                  │
│  Result: User sees validation results, can fix and re-validate  │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    STAGE C: SYNC (Future)                        │
│                                                                  │
│  Only debtors with:                                             │
│    - validation_status = 'valid'                                │
│    - status = 'pending'                                         │
│                                                                  │
│  Are sent to payment gateway                                    │
└─────────────────────────────────────────────────────────────────┘
```

### Additional Debtor Fields (Validation)

| Column | Type | Description |
|--------|------|-------------|
| validation_status | string | pending, valid, invalid |
| validation_errors | json | Array of error messages |
| validated_at | timestamp | When last validated |
| raw_data | json | Original CSV row data |

### Additional Upload Fields

| Column | Type | Description |
|--------|------|-------------|
| headers | json | CSV column headers for dynamic UI |

### Debtor Constants
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

### Validation Scopes
```php
// Get valid debtors
$upload->debtors()->valid();

// Get invalid debtors
$upload->debtors()->invalid();

// Get pending validation
$upload->debtors()->validationPending();

// Get ready for sync (valid + pending status)
$upload->debtors()->readyForSync();
```

### IbanApiService

Location: `app/Services/IbanApiService.php`

IBAN.com API V4 integration for bank account validation and bank information lookup.

**Configuration (`.env`):**
```
IBAN_API_KEY=your_api_key
IBAN_API_URL=https://api.iban.com/clients/api/v4/iban/
IBAN_API_MOCK=false
```

**Features:**
- Bank name and BIC lookup via iban.com API
- SEPA support detection (SCT, SDD, B2B, COR1)
- Response caching (24 hours)
- Retry logic with exponential backoff
- Mock mode for development/testing

**Methods:**

| Method | Return | Description |
|--------|--------|-------------|
| `verify(string $iban)` | array | Full verification with bank data |
| `getBankName(string $iban)` | ?string | Get bank name |
| `getBic(string $iban)` | ?string | Get BIC/SWIFT code |
| `isValid(string $iban)` | bool | Check IBAN validity via API |
| `supportsSepaSdd(string $iban)` | bool | Check SEPA Direct Debit support |

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
//   'cached' => false
// ]

// Quick lookups
$bankName = $service->getBankName($iban); // "Commerzbank"
$bic = $service->getBic($iban);           // "COBADEFFXXX"

// Check SEPA support
if ($service->supportsSepaSdd($iban)) {
    // Can process SEPA Direct Debit
}
```

**API Response Structure:**
```php
[
    'success' => true,
    'bank_data' => [
        'bic' => 'COBADEFFXXX',
        'bank' => 'Commerzbank',
        'address' => 'Venloer Str. 288',
        'city' => 'Köln',
        'zip' => '50447',
        'country' => 'Germany',
        'country_iso' => 'DE',
    ],
    'sepa_data' => [
        'SCT' => 'YES',  // SEPA Credit Transfer
        'SDD' => 'YES',  // SEPA Direct Debit
        'COR1' => 'YES', // SEPA COR1
        'B2B' => 'YES',  // SEPA Business to Business
        'SCC' => 'YES',  // SEPA Card Clearing
    ],
    'validations' => [
        'iban' => ['code' => '001', 'message' => 'IBAN Check digit is correct'],
        // ...
    ],
    'cached' => false,
]
```
