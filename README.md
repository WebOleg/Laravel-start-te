# Tether - Debt Recovery Platform

A SaaS platform for automated debt recovery through SEPA Direct Debit payments.

## Table of Contents

- [Overview](#overview)
- [Tech Stack](#tech-stack)
- [Requirements](#requirements)
- [Installation](#installation)
- [Development](#development)
- [Troubleshooting](#troubleshooting)
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
   # Recommended: Use the startup script (handles port conflicts automatically)
   ./start.sh

   # Or use Make commands
   make up              # Safe mode with port checks
   make up-force        # Force start (stops conflicting services)
   make up-alt          # Use alternative ports (5433, 6380)

   # Or use Docker Compose directly
   docker-compose up -d
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

### Quick Start Commands

```bash
./start.sh           # Interactive startup with conflict resolution
make help            # Show all available commands
make status          # Check ports and container status
```

### Available Make Commands

#### Docker Management
| Command | Description |
|---------|-------------|
| `make up` | Start containers (safe mode with port checks) |
| `make up-force` | Force start (automatically stops conflicting services) |
| `make up-alt` | Start with alternative ports (5433, 6380) |
| `make down` | Stop containers |
| `make down-alt` | Stop alternative port containers |
| `make restart` | Restart containers |
| `make build` | Rebuild containers |
| `make status` | Show detailed status of all services |

#### Port & Troubleshooting
| Command | Description |
|---------|-------------|
| `make check-ports` | Check if required ports are available |
| `make stop-host-postgres` | Stop host database service (if running) |
| `make clean-ports` | Clean up Docker resources |

#### Database
| Command | Description |
|---------|-------------|
| `make migrate` | Run migrations |
| `make fresh` | Fresh migrate + seed |
| `make seed` | Run seeders |

#### Testing
| Command | Description |
|---------|-------------|
| `make test` | Run all tests |
| `make test-filter` | Run filtered tests (filter=TestName) |
| `make test-coverage` | Run tests with coverage |

#### Utilities
| Command | Description |
|---------|-------------|
| `make bash` | Enter app container |
| `make tinker` | Laravel REPL |
| `make logs` | View container logs |
| `make cache` | Cache config & routes |
| `make clear` | Clear all caches |
| `make install` | Composer install |
| `make autoload` | Composer dump-autoload |

### Without Make
```bash
docker-compose up -d
docker-compose exec app php artisan migrate
docker-compose exec app php artisan db:seed
docker-compose exec app php artisan test
```

## Troubleshooting

### Port Conflicts (Address Already in Use)

If you see errors like `bind: address already in use` when starting containers:

**Option 1: Use the startup script (Recommended)**
```bash
./start.sh
```
The script will detect port conflicts and offer solutions interactively.

**Option 2: Use Make commands**
```bash
# Check which ports are in use
make check-ports

# Automatically stop conflicting services and start containers
make up-force

# Use alternative ports to avoid conflicts
make up-alt
```

**Option 3: Manual resolution**
```bash
# Check what's using a specific port (example: 5432)
lsof -i :5432
# or
ss -tlnp | grep :5432

# Stop the conflicting service (example: PostgreSQL)
sudo systemctl stop postgresql

# Clean up and start
make clean-ports
docker-compose up -d
```

### Alternative Ports Setup

If you want to run Tether alongside existing database/cache services on your host:

1. Start with alternative ports:
```bash
make up-alt
```

2. Update your [.env](.env.example) file:
```env
DB_HOST=127.0.0.1
DB_PORT=5433           # Changed from 5432
REDIS_PORT=6380        # Changed from 6379
```

3. The services will be available at:
   - PostgreSQL: `localhost:5433`
   - Redis: `localhost:6380`
   - Nginx: `localhost:8000`

### Common Issues

**Container fails to start**
```bash
make clean-ports
make up
```

**Permission issues with vendor/ or storage/**
```bash
sudo chown -R $USER:$USER vendor/ storage/ bootstrap/cache/
```

**Database connection refused**
```bash
# Check if containers are running
make status

# Check logs
make logs

# Restart services
make restart
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
