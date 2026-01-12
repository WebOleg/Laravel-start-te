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

| Test Suite                   | Tests | Status |
| ---------------------------- | ----- | ------ |
| UploadControllerTest         | 8     | ✅     |
| DebtorControllerTest         | 9     | ✅     |
| DebtorValidationServiceTest  | 15    | ✅     |
| VopLogControllerTest         | 5     | ✅     |
| BillingAttemptControllerTest | 6     | ✅     |
| BillingControllerTest        | 11    | ✅     |
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
