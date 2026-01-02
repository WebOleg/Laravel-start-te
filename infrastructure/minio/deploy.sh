#!/bin/bash
# Tether MinIO Deployment Script
# THR-174: Deploy MinIO for S3 storage

set -e

echo "=== Deploying Tether MinIO ==="

# Install Docker if needed
if ! command -v docker &> /dev/null; then
    curl -fsSL https://get.docker.com | sh
    systemctl enable docker
    systemctl start docker
fi

# Create network
docker network create tether-network 2>/dev/null || true

# Set credentials
export MINIO_ROOT_USER=${MINIO_ROOT_USER:-minioadmin}
export MINIO_ROOT_PASSWORD=${MINIO_ROOT_PASSWORD:-$(openssl rand -base64 32)}

echo "MinIO Credentials:"
echo "  User: $MINIO_ROOT_USER"
echo "  Password: $MINIO_ROOT_PASSWORD"
echo ""

# Start MinIO
docker-compose up -d

# Wait for MinIO to start
sleep 5

# Install mc client and create bucket
docker run --rm --network tether-network \
    -e MC_HOST_minio=http://$MINIO_ROOT_USER:$MINIO_ROOT_PASSWORD@tether-minio:9000 \
    minio/mc mb minio/tether-uploads --ignore-existing

echo ""
echo "=== MinIO deployed ==="
echo "S3 Endpoint: http://localhost:9000"
echo "Console: http://localhost:9001"
echo "Bucket: tether-uploads"
