#!/bin/bash
# Tether PostgreSQL + pgBouncer Deployment Script
# THR-175: Deploy Postgres with pgBouncer

set -e

echo "=== Deploying Tether PostgreSQL + pgBouncer ==="

# Install Docker if needed
if ! command -v docker &> /dev/null; then
    curl -fsSL https://get.docker.com | sh
    systemctl enable docker
    systemctl start docker
fi

# Create network
docker network create tether-network 2>/dev/null || true

# Check password
if [ -z "$DB_PASSWORD" ]; then
    echo "⚠️  DB_PASSWORD not set. Generating..."
    export DB_PASSWORD=$(openssl rand -base64 32)
    echo "Generated password: $DB_PASSWORD"
fi

# Start services
docker-compose up -d

echo "=== PostgreSQL + pgBouncer deployed ==="
echo ""
echo "PostgreSQL: localhost:5432"
echo "pgBouncer:  localhost:6432 (use this for app)"
echo ""
echo "Test: docker exec tether-postgres psql -U tether -c 'SELECT 1'"
