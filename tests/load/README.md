# Load Testing

## Prerequisites

Install k6:
```bash
# macOS
brew install k6

# Ubuntu/Debian
sudo gpg -k
sudo gpg --no-default-keyring --keyring /usr/share/keyrings/k6-archive-keyring.gpg --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
echo "deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main" | sudo tee /etc/apt/sources.list.d/k6.list
sudo apt-get update
sudo apt-get install k6

# Docker
docker pull grafana/k6
```

## Running Tests

### Webhook Load Test (THR-167)
```bash
# Local testing
k6 run tests/load/webhook-load-test.js

# Against staging
k6 run -e BASE_URL=https://staging.tether.example.com -e API_PASSWORD=your_password tests/load/webhook-load-test.js

# With Docker
docker run -i grafana/k6 run - <tests/load/webhook-load-test.js
```

### Test Stages

1. Ramp to 50 RPS over 1 minute
2. Sustain 50 RPS for 3 minutes
3. Ramp to 100 RPS over 1 minute
4. Sustain 100 RPS for 3 minutes
5. Ramp down over 1 minute

**Total duration: ~10 minutes**

### Thresholds

| Metric | Target |
|--------|--------|
| p95 Response Time | < 100ms |
| p99 Response Time | < 200ms |
| Error Rate | < 1% |

## Results

Results are saved to `tests/load/results/` directory.

## Acceptance Criteria (THR-152)

- [ ] Webhook p95 response time under 100ms at 50 RPS
- [ ] Webhook p99 response time under 200ms at 100 RPS
- [ ] Zero errors during 10-minute sustained load test
- [ ] Queue workers keep up with incoming jobs
