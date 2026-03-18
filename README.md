# Tether - Debt Recovery Platform

A SaaS platform for automated debt recovery through SEPA Direct Debit payments with emerchantpay integration.

## Table of Contents

-   [Overview](#overview)
-   [Features](#features)
-   [Tech Stack](#tech-stack)
-   [Requirements](#requirements)
-   [Installation](#installation)
-   [Infrastructure](#infrastructure)
-   [Development](#development)
-   [Testing](#testing)
-   [API Documentation](#api-documentation)
-   [Project Structure](#project-structure)

## Overview

Tether enables merchants to recover outstanding debts through automated SEPA Direct Debit collection. The platform provides end-to-end debt recovery workflow from CSV upload to payment processing and reconciliation.

## Features

### Core Functionality

| Feature                   | Description                                                                  |
| ------------------------- | ---------------------------------------------------------------------------- |
| **CSV Upload Processing** | Bulk debtor import with chunked processing for large files (100k+ rows)      |
| **Two-Stage Validation**  | Stage A: Accept all rows → Stage B: Validate fields → Stage C: Sync eligible |
| **VOP Verification**      | IBAN validation, bank identification, and name matching via IBAN.com         |
| **SEPA Direct Debit**     | Payment processing via emerchantpay Genesis API                              |
| **Webhook Handler**       | Automatic status updates from payment gateway                                |
| **Reconciliation**        | Backup mechanism for missed webhooks - query EMP for actual status           |
| **Blacklist System**      | Auto-blacklist on chargebacks, manual blacklist management                   |
| **Deduplication**         | 30-day cooldown for same IBAN, prevent duplicate processing                  |

### Payment Flow
```
CSV Upload → Validation → VOP Verify → Billing Sync → EMP Processing
                                              ↓
                            Webhook ← Status Update (approved/declined/error)
                                              ↓
                            Reconciliation (if webhook missed)
```

### Chargeback Prevention

-   Automatic IBAN blacklisting on chargeback
-   Configurable blacklist codes
-   Chargeback rate monitoring per country
-   Bank-level chargeback statistics

## Tech Stack

| Component        | Technology               |
| ---------------- | ------------------------ |
| Backend          | Laravel 11 (PHP 8.3+)    |
| Database         | PostgreSQL 15            |
| Queue            | Redis + Laravel Queue    |
| Object Storage   | MinIO (S3-compatible)    |
| Authentication   | Laravel Sanctum          |
| Payment Gateway  | emerchantpay Genesis API |
| VOP Provider     | IBAN.com API             |
| Containerization | Docker & Docker Compose  |
| Testing          | PHPUnit                  |

## Requirements

-   Docker & Docker Compose
-   Git
-   Make (optional)

## Installation

1. **Clone the repository**
```bash
git clone git@github.com:your-org/tether-laravel.git
cd tether-laravel
```

2. **Copy environment file**
```bash
cp .env.example .env
```

3. **Configure environment**
```env
# Database
DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=tether
DB_USERNAME=tether
DB_PASSWORD=secret

# emerchantpay
EMP_GENESIS_ENDPOINT=staging.gate.emerchantpay.net
EMP_GENESIS_USERNAME=your_username
EMP_GENESIS_PASSWORD=your_password
EMP_GENESIS_TERMINAL_TOKEN=your_terminal_token

# IBAN.com VOP
IBAN_API_KEY=your_api_key
IBAN_API_URL=https://api.iban.com/clients/verify/v3/
IBAN_API_MOCK=false

# MinIO (S3-compatible storage)
MINIO_ENDPOINT=http://minio:9000
MINIO_ACCESS_KEY=minioadmin
MINIO_SECRET_KEY=minioadmin
MINIO_BUCKET=tether
```

4. **Start containers**
```bash
./start.sh
# or
docker compose up -d
```

5. **Install dependencies & migrate**
```bash
docker compose exec app composer install
docker compose exec app php artisan migrate --seed
```

## Infrastructure

Production infrastructure is managed separately in the `infrastructure/` directory.

### Components

| Service    | Directory                | Port  | Description                    |
| ---------- | ------------------------ | ----- | ------------------------------ |
| API Node   | `infrastructure/api-node`| 8000  | Laravel application servers    |
| Worker     | `infrastructure/worker-node`| -   | Queue workers for async jobs   |
| PostgreSQL | `infrastructure/postgres`| 5432  | Primary database               |
| Redis      | `infrastructure/redis`   | 6379  | Queue & cache                  |
| MinIO      | `infrastructure/minio`   | 9000/9001 | S3-compatible object storage |
| Nginx      | `infrastructure/nginx`   | 80/443| Load balancer & reverse proxy  |
| Network    | `infrastructure/network` | -     | Docker network configuration   |

### MinIO Setup

MinIO provides S3-compatible object storage for file uploads.
```bash
cd infrastructure/minio
docker compose up -d
```

**Ports:**
- `9000` - S3 API
- `9001` - Web Console

**Environment variables:**
```env
MINIO_ROOT_USER=minioadmin
MINIO_ROOT_PASSWORD=minioadmin
```

### Starting Infrastructure

Each component can be started independently:
```bash
# Start all infrastructure
cd infrastructure
for dir in network postgres redis minio nginx api-node worker-node; do
  cd $dir && docker compose up -d && cd ..
done

# Or individual components
cd infrastructure/minio && docker compose up -d
```

## Development

### Make Commands
```bash
make up              # Start containers
make down            # Stop containers
make test            # Run tests
make fresh           # Fresh migrate + seed
make bash            # Enter app container
make tinker          # Laravel REPL
make logs            # View logs
```

### Without Make
```bash
docker compose up -d
docker compose exec app php artisan migrate
docker compose exec app php artisan test
```

## Testing
```bash
# All tests
make test

# Specific test
docker compose exec app php artisan test --filter=ReconciliationControllerTest
```

### Test Coverage

**Feature Tests**

| Test Suite                      | Tests | Status |
| --------------------------------| ----- | ------ |
| EmpRefreshControllerTest        | 32    | ✅     |
| BicAnalyticsControllerTest      | 21    | ✅     |
| ChargebackControllerTest        | 21    | ✅     |
| DashboardTest                   | 21    | ✅     |
| BillingControllerTest           | 21    | ✅     |
| DebtorControllerTest            | 28    | ✅     |
| UploadValidationTest            | 18    | ✅     |
| BillingAttemptControllerTest    | 18    | ✅     |
| VopLogControllerTest            | 14    | ✅     |
| UploadStatusTest                | 14    | ✅     |
| UploadValidationStatsTest       | 12    | ✅     |
| BavBatchControllerTest          | 11    | ✅     |
| VopControllerTest               | 10    | ✅     |
| UploadStoreTest                 | 10    | ✅     |
| StatsControllerTest             | 9     | ✅     |
| UploadChargebackFilterTest      | 9     | ✅     |
| ReconciliationControllerTest    | 17    | ✅     |
| UploadEdgeCasesTest             | 7     | ✅     |
| UploadControllerTest            | 7     | ✅     |
| PricePointStatsTest             | 7     | ✅     |
| UploadDeleteTest                | 8     | ✅     |
| DescriptorControllerTest        | 5     | ✅     |
| BlacklistUploadTest             | 4     | ✅     |
| ChargebackStatsTest             | 21    | ✅     |
| BavVerificationTest             | 14    | ✅     |
| CleanUsersExportTest            | 14    | ✅     |
| BavReportGenerationTest         | 13    | ✅     |
| EmpWebhookTest                  | 20    | ✅     |
| IbanBavServiceTest              | 10    | ✅     |
| BavLoggingChannelTest           | 9     | ✅     |
| ProcessVopJobBavIntegrationTest | 9     | ✅     |
| VopBavIntegrationTest           | 8     | ✅     |
| VopVerificationServiceTest      | 5     | ✅     |
| S3FileUploadTest                | 5     | ✅     |
| BicBlacklistImportCommandTest   | 20    | ✅     |
| BicBlacklistAutoCommandTest     | 20    | ✅     |
| BicBlacklistListCommandTest     | 15    | ✅     |
| DispatchRecurringBillingTest    | 5     | ✅     |
| EmpRefreshControllerTest        | 32    | ✅     |
| UserTest                        | 16    | ✅     |
| LogoutTest                      | 12    | ✅     |
| LoginTwoFactorTest              | 5     | ✅     |

**Unit Tests - Services (21 suites)**

| Test Suite                     | Tests | Status |
| ------------------------------ | ----- | ------ |
| BlacklistServiceTest           | 27    | ✅     |
| IbanValidatorTest              | 19    | ✅     |
| DebtorValidationServiceTest    | 17    | ✅     |
| IbanApiServiceTest             | 13    | ✅     |
| VopScoringServiceTest          | 13    | ✅     |
| EmpBillingServiceTest          | 12    | ✅     |
| DeduplicationServiceTest       | 12    | ✅     |
| SpreadsheetParserServiceTest   | 10    | ✅     |
| DescriptorServiceTest          | 8     | ✅     |
| FileUploadServiceTest          | 8     | ✅     |
| ChargebackServiceTest          | 8     | ✅     |
| BackupCodesServiceTest         | 8     | ✅     |
| DebtorImportServiceTest        | 7     | ✅     |
| EmpClientTest                  | 7     | ✅     |
| OtpServiceTest                 | 7     | ✅     |
| EmpChargebackSyncServiceTest   | 6     | ✅     |
| EmpAccountTest                 | 6     | ✅     |
| DebtorTest                     | 9     | ✅     |
| ProcessUploadJobTest           | 4     | ✅     |
| BicBlacklistTest               | 3     | ✅     |
| ExampleTest                    | 1     | ✅     |

**Test Summary**
- **Feature Tests**: 545+ across 40 test classes
- **Unit Tests**: 176 across 21 test classes
- **Total Test Coverage: 720+ tests** ✅

## API Documentation

### Interactive Swagger UI

The project includes full interactive API documentation powered by Swagger (OpenAPI 3.0). Every endpoint is documented with request/response schemas, parameters, and examples.

**Accessing the docs:**

| Environment | URL |
|-------------|-----|
| Local | `http://localhost:8000/api/documentation?password=PASSWORD` |
| Develop | `http://199.217.98.92/api/documentation?password=PASSWORD` |
| Staging | `https://137.184.105.172/api/documentation?password=PASSWORD` |
| Production | `https://testingiscool.online/api/documentation?password=PASSWORD` |

The docs are password-protected. The password is set via the `SWAGGER_PASSWORD` environment variable. After the first visit with the correct password, a cookie persists authentication for 24 hours.

**Using the docs:**

1. Open the Swagger UI URL with `?password=...`
2. Click the **Authorize** button (top right)
3. Enter your Bearer token from the `/api/login` endpoint
4. All endpoints are now testable via "Try it out"

### Regenerating the Docs

Documentation is auto-generated from PHP annotations in the controller files. After modifying annotations, regenerate the JSON spec:
```bash
# Inside the Docker container
php artisan l5-swagger:generate

# Or from the host
docker exec tether_app php artisan l5-swagger:generate
```

In CI/CD, docs are regenerated automatically on every deploy (see `.github/workflows/`).

**Local development:** Set `L5_SWAGGER_GENERATE_ALWAYS=true` in `.env` to regenerate on every page load. Set to `false` in all deployed environments.

### Adding Documentation to New Endpoints

1. Add `@OA\Get`, `@OA\Post`, etc. annotations above the controller method
2. Use an existing tag from `app/Swagger/SwaggerInfo.php` or add a new `@OA\Tag`
3. For reusable response shapes, add `@OA\Schema` to `SwaggerInfo.php`
4. Run `php artisan l5-swagger:generate` to verify
5. Check the Swagger UI to confirm rendering

### Configuration

| Setting | `.env` Variable | Default | Description |
|---------|----------------|---------|-------------|
| Auto-regenerate | `L5_SWAGGER_GENERATE_ALWAYS` | `false` | Regenerate docs on every request (dev only) |
| Doc expansion | `L5_SWAGGER_UI_DOC_EXPANSION` | `none` | `none`, `list`, or `full` |
| Password | `SWAGGER_PASSWORD` | — | Password to access Swagger UI |
| Base URL | `L5_SWAGGER_CONST_HOST` | — | API base URL shown in the UI |

Config file: `config/l5-swagger.php`
Annotations entry point: `app/Swagger/SwaggerInfo.php`
Generated spec: `storage/api-docs/api-docs.json`

### Endpoint Reference

Base URL: `http://localhost:8000/api`

All admin endpoints require Bearer token authentication (via Sanctum).

<details>
<summary><strong>Auth</strong></summary>

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/login` | Login (handles 2FA states) |
| POST | `/auth/setup-2fa` | Complete 2FA setup |
| POST | `/auth/verify-otp` | Verify OTP code |
| POST | `/auth/resend-otp` | Resend OTP |
| POST | `/auth/verify-backup-code` | Verify backup code (recovery) |
| POST | `/logout` | Logout and revoke token |
| GET | `/user` | Get current user |

</details>

<details>
<summary><strong>Uploads</strong></summary>

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/admin/uploads` | List uploads |
| POST | `/admin/uploads` | Upload CSV/XLSX file |
| GET | `/admin/uploads/{id}` | Get upload details |
| DELETE | `/admin/uploads/{id}` | Delete upload |
| GET | `/admin/uploads/{id}/status` | Processing status |
| GET | `/admin/uploads/{id}/debtors` | List upload debtors |
| POST | `/admin/uploads/{id}/validate` | Run validation |
| GET | `/admin/uploads/{id}/validation-stats` | Validation statistics |
| POST | `/admin/uploads/{id}/filter-chargebacks` | Remove chargebacked debtors |
| PATCH | `/admin/uploads/{id}/cooldown` | Set 30-day cooldown |
| POST | `/admin/uploads/{id}/reassign` | Reassign to EMP account |
| PATCH | `/admin/uploads/{id}/settings` | Update settings (billing cap) |
| GET | `/admin/uploads/{id}/billing-cycles` | Billing cycle breakdown |
| GET | `/admin/uploads/search` | Search by filename |

</details>

<details>
<summary><strong>Debtors</strong></summary>

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/admin/debtors` | List debtors |
| GET | `/admin/debtors/{id}` | Get debtor |
| PUT | `/admin/debtors/{id}` | Update debtor |
| DELETE | `/admin/debtors/{id}` | Delete debtor |
| POST | `/admin/debtors/{id}/validate` | Re-validate debtor |
| POST | `/admin/debtors/bulk-reassign` | Bulk reassign to EMP account |
| GET | `/admin/debtors/orphans/count` | Orphaned debtors count |
| DELETE | `/admin/debtors/orphans` | Remove orphaned debtors |

</details>

<details>
<summary><strong>VOP Verification</strong></summary>

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/admin/uploads/{id}/vop-stats` | VOP stats for upload |
| POST | `/admin/uploads/{id}/verify-vop` | Start VOP verification |
| GET | `/admin/uploads/{id}/vop-logs` | VOP logs for upload |
| POST | `/admin/vop/verify-single` | Verify single IBAN |
| GET | `/admin/vop-logs` | List all VOP logs |
| GET | `/admin/vop-logs/{id}` | Get VOP log |

</details>

<details>
<summary><strong>BAV (Bank Account Verification)</strong></summary>

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/admin/bav/balance` | Credit balance |
| POST | `/admin/bav/adjust` | Adjust credits |
| GET | `/admin/uploads/{id}/bav/stats` | BAV stats for upload |
| POST | `/admin/uploads/{id}/bav/start` | Start BAV verification |
| GET | `/admin/uploads/{id}/bav/status` | BAV progress |
| POST | `/admin/uploads/{id}/bav/cancel` | Cancel BAV verification |

</details>

<details>
<summary><strong>BAV Batches (Standalone)</strong></summary>

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/admin/bav/batches` | List batches |
| POST | `/admin/bav/batches/upload` | Upload CSV for batch BAV |
| POST | `/admin/bav/batches/{id}/start` | Start batch processing |
| GET | `/admin/bav/batches/{id}/status` | Batch progress |
| GET | `/admin/bav/batches/{id}/download` | Download results CSV |
| GET | `/admin/bav/batches/balance` | BAV credit balance |

</details>

<details>
<summary><strong>Billing</strong></summary>

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/admin/uploads/{id}/sync` | Start billing sync/resync |
| GET | `/admin/uploads/{id}/billing-stats` | Billing statistics |
| POST | `/admin/billing/{id}/cancel` | Cancel active billing |
| POST | `/admin/billing/{id}/void` | Void transactions |
| GET | `/admin/billing-attempts` | List billing attempts |
| GET | `/admin/billing-attempts/{id}` | Get billing attempt |
| POST | `/admin/billing-attempts/{id}/retry` | Retry failed attempt |

</details>

<details>
<summary><strong>Clean Users Export</strong></summary>

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/admin/billing-attempts/clean-users/stats` | Clean users count |
| GET | `/admin/billing-attempts/clean-users/export` | Export CSV |
| GET | `/admin/billing-attempts/clean-users/export/{jobId}/status` | Export job status |
| GET | `/admin/billing-attempts/clean-users/export/{jobId}/download` | Download export |

</details>

<details>
<summary><strong>Reconciliation</strong></summary>

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/admin/billing-attempts/{id}/reconcile` | Reconcile single attempt |
| POST | `/admin/uploads/{id}/reconcile` | Reconcile upload attempts |
| GET | `/admin/uploads/{id}/reconciliation-stats` | Upload reconciliation stats |
| GET | `/admin/reconciliation/stats` | Global reconciliation stats |
| POST | `/admin/reconciliation/bulk` | Bulk reconciliation |

</details>

<details>
<summary><strong>EMP Accounts & Refresh</strong></summary>

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/admin/emp/accounts` | List EMP accounts |
| GET | `/admin/emp/accounts/active` | Get active account |
| POST | `/admin/emp/accounts/{id}/activate` | Set account as active |
| GET | `/admin/emp/accounts/{id}/stats` | Account statistics |
| PUT | `/admin/emp/accounts/{id}/cap` | Update monthly cap |
| GET | `/admin/emp/caps` | All accounts caps & usage |
| POST | `/admin/emp/refresh` | Start EMP refresh |
| GET | `/admin/emp/refresh/status` | Current refresh status |
| GET | `/admin/emp/refresh/{jobId}` | Refresh job status |

</details>

<details>
<summary><strong>Statistics & Analytics</strong></summary>

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/admin/dashboard` | Dashboard overview |
| GET | `/admin/stats/chargeback-rates` | CB rates by country |
| GET | `/admin/stats/chargeback-codes` | CB reason code breakdown |
| GET | `/admin/stats/chargeback-banks` | CB stats by bank |
| GET | `/admin/stats/price-points` | CB stats by price point |
| GET | `/admin/stats/chargeback-all-time` | All-time CB code stats |
| GET | `/admin/analytics/bic` | BIC analytics overview |
| GET | `/admin/analytics/bic/export` | Export BIC analytics CSV |
| GET | `/admin/analytics/bic/price-points` | BIC price point breakdown |
| GET | `/admin/analytics/bic/cb-codes` | BIC CB code breakdown |
| POST | `/admin/analytics/bic/clear-cache` | Clear BIC analytics cache |
| GET | `/admin/analytics/bic/{bic}` | Single BIC summary |

</details>

<details>
<summary><strong>Chargebacks</strong></summary>

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/admin/chargebacks` | List chargebacks with stats |
| GET | `/admin/chargebacks/codes` | Unique reason codes |
| GET | `/admin/chargebacks/upload/{id}` | Upload CB reason breakdown |
| GET | `/admin/chargebacks/upload/{id}/{code}/records` | CB records by code |

</details>

<details>
<summary><strong>Other</strong></summary>

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/admin/tether-instances` | List Tether instances |
| GET | `/admin/webhook-relays` | List webhook relays |
| POST | `/admin/webhook-relays` | Create webhook relay |
| PUT | `/admin/webhook-relays/{id}` | Update webhook relay |
| DELETE | `/admin/webhook-relays/{id}` | Delete webhook relay |
| CRUD | `/admin/billing/descriptors` | Billing descriptors |
| POST | `/webhooks/emp/{token}` | EMP webhook handler |

</details>

## Project Structure
```
app/
├── Http/Controllers/Admin/
├── Models/
├── Services/
├── Jobs/
database/
├── migrations/
├── seeders/
infrastructure/          # Production infrastructure configs
├── api-node/           # Laravel app servers
├── worker-node/        # Queue workers
├── postgres/           # PostgreSQL
├── redis/              # Redis
├── minio/              # S3-compatible storage
├── nginx/              # Load balancer
└── network/            # Docker networks
tests/
└── Feature/Admin/
```

## License

Proprietary - All rights reserved.
