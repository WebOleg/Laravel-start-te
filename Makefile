# Tether Laravel - Development Commands

.PHONY: up down restart build test fresh seed migrate tinker logs bash check-ports stop-host-postgres clean-ports up-safe up-alt down-alt status

# Port and conflict management
check-ports:
	@echo "Checking port availability..."
	@if lsof -i :5432 >/dev/null 2>&1 || ss -tln 2>/dev/null | grep -q ":5432 "; then \
		echo "⚠️  Port 5432 (PostgreSQL) is in use"; \
		lsof -i :5432 2>/dev/null || ss -tlnp 2>/dev/null | grep :5432 || echo ""; \
	else \
		echo "✓ Port 5432 is available"; \
	fi
	@if lsof -i :6379 >/dev/null 2>&1 || ss -tln 2>/dev/null | grep -q ":6379 "; then \
		echo "⚠️  Port 6379 (Redis) is in use"; \
	else \
		echo "✓ Port 6379 is available"; \
	fi
	@if lsof -i :8000 >/dev/null 2>&1 || ss -tln 2>/dev/null | grep -q ":8000 "; then \
		echo "⚠️  Port 8000 (Nginx) is in use"; \
	else \
		echo "✓ Port 8000 is available"; \
	fi

stop-host-postgres:
	@echo "Stopping PostgreSQL on host machine..."
	@if systemctl is-active --quiet postgresql 2>/dev/null; then \
		sudo systemctl stop postgresql && echo "✓ PostgreSQL service stopped"; \
	elif systemctl is-active --quiet postgresql@* 2>/dev/null; then \
		sudo systemctl stop 'postgresql@*' && echo "✓ PostgreSQL service stopped"; \
	else \
		echo "ℹ️  PostgreSQL service not found or already stopped"; \
		echo "You may need to manually stop PostgreSQL if it's running"; \
	fi

clean-ports:
	@echo "Cleaning up Docker resources..."
	docker-compose down --remove-orphans
	@echo "✓ Cleanup complete"

up-safe: check-ports clean-ports
	@echo ""
	@echo "Starting Tether services..."
	@if lsof -i :5432 >/dev/null 2>&1 || ss -tln 2>/dev/null | grep -q ":5432 "; then \
		echo ""; \
		echo "❌ Port 5432 is still in use!"; \
		echo ""; \
		echo "Options:"; \
		echo "  1. Run 'make stop-host-postgres' to stop PostgreSQL on host"; \
		echo "  2. Run 'make up-force' to attempt forcing the start"; \
		echo ""; \
		exit 1; \
	fi
	docker-compose up -d
	@echo ""
	@docker-compose ps

up-force: stop-host-postgres clean-ports
	@echo "Force starting all services..."
	docker-compose up -d
	@echo ""
	@docker-compose ps

up-alt:
	@echo "Starting services with alternative ports..."
	@echo "  - PostgreSQL: 5433 (instead of 5432)"
	@echo "  - Redis: 6380 (instead of 6379)"
	@echo "  - Nginx: 8000"
	@echo ""
	docker-compose -f docker-compose.alt.yml down --remove-orphans 2>/dev/null || true
	docker-compose -f docker-compose.alt.yml up -d
	@echo ""
	@docker-compose -f docker-compose.alt.yml ps
	@echo ""
	@echo "Note: Update your .env file to use these ports if needed:"
	@echo "  DB_PORT=5433"
	@echo "  REDIS_PORT=6380"

down-alt:
	@echo "Stopping services (alternative ports)..."
	docker-compose -f docker-compose.alt.yml down

status:
	@echo "=== Port Status ==="
	@make check-ports
	@echo ""
	@echo "=== Default Containers ==="
	@docker-compose ps 2>/dev/null || echo "No containers running"
	@echo ""
	@echo "=== Alternative Port Containers ==="
	@docker-compose -f docker-compose.alt.yml ps 2>/dev/null || echo "No containers running"

# Docker commands
up:
	@make up-safe

down:
	docker-compose down

restart:
	docker-compose down && docker-compose up -d

build:
	docker-compose build --no-cache

logs:
	docker-compose logs -f

# Container access
bash:
	docker-compose exec app bash

tinker:
	docker-compose exec app php artisan tinker

# Database commands
migrate:
	docker-compose exec app php artisan migrate

fresh:
	docker-compose exec app composer dump-autoload
	docker-compose exec app php artisan migrate:fresh --seed

seed:
	docker-compose exec app php artisan db:seed

# Composer
autoload:
	docker-compose exec app composer dump-autoload

install:
	docker-compose exec app composer install

# Testing
test:
	docker-compose exec app php artisan test

test-filter:
	docker-compose exec app php artisan test --filter=$(filter)

test-coverage:
	docker-compose exec app php artisan test --coverage

# Cache commands
cache:
	docker-compose exec app php artisan config:cache
	docker-compose exec app php artisan route:cache

clear:
	docker-compose exec app php artisan config:clear
	docker-compose exec app php artisan route:clear
	docker-compose exec app php artisan cache:clear

# Help
help:
	@echo "Available commands:"
	@echo ""
	@echo "🐳 Docker Management:"
	@echo "  make up                 - Start containers (safe mode with port checks)"
	@echo "  make up-force           - Force start (stops host PostgreSQL first)"
	@echo "  make up-alt             - Start with alternative ports (5433, 6380)"
	@echo "  make down               - Stop containers"
	@echo "  make down-alt           - Stop alternative port containers"
	@echo "  make restart            - Restart containers"
	@echo "  make build              - Rebuild containers"
	@echo "  make status             - Show detailed status of all services"
	@echo ""
	@echo "🔍 Port & Troubleshooting:"
	@echo "  make check-ports        - Check if required ports are available"
	@echo "  make stop-host-postgres - Stop PostgreSQL service on host machine"
	@echo "  make clean-ports        - Clean up Docker resources"
	@echo ""
	@echo "🗄️  Database:"
	@echo "  make migrate            - Run migrations"
	@echo "  make fresh              - Fresh migrate + seed"
	@echo "  make seed               - Run seeders"
	@echo ""
	@echo "🧪 Testing:"
	@echo "  make test               - Run all tests"
	@echo "  make test-filter        - Run filtered tests (filter=TestName)"
	@echo "  make test-coverage      - Run tests with coverage"
	@echo ""
	@echo "📦 Composer:"
	@echo "  make install            - Composer install"
	@echo "  make autoload           - Composer dump-autoload"
	@echo ""
	@echo "🔧 Utilities:"
	@echo "  make bash               - Enter app container"
	@echo "  make tinker             - Laravel tinker"
	@echo "  make logs               - View logs"
	@echo "  make cache              - Cache config & routes"
	@echo "  make clear              - Clear all caches"
