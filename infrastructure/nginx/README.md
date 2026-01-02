# Nginx Load Balancer

## THR-170: Provision load balancer

### Requirements Met
- ✅ Public IP with DNS (api.yourdomain.com)
- ✅ SSL certificate (Let's Encrypt)
- ✅ Health check to /health every 10 seconds
- ✅ Load balancing: least_conn
- ✅ Fail threshold: 3
- ✅ Timeout: 60 seconds

### Files
- `nginx.conf` - Main Nginx configuration
- `load-balancer.conf` - Site configuration with upstream, SSL, rate limiting
- `docker-compose.yml` - Docker setup with Certbot
- `ssl-renewal.sh` - SSL auto-renewal script

### Initial SSL Setup
```bash
# Create webroot directory
mkdir -p /var/www/certbot

# Get initial certificate
certbot certonly \
    --webroot \
    --webroot-path=/var/www/certbot \
    --email admin@yourdomain.com \
    --agree-tos \
    --no-eff-email \
    -d api.yourdomain.com
```

### Deployment
```bash
# Start load balancer
docker-compose up -d

# Verify configuration
docker exec tether-loadbalancer nginx -t

# Reload after config changes
docker exec tether-loadbalancer nginx -s reload
```

### Health Check
```bash
curl -k https://api.yourdomain.com/health
```

### Architecture
```
                    ┌─────────────────────┐
                    │   Load Balancer     │
    Internet ──────▶│   (Nginx + SSL)     │
                    │   least_conn        │
                    └──────────┬──────────┘
                               │
              ┌────────────────┼────────────────┐
              ▼                ▼                ▼
        ┌──────────┐    ┌──────────┐    ┌──────────┐
        │ API Node │    │ API Node │    │ API Node │
        │    1     │    │    2     │    │   ...    │
        └──────────┘    └──────────┘    └──────────┘
```

### Rate Limits
- API endpoints: 100 req/s per IP (burst 20)
- Webhook endpoints: 200 req/s per IP (burst 50)
