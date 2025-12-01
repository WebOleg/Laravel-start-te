# Tether Laravel Backend

## Requirements
- PHP 8.2+
- Composer
- PostgreSQL

## Setup
```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

## API
Base URL: `http://localhost:8000/api`
