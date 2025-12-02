# Docker Setup

## Requirements
- Docker Desktop

## Quick Start

1. Clone repo
2. Copy `.env.example` to `.env`
3. Run:
```bash
docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

4. Open: http://localhost:8000

## Commands
```bash
# Start
docker compose up -d

# Stop
docker compose down

# Logs
docker compose logs -f

# Run artisan
docker compose exec app php artisan [command]

# Access database
docker compose exec db psql -U tether -d tether

# Rebuild
docker compose up -d --build
```
