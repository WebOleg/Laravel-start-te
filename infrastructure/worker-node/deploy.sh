#!/bin/bash
# Tether Worker Node Deployment Script
# THR-172: Provision worker nodes

set -e

REPO_URL="git@github.com:your-org/tether-laravel.git"
APP_DIR="/opt/tether"

echo "=== Deploying Tether Worker Node ==="

# Update system
apt-get update && apt-get upgrade -y

# Install Docker
if ! command -v docker &> /dev/null; then
    curl -fsSL https://get.docker.com | sh
    systemctl enable docker
    systemctl start docker
fi

# Install Docker Compose
if ! command -v docker-compose &> /dev/null; then
    curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
    chmod +x /usr/local/bin/docker-compose
fi

# Clone or update
if [ -d "$APP_DIR" ]; then
    cd $APP_DIR && git pull origin main
else
    git clone $REPO_URL $APP_DIR
    cd $APP_DIR
fi

# Environment
if [ ! -f ".env" ]; then
    cp .env.production .env
    echo "⚠️  Configure .env before continuing!"
    exit 1
fi

# Copy Horizon config
cp infrastructure/worker-node/horizon.php config/horizon.php

# Docker network
docker network create tether-network 2>/dev/null || true

# Build and start
cd infrastructure/worker-node
docker-compose build
docker-compose up -d

echo "=== Worker Node deployed ==="
echo "Check status: docker-compose exec worker php artisan horizon:status"
