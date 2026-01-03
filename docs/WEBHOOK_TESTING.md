# Webhook Testing Guide

Complete guide for testing emerchantpay webhook integrations in the Tether platform.

## Table of Contents

1. [emerchantpay Test Dashboard](#1-emerchantpay-test-dashboard-recommended)
2. [Manual Testing with curl](#2-manual-testing-with-curl)
3. [k6 Load Testing](#3-k6-load-testing)
4. [Webhook Testing Tools](#4-webhook-testing-tools)
5. [PHPUnit Tests](#5-phpunit-tests)
6. [Troubleshooting](#troubleshooting)

---

## 1. emerchantpay Test Dashboard (Recommended)

### Staging Credentials

The emerchantpay staging environment is configured in your `.env` file:

```bash
# emerchantpay Genesis API - STAGING
EMP_GENESIS_ENDPOINT=staging.gate.emerchantpay.net
EMP_GENESIS_USERNAME=32a174d6bdbe31b03a337b65900fe96271c9f4a8
EMP_GENESIS_PASSWORD=948d93cb68916cc893d234f549a825fa0c91c826
EMP_GENESIS_TERMINAL_TOKEN=585b1650138599b7861e129030c91fe0ecc79bd5
NEXT_PUBLIC_EMP_MERCHANT_PORTAL_URL=https://emp.staging.merchant.emerchantpay.net
```

### Access the Dashboard

#### Merchant Portal (Recommended)
1. **Login URL:** https://emp.staging.merchant.emerchantpay.net
2. **Username:** `admin-elariossodigitalltd-georgi`
3. **Password:** `F6YJYydQfk!3Yvw`

#### Genesis Gateway API Portal
1. **Login URL:** https://staging.gate.emerchantpay.net
2. **Credentials:** Use your `EMP_GENESIS_USERNAME` and `EMP_GENESIS_PASSWORD` from above

### Using the Test Dashboard

Once logged in, you can:

- **Simulate Transactions:** Create test SDD (SEPA Direct Debit) transactions
- **Trigger Webhooks:** Manually send webhook notifications to your endpoint
- **View Webhook Logs:** Monitor webhook delivery status and responses
- **Test Chargebacks:** Simulate chargeback scenarios
- **Retry Failed Webhooks:** Resend webhooks that failed delivery
- **View Transaction History:** See all test transactions and their statuses

### Configure Webhook URL

In the emerchantpay dashboard, configure your webhook notification URL:

**Local Development (with ngrok):**
```
https://your-id.ngrok.io/api/webhooks/emp
```

**Staging Server:**
```
https://staging.yourdomain.com/api/webhooks/emp
```

**Production:**
```
https://yourdomain.com/api/webhooks/emp
```

### Test Scenarios

Use the dashboard to simulate:

1. **Successful Payment**
   - Status: `approved`
   - Webhook should update billing_attempt status

2. **Declined Payment**
   - Status: `declined`
   - Error codes: `AM04` (Insufficient funds), etc.

3. **Pending Async**
   - Status: `pending_async`
   - For 3DS or redirect flows

4. **Chargeback**
   - Transaction type: `chargeback`
   - Codes: `AC04` (Account closed), `MD01` (No mandate)
   - Should trigger auto-blacklist for certain codes

---

## 2. Manual Testing with curl

### Prerequisites

```bash
# Set your credentials from .env (STAGING)
export EMP_PASSWORD="948d93cb68916cc893d234f549a825fa0c91c826"
export BASE_URL="http://localhost:8000"
```

### Test SDD Sale Webhook (Approved)

```bash
#!/bin/bash

# Generate unique transaction ID
UNIQUE_ID="tx_test_$(date +%s)"

# Generate signature: SHA1(unique_id + password)
SIGNATURE=$(echo -n "${UNIQUE_ID}${EMP_PASSWORD}" | shasum -a 1 | cut -d' ' -f1)

# Send webhook
curl -X POST "${BASE_URL}/api/webhooks/emp" \
  -H "Content-Type: application/json" \
  -d "{
    \"transaction_type\": \"sdd_sale\",
    \"unique_id\": \"${UNIQUE_ID}\",
    \"status\": \"approved\",
    \"amount\": 1500,
    \"currency\": \"EUR\",
    \"timestamp\": \"$(date -u +%Y-%m-%dT%H:%M:%SZ)\",
    \"signature\": \"${SIGNATURE}\"
  }"
```

**Expected Response:**
```json
{
  "status": "ok",
  "message": "Webhook processed"
}
```

### Test SDD Sale Webhook (Declined)

```bash
#!/bin/bash

UNIQUE_ID="tx_declined_$(date +%s)"
SIGNATURE=$(echo -n "${UNIQUE_ID}${EMP_PASSWORD}" | shasum -a 1 | cut -d' ' -f1)

curl -X POST "${BASE_URL}/api/webhooks/emp" \
  -H "Content-Type: application/json" \
  -d "{
    \"transaction_type\": \"sdd_sale\",
    \"unique_id\": \"${UNIQUE_ID}\",
    \"status\": \"declined\",
    \"amount\": 1500,
    \"currency\": \"EUR\",
    \"code\": \"AM04\",
    \"message\": \"Insufficient funds\",
    \"timestamp\": \"$(date -u +%Y-%m-%dT%H:%M:%SZ)\",
    \"signature\": \"${SIGNATURE}\"
  }"
```

### Test Chargeback Webhook

```bash
#!/bin/bash

# First, create an original transaction
ORIGINAL_TX_ID="tx_original_$(date +%s)"

# Now create chargeback
CHARGEBACK_ID="cb_$(date +%s)"
SIGNATURE=$(echo -n "${CHARGEBACK_ID}${EMP_PASSWORD}" | shasum -a 1 | cut -d' ' -f1)

curl -X POST "${BASE_URL}/api/webhooks/emp" \
  -H "Content-Type: application/json" \
  -d "{
    \"transaction_type\": \"chargeback\",
    \"unique_id\": \"${CHARGEBACK_ID}\",
    \"original_transaction_unique_id\": \"${ORIGINAL_TX_ID}\",
    \"status\": \"approved\",
    \"amount\": 1500,
    \"currency\": \"EUR\",
    \"reason\": \"Account closed\",
    \"reason_code\": \"AC04\",
    \"timestamp\": \"$(date -u +%Y-%m-%dT%H:%M:%SZ)\",
    \"signature\": \"${SIGNATURE}\"
  }"
```

**Expected Behavior:**
- Updates billing_attempt to `chargebacked`
- Auto-blacklists IBAN if code is `AC04`, `AC06`, `AG01`, or `MD01`

### Test Invalid Signature (Should Fail)

```bash
curl -X POST "${BASE_URL}/api/webhooks/emp" \
  -H "Content-Type: application/json" \
  -d "{
    \"transaction_type\": \"sdd_sale\",
    \"unique_id\": \"tx_invalid_$(date +%s)\",
    \"status\": \"approved\",
    \"amount\": 1500,
    \"currency\": \"EUR\",
    \"signature\": \"invalid_signature_12345\"
  }"
```

**Expected Response (401):**
```json
{
  "error": "Invalid signature",
  "status": "error"
}
```

### Test Duplicate Webhook (Idempotency)

```bash
#!/bin/bash

UNIQUE_ID="tx_duplicate_$(date +%s)"
SIGNATURE=$(echo -n "${UNIQUE_ID}${EMP_PASSWORD}" | shasum -a 1 | cut -d' ' -f1)

PAYLOAD="{
  \"transaction_type\": \"sdd_sale\",
  \"unique_id\": \"${UNIQUE_ID}\",
  \"status\": \"approved\",
  \"amount\": 1500,
  \"currency\": \"EUR\",
  \"signature\": \"${SIGNATURE}\"
}"

# Send first webhook
echo "First webhook:"
curl -X POST "${BASE_URL}/api/webhooks/emp" \
  -H "Content-Type: application/json" \
  -d "$PAYLOAD"

echo -e "\n\nSecond webhook (duplicate):"
# Send duplicate immediately
curl -X POST "${BASE_URL}/api/webhooks/emp" \
  -H "Content-Type: application/json" \
  -d "$PAYLOAD"
```

**Expected:** Second webhook should return "Webhook already queued"

---

## 3. k6 Load Testing

### Installation

```bash
# macOS
brew install k6

# Linux
sudo gpg --no-default-keyring --keyring /usr/share/keyrings/k6-archive-keyring.gpg --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
echo "deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main" | sudo tee /etc/apt/sources.list.d/k6.list
sudo apt-get update
sudo apt-get install k6

# Windows
choco install k6
```

### Running the Load Test

The project includes a comprehensive k6 load test script at `tests/load/webhook-load-test.js`.

**Basic Usage:**
```bash
# Run with default settings (localhost:8000)
k6 run tests/load/webhook-load-test.js

# Run against staging
k6 run -e BASE_URL=https://staging.yourdomain.com \
       -e API_PASSWORD=948d93cb68916cc893d234f549a825fa0c91c826 \
       tests/load/webhook-load-test.js

# Run with custom stages (lighter load)
k6 run --stage 30s:10 --stage 1m:10 --stage 30s:0 \
       tests/load/webhook-load-test.js
```

### Load Test Features

The script includes:
- **Realistic Traffic:** 80% SDD Sale, 20% Chargeback
- **Auto-Generated Signatures:** SHA-1 validation built-in
- **Performance Thresholds:**
  - p95 < 100ms
  - p99 < 200ms
  - Error rate < 1%
- **Test Stages:**
  - Ramp to 50 RPS over 1 minute
  - Sustain 50 RPS for 3 minutes
  - Ramp to 100 RPS over 1 minute
  - Sustain 100 RPS for 3 minutes
  - Ramp down over 1 minute

### Expected Output

```
     ✓ status is 2xx or expected 4xx
     ✓ response time < 200ms
     ✓ has valid JSON response

     checks.........................: 100.00% ✓ 15000      ✗ 0
     data_received..................: 3.5 MB  12 kB/s
     data_sent......................: 15 MB   50 kB/s
     errors.........................: 0.00%   ✓ 0          ✗ 5000
     http_req_duration..............: avg=45ms    min=10ms med=42ms max=95ms  p(95)=78ms p(99)=89ms
     http_reqs......................: 5000    16.666667/s
     webhook_duration...............: avg=45ms    min=10ms med=42ms max=95ms  p(95)=78ms p(99)=89ms
```

---

## 4. Webhook Testing Tools

### ngrok (Recommended for Local Development)

Expose your local server to receive webhooks from emerchantpay staging:

```bash
# Install ngrok
brew install ngrok  # macOS
# or download from: https://ngrok.com/download

# Start ngrok tunnel
ngrok http 8000

# Output:
# Forwarding   https://abc123.ngrok.io -> http://localhost:8000
```

**Configure in emerchantpay:**
1. Copy the HTTPS URL (e.g., `https://abc123.ngrok.io`)
2. Add `/api/webhooks/emp` to the end
3. Set as webhook URL in emerchantpay dashboard: `https://abc123.ngrok.io/api/webhooks/emp`

**View Webhook Traffic:**
- Visit http://localhost:4040 to see all incoming requests
- Inspect headers, payloads, and responses

### webhook.site

Quick webhook inspection without installation:

1. Visit https://webhook.site
2. Copy your unique URL (e.g., `https://webhook.site/abc-123`)
3. Use as a temporary webhook endpoint to inspect payloads
4. Forward to your local server using the "Edit" feature

### Postman / Insomnia

Create a webhook collection with pre-configured requests:

**Postman Collection Example:**
```json
{
  "info": {
    "name": "EMP Webhooks",
    "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json"
  },
  "item": [
    {
      "name": "SDD Sale - Approved",
      "request": {
        "method": "POST",
        "header": [
          {
            "key": "Content-Type",
            "value": "application/json"
          }
        ],
        "body": {
          "mode": "raw",
          "raw": "{\n  \"transaction_type\": \"sdd_sale\",\n  \"unique_id\": \"{{$guid}}\",\n  \"status\": \"approved\",\n  \"amount\": 1500,\n  \"currency\": \"EUR\",\n  \"signature\": \"{{signature}}\"\n}"
        },
        "url": {
          "raw": "{{base_url}}/api/webhooks/emp",
          "host": ["{{base_url}}"],
          "path": ["api", "webhooks", "emp"]
        }
      }
    }
  ]
}
```

**Pre-request Script for Signature:**
```javascript
// Postman Pre-request Script
const crypto = require('crypto');
const uniqueId = pm.variables.replaceIn('{{$guid}}');
const password = pm.environment.get('EMP_PASSWORD');
const signature = crypto.createHash('sha1').update(uniqueId + password).digest('hex');

pm.variables.set('signature', signature);
```

---

## 5. PHPUnit Tests

### Running Webhook Tests

```bash
# Run all webhook tests
docker compose exec app php artisan test --filter=EmpWebhookTest

# Run specific test
docker compose exec app php artisan test --filter=EmpWebhookTest::test_valid_sdd_sale_webhook

# Run with coverage
docker compose exec app php artisan test --filter=EmpWebhookTest --coverage
```

### Available Test Cases

The test suite at `tests/Feature/Webhook/EmpWebhookTest.php` covers:

- ✅ Valid SDD sale webhook processing
- ✅ Valid chargeback webhook processing
- ✅ Invalid signature rejection
- ✅ Missing unique_id handling
- ✅ Unknown transaction type handling
- ✅ Duplicate webhook deduplication (idempotency)
- ✅ Webhook queue dispatching
- ✅ Auto-blacklist on chargeback codes

### Example Test Output

```
PASS  Tests\Feature\Webhook\EmpWebhookTest
✓ valid sdd sale webhook is processed successfully           0.15s
✓ valid chargeback webhook is processed successfully         0.12s
✓ webhook with invalid signature is rejected                 0.08s
✓ webhook with missing unique id returns 400                 0.05s
✓ webhook with unknown transaction type is ignored           0.06s
✓ duplicate webhook returns already queued message           0.11s
✓ webhook dispatches background job                          0.09s
✓ chargeback webhook triggers auto blacklist for ac04        0.14s

Tests:    8 passed (8 assertions)
Duration: 0.80s
```

---

## Troubleshooting

### Check Application Logs

```bash
# Follow logs in real-time
docker compose logs -f app

# Filter for webhook logs
docker compose logs app | grep "EMP webhook"

# View Laravel logs
tail -f storage/logs/laravel.log
```

### Common Issues

#### 1. Invalid Signature Error (401)

**Symptom:**
```json
{
  "error": "Invalid signature",
  "status": "error"
}
```

**Solution:**
- Verify `EMP_GENESIS_PASSWORD` in `.env` matches emerchantpay dashboard
- Ensure signature is SHA-1 hash of `unique_id + password`
- Check for extra whitespace in password

**Test Signature Locally:**
```bash
# Example with staging password
echo -n "tx_123948d93cb68916cc893d234f549a825fa0c91c826" | shasum -a 1
```

#### 2. Duplicate Webhook Message

**Symptom:**
```json
{
  "status": "ok",
  "message": "Webhook already queued"
}
```

**Explanation:** This is expected behavior! The webhook deduplication system prevents processing the same transaction twice within 1 hour.

**To Test Fresh:**
```bash
# Clear Redis cache
docker compose exec redis redis-cli FLUSHDB

# Or wait 1 hour, or use a new unique_id
```

#### 3. Missing unique_id Error (400)

**Symptom:**
```json
{
  "error": "Missing unique_id",
  "status": "error"
}
```

**Solution:** Ensure `unique_id` field is present in webhook payload.

#### 4. Queue Not Processing

**Symptom:** Webhooks return 200 but billing_attempts don't update.

**Solution:**
```bash
# Check queue worker is running
docker compose exec app php artisan queue:work

# Or use Horizon (if configured)
docker compose exec app php artisan horizon

# Check failed jobs
docker compose exec app php artisan queue:failed
```

#### 5. Webhook Not Reaching Server

**Symptom:** No logs in application, emerchantpay shows failed delivery.

**Solution:**
- **Local Development:** Ensure ngrok is running and URL is correct
- **Firewall:** Check server allows incoming HTTPS on port 443
- **DNS:** Verify domain resolves correctly
- **SSL Certificate:** Ensure valid HTTPS certificate (emerchantpay requires HTTPS)

**Test Connectivity:**
```bash
# From emerchantpay's perspective, test your webhook endpoint
curl -X POST https://your-domain.com/api/webhooks/emp \
  -H "Content-Type: application/json" \
  -d '{"transaction_type":"sdd_sale","unique_id":"test","signature":"test"}'
```

### Verify Webhook Configuration

**Check Route:**
```bash
docker compose exec app php artisan route:list | grep webhook
```

**Expected Output:**
```
POST  api/webhooks/emp ............. webhook.emp › Webhook\EmpWebhookController@handle
```

**Check Environment:**
```bash
docker compose exec app php artisan tinker
>>> config('services.emp.password')
=> "948d93cb68916cc893d234f549a825fa0c91c826"
>>> config('services.emp.endpoint')
=> "staging.gate.emerchantpay.net"
```

### Enable Debug Logging

Add to `.env`:
```bash
LOG_LEVEL=debug
```

This will log detailed webhook processing information:
- Received payload
- Signature verification steps
- Deduplication checks
- Job dispatch events

---

## Production Checklist

Before going live with webhooks:

- [ ] Configure production emerchantpay credentials in `.env`
- [ ] Update webhook URL in emerchantpay production dashboard
- [ ] Ensure HTTPS is enabled with valid SSL certificate
- [ ] Test all webhook scenarios (approved, declined, chargeback)
- [ ] Verify queue workers are running (`php artisan queue:work` or Horizon)
- [ ] Set up monitoring for failed webhooks
- [ ] Configure retry logic for failed jobs
- [ ] Test reconciliation system as backup
- [ ] Monitor logs for first 24 hours
- [ ] Verify deduplication is working (1-hour TTL)

---

## Related Documentation

- [API Documentation](API.md#webhooks) - Full webhook API spec
- [Architecture](ARCHITECTURE.md) - System design and webhook flow
- [EmpWebhookController.php](../app/Http/Controllers/Webhook/EmpWebhookController.php) - Webhook controller code
- [EmpWebhookService.php](../app/Services/Emp/EmpWebhookService.php) - Business logic
- [ProcessEmpWebhookJob.php](../app/Jobs/ProcessEmpWebhookJob.php) - Background job processor

---

## Support

For emerchantpay-specific issues:
- **Support:** support@emerchantpay.com
- **Documentation:** https://emerchantpay.com/docs/
- **Status Page:** https://status.emerchantpay.com/

For Tether platform issues:
- Check [Troubleshooting](#troubleshooting) section above
- Review logs: `docker compose logs -f app`
- Run tests: `php artisan test --filter=Webhook`
