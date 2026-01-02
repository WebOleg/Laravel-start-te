# Redis Configuration

## THR-173: Deploy Redis cluster

### Requirements Met
- Redis 7.x ✅
- 2-4 GB RAM ✅
- Private network only ✅
- maxmemory 2gb ✅
- maxmemory-policy allkeys-lru ✅

### Deployment
```bash
./deploy.sh
```

### Testing
```bash
# From Redis server
docker exec tether-redis redis-cli ping

# From API node
redis-cli -h redis-server-ip ping
```

### Monitoring
```bash
# Memory usage
docker exec tether-redis redis-cli info memory

# Queue lengths
docker exec tether-redis redis-cli llen queues:webhooks
docker exec tether-redis redis-cli llen queues:billing
docker exec tether-redis redis-cli llen queues:default
```

### For API/Worker Nodes

Add to `.env`:
```
REDIS_HOST=redis-server-ip
REDIS_PORT=6379
```
