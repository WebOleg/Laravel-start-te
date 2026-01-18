# VOP Verification Log Monitoring Guide

## Overview

This guide explains how to monitor and debug the VOP (Verification of Payee) process using Laravel logs.

## Log Locations

### Production/Staging
```bash
# Main application log
tail -f storage/logs/laravel.log

# If using Docker
docker compose exec app tail -f storage/logs/laravel.log

# Filter for VOP-related logs only
tail -f storage/logs/laravel.log | grep -i "vop\|iban"
```

### Real-time Monitoring
```bash
# Watch for specific services
tail -f storage/logs/laravel.log | grep "IbanApiService\|IbanBavService\|VopScoringService"

# Watch for errors only
tail -f storage/logs/laravel.log | grep -i "error\|warning"
```

---

## VOP Verification Flow with Logs

When you click "Verify VOP" button, here's the complete log flow you should see:

### 1. **Controller - Job Dispatch**
```
ProcessVopJob started
{
  "upload_id": 123
}
```

### 2. **Job - BAV Selection**
```
ProcessVopJob: BAV selection completed
{
  "upload_id": 123,
  "total_debtors": 500,
  "large_upload": false,
  "percentage": 10,
  "selected": 50,
  "daily_remaining": 50
}
```

### 3. **Job - Chunk Dispatch**
```
ProcessVopJob dispatched
{
  "upload_id": 123,
  "debtors": 500,
  "bav_selected": 50,
  "chunks": 10
}
```

### 4. **Chunk Processing Starts**
```
ProcessVopChunkJob started
{
  "upload_id": 123,
  "chunk": 0,
  "debtors": 50
}
```

### 5. **For Each Debtor - Scoring Service**
```
VopScoringService: Starting score calculation
{
  "debtor_id": 456,
  "iban": "DE46****3010",
  "bav_selected": true,
  "force_refresh": false
}
```

### 6. **IBAN API Service - Cache Check**
```
IbanApiService: verify() called
{
  "iban": "DE46****3010",
  "country": "DE",
  "bank_code": "50070010",
  "skip_local_cache": false,
  "mock_mode": true
}
```

#### 6a. **Cache Hit (Database)**
```
IbanApiService: Database cache HIT
{
  "iban": "DE46****3010",
  "bank": "Deutsche Bank"
}
```

#### 6b. **Cache Hit (Memory)**
```
IbanApiService: Memory cache HIT
{
  "iban": "DE46****3010",
  "bank": "Deutsche Bank"
}
```

#### 6c. **Cache Miss - API Call**
```
IbanApiService: Cache MISS - fetching data
{
  "iban": "DE46****3010",
  "will_use_mock": true
}

IbanApiService: Making API request
{
  "url": "https://api.iban.com/clients/api/v4/iban/",
  "iban": "DE46****3010",
  "mock_mode": true
}

IbanApiService: API response received
{
  "status": 200,
  "iban": "DE46****3010",
  "response_size": 1234
}

IbanApiService: API result parsed
{
  "iban": "DE46****3010",
  "success": true,
  "bank": "Deutsche Bank",
  "bic": "DEUTDEFF"
}

IbanApiService: Result cached
{
  "iban": "DE46****3010",
  "cache_ttl_seconds": 86400
}
```

### 7. **BAV Verification (if debtor.bav_selected = true)**
```
IbanBavService: verify() called
{
  "iban": "DE46****3010",
  "name": "John Doe",
  "country": "DE",
  "mock_mode": true
}
```

#### 7a. **Country Supported**
```
IbanBavService: Using MOCK response
{
  "iban": "DE46****3010"
}
```

#### 7b. **Country Not Supported**
```
IbanBavService: Country not supported for BAV
{
  "country": "PL",
  "iban": "PL10****9123",
  "supported_countries": ["AT", "BE", "CY", ...]
}
```

#### 7c. **Real BAV API Call**
```
IbanBavService: Making BAV API request
{
  "url": "https://api.iban.com/clients/api/verify/v3/",
  "iban": "DE46****3010",
  "name": "John Doe",
  "has_api_key": true
}

IbanBavService: BAV API response received
{
  "iban_masked": "DE46****3010",
  "status": 200,
  "success": true,
  "name_match": "yes",
  "valid": true,
  "bic": "DEUTDEFF"
}
```

### 8. **Scoring Complete**
```
VopScoringService: Score calculation complete
{
  "debtor_id": 456,
  "iban": "DE46****3010",
  "final_score": 100,
  "result": "verified",
  "bank": "Deutsche Bank",
  "bic": "DEUTDEFF",
  "sepa_sdd": true,
  "bav_verified": true,
  "name_match": "yes",
  "score_breakdown": {
    "iban_valid": 20,
    "bank_identified": 25,
    "sepa_sdd": 25,
    "country_supported": 15,
    "name_match": 15
  }
}

VopScoringService: VopLog created
{
  "vop_log_id": 789,
  "debtor_id": 456
}
```

### 9. **Chunk Complete**
```
ProcessVopChunkJob completed
{
  "upload_id": 123,
  "chunk": 0,
  "verified": 50,
  "bav_verified": 5,
  "failed": 0
}
```

### 10. **All Chunks Complete**
```
ProcessVopJob batch completed
{
  "upload_id": 123
}
```

---

## Monitoring Commands

### View VOP Process in Real-Time
```bash
# Follow all VOP-related logs
tail -f storage/logs/laravel.log | grep -E "ProcessVopJob|VopScoringService|IbanApiService|IbanBavService"

# More readable format with timestamps
tail -f storage/logs/laravel.log | grep -E "ProcessVopJob|VopScoringService" --line-buffered | sed 's/local.INFO:/\n==> INFO:/'
```

### Check for Errors
```bash
# VOP errors only
tail -f storage/logs/laravel.log | grep -i "error" | grep -iE "vop|iban"

# API call failures
tail -f storage/logs/laravel.log | grep "request failed\|connection failed\|API error"
```

### Monitor API Usage
```bash
# Count API calls (mock vs real)
grep "IbanApiService: Making API request" storage/logs/laravel.log | grep -c "mock_mode.*true"
grep "IbanApiService: Making API request" storage/logs/laravel.log | grep -c "mock_mode.*false"

# Count BAV calls
grep "IbanBavService: Making BAV API request" storage/logs/laravel.log | wc -l

# Cache hit rate
grep "IbanApiService: Database cache HIT" storage/logs/laravel.log | wc -l
grep "IbanApiService: Memory cache HIT" storage/logs/laravel.log | wc -l
grep "IbanApiService: Cache MISS" storage/logs/laravel.log | wc -l
```

### Monitor Score Distribution
```bash
# See final scores
grep "VopScoringService: Score calculation complete" storage/logs/laravel.log | grep -oP '"final_score":\K\d+'

# Count verification results
grep '"result":"verified"' storage/logs/laravel.log | wc -l
grep '"result":"likely_verified"' storage/logs/laravel.log | wc -l
grep '"result":"inconclusive"' storage/logs/laravel.log | wc -l
```

---

## Debugging Common Issues

### Issue: No logs appearing
**Symptom**: You click "Verify VOP" but see no logs

**Check**:
1. Log level is set correctly
   ```bash
   # In .env
   LOG_LEVEL=debug  # or info
   ```

2. Queue worker is running
   ```bash
   php artisan queue:work
   # or
   docker compose exec app php artisan queue:work
   ```

3. Check queue connection
   ```bash
   # In .env
   QUEUE_CONNECTION=redis  # Make sure Redis is running
   ```

**Debug**:
```bash
# Check if job was queued
php artisan queue:failed

# Check Redis queue
redis-cli -h 127.0.0.1 -p 6379 KEYS "*"
```

---

### Issue: "API Key is invalid" (Error 301)
**Symptom**:
```
IbanApiService: request failed
{
  "status": 401,
  "body": "{\"error\":\"API Key is invalid\"}"
}
```

**Solution**:
1. Check `.env` file:
   ```bash
   grep IBAN_API_KEY .env
   ```
2. Verify API key is correct
3. Clear config cache:
   ```bash
   php artisan config:clear
   ```

---

### Issue: Mock mode is enabled in production
**Symptom**:
```
IbanApiService: verify() called
{
  "mock_mode": true  ← Should be false in production!
}
```

**Solution**:
```bash
# Update .env
IBAN_API_MOCK=false

# Clear cache
php artisan config:clear

# Restart queue workers
php artisan queue:restart
```

---

### Issue: BAV not running
**Symptom**: Never see "IbanBavService" logs

**Check**:
1. BAV is enabled:
   ```bash
   grep BAV_ENABLED .env
   # Should be: BAV_ENABLED=true
   ```

2. Daily limit not reached:
   ```bash
   # Check logs for:
   grep "BAV daily limit reached" storage/logs/laravel.log
   ```

3. Debtors are being selected:
   ```bash
   grep "BAV selection completed" storage/logs/laravel.log
   # Should show: "selected": > 0
   ```

---

### Issue: All scores are the same
**Symptom**: Every debtor gets score 85

**Cause**: Mock mode generates predictable scores

**Solution**:
```bash
# Disable mock mode
IBAN_API_MOCK=false

# Add real API key
IBAN_API_KEY=your_real_key
```

---

## Performance Monitoring

### Track Processing Speed
```bash
# Time per chunk
grep "ProcessVopChunkJob started\|ProcessVopChunkJob completed" storage/logs/laravel.log

# Average cache hit rate
total_requests=$(grep "IbanApiService: verify() called" storage/logs/laravel.log | wc -l)
cache_hits=$(grep "cache HIT" storage/logs/laravel.log | wc -l)
echo "Cache hit rate: $(echo "scale=2; $cache_hits * 100 / $total_requests" | bc)%"
```

### Monitor Queue Depth
```bash
# Check queued jobs
php artisan queue:monitor vop

# Queue size
redis-cli LLEN queues:vop
```

---

## Log Levels

The application uses different log levels for different types of messages:

| Level | Usage | Example |
|-------|-------|---------|
| `INFO` | Normal operation | Score calculation, API calls, cache hits |
| `WARNING` | Non-critical issues | Country not supported, request retries |
| `ERROR` | Failures | API errors, connection failures, exceptions |
| `DEBUG` | Detailed debugging | (Not currently used in VOP services) |

Set log level in `.env`:
```env
LOG_LEVEL=info  # info, debug, warning, error
```

---

## Useful Laravel Artisan Commands

```bash
# Clear all caches
php artisan cache:clear
php artisan config:clear
php artisan view:clear

# Restart queue workers (picks up new code/config)
php artisan queue:restart

# View failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all

# Monitor queue in real-time
php artisan queue:monitor vop --max=100
```

---

## Example: Complete VOP Run Analysis

After running VOP verification on upload #123:

```bash
# How many debtors were processed?
grep -c '"upload_id":123' storage/logs/laravel.log

# How many were selected for BAV?
grep '"upload_id":123' storage/logs/laravel.log | grep "bav_selected" | head -1

# What was the score distribution?
grep '"upload_id":123.*final_score' storage/logs/laravel.log | grep -oP '"final_score":\K\d+' | sort -n | uniq -c

# Were there any errors?
grep '"upload_id":123' storage/logs/laravel.log | grep -i error

# Cache performance?
grep '"upload_id":123' storage/logs/laravel.log | grep -c "cache HIT"
grep '"upload_id":123' storage/logs/laravel.log | grep -c "cache MISS"

# Processing time?
grep '"upload_id":123' storage/logs/laravel.log | grep "ProcessVopJob started" | head -1
grep '"upload_id":123' storage/logs/laravel.log | grep "batch completed" | tail -1
```

---

## Best Practices

1. **Always monitor logs when testing** - Especially when switching from mock to real API

2. **Check cache hit rates** - Should be >70% for efficiency

3. **Watch for API errors** - Fix immediately to avoid wasted credits

4. **Monitor BAV usage** - Keep within daily limits

5. **Rotate logs regularly** - Large log files slow down grep/tail

6. **Use structured logging** - All logs include JSON context for easy parsing

---

## Now Click "Verify VOP" and Watch!

Open a terminal and run:
```bash
tail -f storage/logs/laravel.log | grep -E "ProcessVopJob|VopScoringService|IbanApiService|IbanBavService"
```

Then click the "Verify VOP" button in the UI and watch the logs flow! 🚀
