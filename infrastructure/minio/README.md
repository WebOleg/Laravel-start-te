# MinIO Configuration

## THR-174: Deploy MinIO for S3 storage

> Note: MinIO replaces Ceph as per Azay's decision

### Requirements Met
- S3-compatible storage ✅
- 50-100 GB storage ✅
- Private network only ✅
- Bucket: tether-uploads ✅

### Deployment
```bash
export MINIO_ROOT_USER="admin"
export MINIO_ROOT_PASSWORD="your_secure_password"
./deploy.sh
```

### Testing
```bash
# Upload test file
docker run --rm --network tether-network \
    -e MC_HOST_minio=http://admin:password@tether-minio:9000 \
    minio/mc cp /etc/hosts minio/tether-uploads/test.txt

# Download test file
docker run --rm --network tether-network \
    -e MC_HOST_minio=http://admin:password@tether-minio:9000 \
    minio/mc cat minio/tether-uploads/test.txt
```

### For API/Worker Nodes

Add to `.env`:
```
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=admin
AWS_SECRET_ACCESS_KEY=your_secure_password
AWS_BUCKET=tether-uploads
AWS_ENDPOINT=http://minio-server-ip:9000
AWS_USE_PATH_STYLE_ENDPOINT=true
```

### Console Access

URL: http://localhost:9001
Login with MINIO_ROOT_USER / MINIO_ROOT_PASSWORD
