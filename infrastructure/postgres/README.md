# PostgreSQL + pgBouncer Configuration

## THR-175: Deploy Postgres with pgBouncer

### Requirements Met
- Postgres 14+ (using 16) ✅
- 4-8 GB RAM, 50-100 GB SSD ✅
- Private network only ✅
- pgBouncer connection pooling ✅

### pgBouncer Config
- Pool mode: transaction ✅
- Max connections: 200 ✅
- Pool size: 25 ✅

### Deployment
```bash
export DB_PASSWORD="your_secure_password"
./deploy.sh
```

### For API/Worker Nodes

Use pgBouncer port (6432), not Postgres directly:
```
DB_HOST=postgres-server-ip
DB_PORT=6432
DB_DATABASE=tether
DB_USERNAME=tether
DB_PASSWORD=your_password
```

### Testing
```bash
# Direct Postgres
docker exec tether-postgres psql -U tether -c 'SELECT 1'

# Via pgBouncer
psql -h localhost -p 6432 -U tether -c 'SELECT 1'
```

### Monitoring
```bash
# pgBouncer stats
docker exec tether-pgbouncer psql -p 6432 -U tether pgbouncer -c 'SHOW POOLS'

# Postgres connections
docker exec tether-postgres psql -U tether -c 'SELECT count(*) FROM pg_stat_activity'
```
