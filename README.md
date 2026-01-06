cat > README.md << 'EOF'

# Tether - Debt Recovery Platform

A SaaS platform for automated debt recovery through SEPA Direct Debit payments with emerchantpay integration.

## Table of Contents

-   [Overview](#overview)
-   [Features](#features)
-   [Tech Stack](#tech-stack)
-   [Requirements](#requirements)
-   [Installation](#installation)
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
| **VOP Verification**      | IBAN validation, bank identification, and name matching via Sumsub           |
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
| Backend          | Laravel 11 (PHP 8.2+)    |
| Database         | PostgreSQL 15            |
| Queue            | Redis + Laravel Queue    |
| Authentication   | Laravel Sanctum          |
| Payment Gateway  | emerchantpay Genesis API |
| VOP Provider     | Sumsub                   |
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
EMP_API_LOGIN=your_api_login
EMP_API_PASSWORD=your_api_password
EMP_TERMINAL_TOKEN=your_terminal_token
EMP_ENVIRONMENT=staging  # or production

# Sumsub VOP
SUMSUB_APP_TOKEN=your_token
SUMSUB_SECRET_KEY=your_secret
```

4. **Start containers**

```bash
docker compose up -d
```

5. **Install dependencies & migrate**

```bash
docker compose exec app composer install
docker compose exec app php artisan migrate --seed
```

6. **Start queue worker** (for async processing)

```bash
docker compose exec app php artisan queue:work
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

| Test Suite                   | Tests | Status |
| ---------------------------- | ----- | ------ |
| UploadControllerTest         | 8     | ✅     |
| DebtorControllerTest         | 9     | ✅     |
| DebtorValidationServiceTest  | 15    | ✅     |
| VopLogControllerTest         | 5     | ✅     |
| BillingAttemptControllerTest | 6     | ✅     |
| BillingControllerTest        | 8     | ✅     |
| ReconciliationControllerTest | 10    | ✅     |
| BlacklistServiceTest         | 12    | ✅     |
| DeduplicationServiceTest     | 8     | ✅     |

## API Documentation

Base URL: `http://localhost:8000/api`

All endpoints require Bearer token authentication.

### Uploads

| Method | Endpoint                               | Description           |
| ------ | -------------------------------------- | --------------------- |
| GET    | `/admin/uploads`                       | List uploads          |
| POST   | `/admin/uploads`                       | Upload CSV file       |
| GET    | `/admin/uploads/{id}`                  | Get upload details    |
| DELETE | `/admin/uploads/{id}`                  | Delete upload         |
| POST   | `/admin/uploads/{id}/validate`         | Trigger validation    |
| GET    | `/admin/uploads/{id}/validation-stats` | Validation statistics |
| GET    | `/admin/uploads/{id}/debtors`          | List upload debtors   |

### Debtors

| Method | Endpoint                       | Description        |
| ------ | ------------------------------ | ------------------ |
| GET    | `/admin/debtors`               | List debtors       |
| GET    | `/admin/debtors/{id}`          | Get debtor         |
| PUT    | `/admin/debtors/{id}`          | Update debtor      |
| DELETE | `/admin/debtors/{id}`          | Delete debtor      |
| POST   | `/admin/debtors/{id}/validate` | Re-validate debtor |

### VOP Verification

| Method | Endpoint                         | Description            |
| ------ | -------------------------------- | ---------------------- |
| GET    | `/admin/vop-logs`                | List VOP logs          |
| GET    | `/admin/vop-logs/{id}`           | Get VOP log            |
| POST   | `/admin/uploads/{id}/verify-vop` | Start VOP verification |
| GET    | `/admin/uploads/{id}/vop-stats`  | VOP statistics         |
| POST   | `/admin/vop/verify-single`       | Verify single IBAN     |

### Billing

| Method | Endpoint                             | Description          |
| ------ | ------------------------------------ | -------------------- |
| GET    | `/admin/billing-attempts`            | List attempts        |
| GET    | `/admin/billing-attempts/{id}`       | Get attempt          |
| POST   | `/admin/uploads/{id}/sync`           | Start billing sync   |
| GET    | `/admin/uploads/{id}/billing-stats`  | Billing statistics   |
| POST   | `/admin/billing-attempts/{id}/retry` | Retry failed attempt |

### Reconciliation

| Method | Endpoint                                   | Description                 |
| ------ | ------------------------------------------ | --------------------------- |
| GET    | `/admin/reconciliation/stats`              | Global reconciliation stats |
| GET    | `/admin/uploads/{id}/reconciliation-stats` | Upload reconciliation stats |
| POST   | `/admin/billing-attempts/{id}/reconcile`   | Reconcile single attempt    |
| POST   | `/admin/uploads/{id}/reconcile`            | Reconcile upload attempts   |
| POST   | `/admin/reconciliation/bulk`               | Bulk reconciliation         |

### Statistics

| Method | Endpoint                        | Description                 |
| ------ | ------------------------------- | --------------------------- |
| GET    | `/admin/dashboard`              | Dashboard data              |
| GET    | `/admin/stats/chargeback-rates` | Chargeback rates by country |
| GET    | `/admin/stats/chargeback-codes` | Chargeback by error code    |
| GET    | `/admin/stats/chargeback-banks` | Chargeback by bank          |

### Webhooks

| Method | Endpoint        | Description                  |
| ------ | --------------- | ---------------------------- |
| POST   | `/webhooks/emp` | emerchantpay webhook handler |

## Project Structure

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── Admin/
│   │   │   ├── UploadController.php
│   │   │   ├── DebtorController.php
│   │   │   ├── BillingController.php
│   │   │   ├── BillingAttemptController.php
│   │   │   ├── ReconciliationController.php
│   │   │   ├── VopLogController.php
│   │   │   └── StatsController.php
│   │   └── Webhook/
│   │       └── EmpWebhookController.php
│   └── Resources/
├── Models/
│   ├── Upload.php
│   ├── Debtor.php
│   ├── BillingAttempt.php
│   ├── VopLog.php
│   └── Blacklist.php
├── Services/
│   ├── Emp/
│   │   ├── EmpClient.php
│   │   └── EmpBillingService.php
│   ├── IbanValidator.php
│   ├── DebtorValidationService.php
│   ├── BlacklistService.php
│   ├── DeduplicationService.php
│   ├── ReconciliationService.php
│   └── FileUploadService.php
├── Jobs/
│   ├── ProcessUploadJob.php
│   ├── ProcessUploadChunkJob.php
│   ├── ProcessBillingJob.php
│   ├── ProcessBillingChunkJob.php
│   ├── ProcessReconciliationJob.php
│   └── ProcessReconciliationChunkJob.php
database/
├── migrations/
└── seeders/
tests/
└── Feature/
    └── Admin/
```

## Environment Variables

### Required

| Variable             | Description               |
| -------------------- | ------------------------- |
| `EMP_API_LOGIN`      | emerchantpay API login    |
| `EMP_API_PASSWORD`   | emerchantpay API password |
| `EMP_TERMINAL_TOKEN` | Terminal token            |
| `EMP_ENVIRONMENT`    | `staging` or `production` |
| `SUMSUB_APP_TOKEN`   | Sumsub application token  |
| `SUMSUB_SECRET_KEY`  | Sumsub secret key         |

### Optional

| Variable                       | Default | Description                   |
| ------------------------------ | ------- | ----------------------------- |
| `BILLING_CHUNK_SIZE`           | 100     | Records per billing chunk     |
| `RECONCILIATION_MIN_AGE_HOURS` | 2       | Min age before reconciliation |
| `RECONCILIATION_MAX_ATTEMPTS`  | 10      | Max reconciliation attempts   |

## License

Proprietary - All rights reserved.
EOF
# CI/CD test
