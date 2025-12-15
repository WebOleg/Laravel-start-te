# Tether - Debt Recovery Platform

A SaaS platform for automated debt recovery through SEPA Direct Debit payments.

## Table of Contents

- [Overview](#overview)
- [Tech Stack](#tech-stack)
- [Requirements](#requirements)
- [Installation](#installation)
- [Development](#development)
- [Testing](#testing)
- [API Documentation](#api-documentation)
- [Project Structure](#project-structure)
- [Contributing](#contributing)

## Overview

Tether enables merchants to recover outstanding debts through automated SEPA Direct Debit collection. The platform provides:

- **CSV Upload Processing**: Bulk debtor import with two-stage validation
- **VOP Verification**: IBAN validation and bank identification
- **Automated Billing**: SEPA Direct Debit payment attempts with retry logic
- **Admin Panel**: Real-time monitoring and management

## Tech Stack

| Component | Technology |
|-----------|------------|
| Backend | Laravel 11 (PHP 8.2+) |
| Database | PostgreSQL 15 |
| Authentication | Laravel Sanctum |
| Containerization | Docker & Docker Compose |
| Testing | PHPUnit |

## Requirements

- Docker & Docker Compose
- Git
- Make (optional, for shortcuts)

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

3. **Start containers**
```bash
   docker-compose up -d
   # or
   make up
```

4. **Install dependencies**
```bash
   # If vendor/ directory has permission issues (owned by root)
   sudo chown -R $USER:$USER vendor/ storage/ bootstrap/cache/

   # Install Composer dependencies
   composer install
```

5. **Run migrations and seed**
```bash
   docker-compose exec app php artisan migrate --seed
   # or
   make fresh
```

6. **Generate API token** (for testing)
```bash
   docker-compose exec app php artisan tinker
   >>> User::first()->createToken('dev')->plainTextToken
```

## Development

### Available Make Commands

| Command | Description |
|---------|-------------|
| `make up` | Start Docker containers |
| `make down` | Stop Docker containers |
| `make restart` | Restart containers |
| `make test` | Run all tests |
| `make fresh` | Fresh migrate + seed |
| `make bash` | Enter app container |
| `make tinker` | Laravel REPL |
| `make logs` | View container logs |
| `make help` | Show all commands |

### Without Make
```bash
docker-compose up -d
docker-compose exec app php artisan migrate
docker-compose exec app php artisan db:seed
docker-compose exec app php artisan test
```

## Testing

Run the full test suite:
```bash
make test
# or
docker-compose exec app php artisan test
```

Run specific tests:
```bash
docker-compose exec app php artisan test --filter=UploadControllerTest
```

### Test Coverage

| Controller | Tests | Status |
|------------|-------|--------|
| UploadController | 8 | ✅ |
| DebtorController | 9 | ✅ |
| VopLogController | 5 | ✅ |
| BillingAttemptController | 6 | ✅ |
| UploadValidationTest | 5 | ✅ |
| BlacklistUploadTest | 4 | ✅ |

## API Documentation

Base URL: `http://localhost:8000/api`

All endpoints require Bearer token authentication.

See [docs/API.md](docs/API.md) for detailed API documentation.

### Quick Reference

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/admin/uploads` | List all uploads |
| GET | `/admin/uploads/{id}` | Get upload details |
| POST | `/admin/uploads` | Create upload (multipart) |
| GET | `/admin/uploads/{id}/status` | Get upload status |
| POST | `/admin/uploads/{id}/validate` | Trigger validation |
| GET | `/admin/uploads/{id}/validation-stats` | Get validation stats |
| GET | `/admin/uploads/{id}/debtors` | List upload debtors |
| GET | `/admin/debtors` | List all debtors |
| GET | `/admin/debtors/{id}` | Get debtor details |
| PUT | `/admin/debtors/{id}` | Update debtor (raw_data) |
| GET | `/admin/vop-logs` | List VOP verifications |
| GET | `/admin/vop-logs/{id}` | Get VOP details |
| GET | `/admin/billing-attempts` | List billing attempts |
| GET | `/admin/billing-attempts/{id}` | Get attempt details |

### Two-Stage Validation Flow

1. **Stage A (Upload)**: All rows accepted, saved with `validation_status=pending`
2. **Stage B (Validation)**: User triggers validation via `/validate` endpoint
3. **Stage C (Sync)**: Only valid debtors sent to payment gateway

## Project Structure
```
app/
├── Http/
│   ├── Controllers/
│   │   └── Admin/           # Admin API controllers
│   └── Resources/           # API JSON transformers
├── Models/                  # Eloquent models
├── Services/                # Business logic
│   ├── IbanValidator.php
│   ├── FileUploadService.php
│   ├── DebtorValidationService.php
│   └── BlacklistService.php
├── Jobs/                    # Queue jobs
│   ├── ProcessUploadJob.php
│   └── ProcessUploadChunkJob.php
database/
├── factories/               # Test data factories
├── migrations/              # Database schema
├── seeders/                 # Development data
tests/
└── Feature/
    └── Admin/               # API feature tests
docs/                        # Documentation
```

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for detailed architecture overview.

## Contributing

Please read [docs/CONTRIBUTING.md](docs/CONTRIBUTING.md) for:

- Code style guidelines
- Git workflow
- Pull request process

## License

Proprietary - All rights reserved.
