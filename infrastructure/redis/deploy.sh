#!/bin/bash
# Tether Redis Deployment Script
# THR-173: Deploy Redis cluster

set -e

echo "=== Deploying Tether Redis ==="

# Install Docker if needed
if ! command -v docker &> /dev/null; then
    curl -fsSL https://get.docker.com | sh
    systemctl enable docker
    systemctl start docker
fi

# Create network
docker network create tether-network 2>/dev/null || true

# Start Redis
docker-compose up -d

echo "=== Redis deployed ==="
echo "Test: docker exec tether-redis redis-cli ping"
