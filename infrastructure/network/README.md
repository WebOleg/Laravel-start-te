# Private Network Configuration

## THR-176: Configure private networking

### Security Summary

| Service | Private IP | Public Access |
|---------|------------|---------------|
| Load Balancer | 10.0.1.10 | ✅ Yes (80, 443) |
| API Nodes | 10.0.1.11-12 | ❌ No |
| Worker Node | 10.0.1.21 | ❌ No |
| Redis | 10.0.1.31 | ❌ No |
| PostgreSQL | 10.0.1.41 | ❌ No |
| MinIO | 10.0.1.51 | ❌ No |

### Apply Firewall Rules
```bash
# On Load Balancer
./firewall-rules.sh loadbalancer

# On API Nodes
./firewall-rules.sh api

# On Worker Node
./firewall-rules.sh worker

# On Redis
./firewall-rules.sh redis

# On PostgreSQL
./firewall-rules.sh postgres

# On MinIO
./firewall-rules.sh minio
```

### Testing
```bash
# Verify services NOT accessible from internet
curl http://PUBLIC_IP:6379    # Should fail (Redis)
curl http://PUBLIC_IP:5432    # Should fail (Postgres)
curl http://PUBLIC_IP:9000    # Should fail (MinIO)

# Verify internal nodes CAN reach services
# From API node:
redis-cli -h 10.0.1.31 ping           # Should work
psql -h 10.0.1.41 -U tether -c 'SELECT 1'  # Should work
curl http://10.0.1.51:9000            # Should work
```

### Files
- `NETWORK_DIAGRAM.md` - Visual network architecture
- `firewall-rules.sh` - iptables configuration script
