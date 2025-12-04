# Tether Laravel - Development Commands

.PHONY: up down restart build test fresh seed migrate tinker logs bash

# Docker commands
up:
	docker-compose up -d

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
	docker-compose exec app php artisan migrate:fresh --seed

seed:
	docker-compose exec app php artisan db:seed

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
	@echo "  make up        - Start containers"
	@echo "  make down      - Stop containers"
	@echo "  make restart   - Restart containers"
	@echo "  make test      - Run all tests"
	@echo "  make fresh     - Fresh migrate + seed"
	@echo "  make bash      - Enter app container"
	@echo "  make tinker    - Laravel tinker"
	@echo "  make logs      - View logs"
