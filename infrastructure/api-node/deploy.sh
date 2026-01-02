#!/bin/bash
# Tether API Node Deployment Script
# THR-171: Provision API nodes
#
# Usage: ./deploy.sh [node_number] [run_migrations]
# Example: ./deploy.sh 1 true   # First node with migrations
#          ./deploy.sh 2 false  # Second node without migrations

set -e

NODE_NUMBER=${1:-1}
RUN_MIGRATIONS=${2:-false}
REPO_URL="git@github.com:your-org/tether-laravel.git"
APP_DIR="/opt/tether"

echo "=== Deploying Tether API Node $NODE_NUMBER ==="

# Update system
echo "Updating system packages..."
apt-get update && apt-get upgrade -y

# Install Docker if not present
if ! command -v docker &> /dev/null; then
    echo "Installing Docker..."
    curl -fsSL https://get.docker.com | sh
    systemctl enable docker
    systemctl start docker
fi

# Install Docker Compose if not present
if ! command -v docker-compose &> /dev/null; then
    echo "Installing Docker Compose..."
    curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
    chmod +x /usr/local/bin/docker-compose
fi

# Clone or update repository
if [ -d "$APP_DIR" ]; then
    echo "Updating repository..."
    cd $APP_DIR
    git pull origin main
else
    echo "Cloning repository..."
    git clone $REPO_URL $APP_DIR
    cd $APP_DIR
fi

# Copy environment file
if [ ! -f ".env" ]; then
    echo "Creating .env file..."
    cp .env.production .env
    echo "⚠️  Please configure .env file before continuing!"
    exit 1
fi

# Create Docker network if not exists
docker network create tether-network 2>/dev/null || true

# Build and start
echo "Building and starting containers..."
cd infrastructure/api-node
docker-compose build
docker-compose up -d

# Run migrations (only on first node)
if [ "$RUN_MIGRATIONS" = "true" ]; then
    echo "Running migrations..."
    docker-compose exec -T api php artisan migrate --force
    docker-compose exec -T api php artisan config:cache
    docker-compose exec -T api php artisan route:cache
    docker-compose exec -T api php artisan view:cache
fi

# Optimize
echo "Optimizing application..."
docker-compose exec -T api php artisan optimize

echo "=== API Node $NODE_NUMBER deployed successfully ==="
echo "Health check: curl http://localhost/health"
