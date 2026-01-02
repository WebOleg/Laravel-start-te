# API Node Configuration

## THR-171: Provision API nodes

### Specs per Node
- Ubuntu 22.04 LTS
- 2-4 vCPUs
- 4-8 GB RAM
- 20 GB disk

### Stack
- Nginx (reverse proxy)
- PHP 8.3 FPM
- Extensions: pdo_pgsql, redis, gd, mbstring, xml, zip, intl, bcmath, opcache

### Deployment

#### First Node (with migrations)
```bash
./deploy.sh 1 true
```

#### Additional Nodes (without migrations)
```bash
./deploy.sh 2 false
```

### Configuration Files
- `Dockerfile` - Container build instructions
- `php.ini` - PHP configuration (optimized for production)
- `php-fpm.conf` - FPM pool configuration
- `nginx-site.conf` - Nginx site configuration
- `supervisord.conf` - Process management
- `docker-compose.yml` - Container orchestration
- `deploy.sh` - Deployment script

### Environment Variables
Required in `.env`:
```
DB_HOST=postgres
DB_DATABASE=tether
DB_USERNAME=xxx
DB_PASSWORD=xxx
REDIS_HOST=redis
MINIO_ENDPOINT=http://minio:9000
MINIO_ACCESS_KEY=xxx
MINIO_SECRET_KEY=xxx
MINIO_BUCKET=tether
```

### Health Checks
```bash
# HTTP health
curl http://localhost/health

# FPM status (from container)
curl http://127.0.0.1/fpm-status

# FPM ping
curl http://127.0.0.1/fpm-ping
```

### Scaling
To add more API nodes:
1. Provision new server with same specs
2. Run `./deploy.sh N false` (N = node number)
3. Add node to load balancer upstream in `infrastructure/nginx/load-balancer.conf`
4. Reload load balancer: `docker exec tether-loadbalancer nginx -s reload`

### Architecture
```
                Load Balancer
                     │
        ┌────────────┼────────────┐
        ▼            ▼            ▼
   ┌─────────┐  ┌─────────┐  ┌─────────┐
   │ API     │  │ API     │  │ API     │
   │ Node 1  │  │ Node 2  │  │ Node N  │
   └─────────┘  └─────────┘  └─────────┘
        │            │            │
        └────────────┼────────────┘
                     │
              Shared Services
           (Redis, PostgreSQL, MinIO)
```
