# Worker Node Configuration

## THR-172: Provision worker nodes

### Purpose
Queue processing with Laravel Horizon. No Nginx (not serving HTTP).

### Specs
- Same as API nodes (2-4 vCPUs, 4-8 GB RAM)
- No Nginx, no PHP-FPM
- PHP CLI only

### 3 Supervisors (Queues)

| Supervisor | Queue | Processes | Timeout | Purpose |
|------------|-------|-----------|---------|---------|
| webhooks | webhooks | 3-5 | 30s | EMP webhook jobs (critical) |
| billing | billing | 2-3 | 120s | Payment processing |
| default | default | 2-3 | 60s | Uploads, emails, etc. |

### Deployment
```bash
./deploy.sh
```

### Horizon Commands
```bash
# Status
docker-compose exec worker php artisan horizon:status

# Pause
docker-compose exec worker php artisan horizon:pause

# Continue
docker-compose exec worker php artisan horizon:continue

# Terminate (graceful)
docker-compose exec worker php artisan horizon:terminate

# List supervisors
docker-compose exec worker php artisan horizon:supervisors
```

### Scaling

To handle more jobs:
1. Increase maxProcesses in horizon.php
2. Or add more worker nodes

### Architecture
```
┌─────────────────────────────────────────────────────────┐
│                    Worker Node                           │
│  ┌───────────────────────────────────────────────────┐  │
│  │                  Horizon                           │  │
│  │  ┌─────────────┬─────────────┬─────────────────┐  │  │
│  │  │  webhooks   │   billing   │     default     │  │  │
│  │  │  (5 proc)   │  (3 proc)   │    (3 proc)     │  │  │
│  │  └─────────────┴─────────────┴─────────────────┘  │  │
│  └───────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────┘
                           │
                           ▼
                    ┌─────────────┐
                    │    Redis    │
                    │   (queues)  │
                    └─────────────┘
```
