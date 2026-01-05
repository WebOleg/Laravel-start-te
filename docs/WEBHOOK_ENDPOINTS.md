# Webhook Endpoints Guide

Your Tether Laravel application now supports **TWO** webhook endpoints for maximum flexibility:

## Table of Contents

1. [Endpoint Comparison](#endpoint-comparison)
2. [Laravel Endpoint (Recommended)](#1-laravel-webhook-endpoint-recommended)
3. [Node.js-Compatible Endpoint](#2-nodejs-compatible-endpoint)
4. [Which Endpoint Should I Use?](#which-endpoint-should-i-use)
5. [Configuring emerchantpay](#configuring-emerchantpay)
6. [Notification System](#notification-system)

---

## Endpoint Comparison

| Feature | Laravel Endpoint | Node.js-Compatible Endpoint |
|---------|------------------|----------------------------|
| **URL** | `/api/webhooks/emp` | `/api/emp/notifications/emerchantpay` |
| **Response Format** | JSON | XML |
| **transaction_type Required** | Yes (`sdd_sale`, `chargeback`) | No |
| **unique_id Field** | `unique_id` only | Both `unique_id` and `uniqueId` |
| **Status Mapping** | Full (approved, declined, error, voided, pending, pending_async) | Simple (approved, error, submitted) |
| **Chargeback Support** | Full (with auto-blacklist) | Basic (status update only) |
| **Use Case** | Full-featured webhook processing | Simple status updates, Node.js compatibility |

---

## 1. Laravel Webhook Endpoint (Recommended)

### URL
```
POST /api/webhooks/emp
```

### Full URLs
- **Local**: `http://localhost:8000/api/webhooks/emp`
- **Production**: `https://yourdomain.com/api/webhooks/emp`

### Request Format

**Headers:**
```
Content-Type: application/json
# or
Content-Type: application/x-www-form-urlencoded
```

**Required Fields:**
- `transaction_type` - Type of transaction (`sdd_sale` or `chargeback`)
- `unique_id` - Transaction unique identifier
- `status` - Transaction status
- `signature` - SHA-1 signature for verification

**Example Request (SDD Sale):**
```json
{
  "transaction_type": "sdd_sale",
  "unique_id": "tx_12345",
  "status": "approved",
  "amount": 1500,
  "currency": "EUR",
  "signature": "abc123..."
}
```

**Example Request (Chargeback):**
```json
{
  "transaction_type": "chargeback",
  "unique_id": "cb_67890",
  "original_transaction_unique_id": "tx_12345",
  "status": "approved",
  "amount": 1500,
  "currency": "EUR",
  "reason": "Account closed",
  "reason_code": "AC04",
  "signature": "def456..."
}
```

### Response Format

**Success Response (JSON):**
```json
{
  "status": "ok",
  "message": "Webhook processed"
}
```

**Error Response (JSON):**
```json
{
  "error": "Invalid signature",
  "status": "error"
}
```

### Status Codes
- `200` - Success
- `400` - Missing fields or unknown transaction type
- `401` - Invalid signature
- `500` - Internal server error

### Features
✅ Full transaction type support (sdd_sale, chargeback)
✅ Comprehensive status mapping
✅ Auto-blacklisting on chargebacks (AC04, AC06, AG01, MD01)
✅ Idempotency protection
✅ Async job processing
✅ Full notification support

### curl Example

```bash
#!/bin/bash

export EMP_PASSWORD="948d93cb68916cc893d234f549a825fa0c91c826"
UNIQUE_ID="tx_test_$(date +%s)"
SIGNATURE=$(echo -n "${UNIQUE_ID}${EMP_PASSWORD}" | shasum -a 1 | cut -d' ' -f1)

curl -X POST "http://localhost:8000/api/webhooks/emp" \
  -H "Content-Type: application/json" \
  -d "{
    \"transaction_type\": \"sdd_sale\",
    \"unique_id\": \"${UNIQUE_ID}\",
    \"status\": \"approved\",
    \"amount\": 1500,
    \"currency\": \"EUR\",
    \"signature\": \"${SIGNATURE}\"
  }"
```

---

## 2. Node.js-Compatible Endpoint

### URL
```
POST /api/emp/notifications/emerchantpay
```

### Full URLs
- **Local**: `http://localhost:8000/api/emp/notifications/emerchantpay`
- **Production**: `https://yourdomain.com/api/emp/notifications/emerchantpay`

### Request Format

**Headers:**
```
Content-Type: application/json
# or
Content-Type: application/x-www-form-urlencoded
```

**Required Fields:**
- `unique_id` OR `uniqueId` - Transaction unique identifier
- `status` - Transaction status (`approved`, `error`, etc.)
- `signature` - SHA-1 signature for verification

**Optional Fields:**
- `message` - Error or status message

**Example Request:**
```json
{
  "unique_id": "tx_12345",
  "status": "approved",
  "message": "Transaction successful",
  "signature": "abc123..."
}
```

**Alternative with camelCase:**
```json
{
  "uniqueId": "tx_12345",
  "status": "approved",
  "signature": "abc123..."
}
```

### Response Format

**Success Response (XML):**
```xml
<?xml version="1.0" encoding="UTF-8"?>
<notification_echo>
  <unique_id>tx_12345</unique_id>
</notification_echo>
```

**Error Response (JSON):**
```json
{
  "error": "Invalid signature"
}
```

### Status Codes
- `200` - Success (returns XML)
- `400` - Missing fields
- `401` - Invalid signature
- `500` - Internal server error

### Status Mapping

| Input Status | Mapped To | Notes |
|--------------|-----------|-------|
| `approved` | `approved` | Success |
| `error` | `error` | Failure |
| `declined` | `error` | Treated as error |
| `pending` | `pending` | Awaiting confirmation |
| `pending_async` | `pending` | 3DS/redirect flow |
| `submitted` | `pending` | Default fallback |
| Other | `null` | Ignored |

### Features
✅ Simple status updates
✅ XML response (Node.js compatible)
✅ Accepts both `unique_id` and `uniqueId`
✅ Basic notification support
⚠️ No chargeback processing
⚠️ No auto-blacklisting

### curl Example

```bash
#!/bin/bash

export EMP_PASSWORD="948d93cb68916cc893d234f549a825fa0c91c826"
UNIQUE_ID="tx_test_$(date +%s)"
SIGNATURE=$(echo -n "${UNIQUE_ID}${EMP_PASSWORD}" | shasum -a 1 | cut -d' ' -f1)

curl -X POST "http://localhost:8000/api/emp/notifications/emerchantpay" \
  -H "Content-Type: application/json" \
  -d "{
    \"unique_id\": \"${UNIQUE_ID}\",
    \"status\": \"approved\",
    \"signature\": \"${SIGNATURE}\"
  }"
```

**Expected Response:**
```xml
<?xml version="1.0" encoding="UTF-8"?>
<notification_echo>
  <unique_id>tx_test_1735987654</unique_id>
</notification_echo>
```

---

## Which Endpoint Should I Use?

### Use Laravel Endpoint (`/api/webhooks/emp`) if:
- ✅ You need full webhook functionality
- ✅ You want chargeback processing with auto-blacklisting
- ✅ You need comprehensive status tracking
- ✅ You prefer JSON responses
- ✅ This is a new implementation

### Use Node.js-Compatible Endpoint (`/api/emp/notifications/emerchantpay`) if:
- ✅ You're migrating from a Node.js application
- ✅ You need XML responses for compatibility
- ✅ You only need basic status updates
- ✅ Your frontend expects the Node.js format
- ✅ You want to support both `unique_id` and `uniqueId`

**Recommendation:** Use the **Laravel endpoint** for new implementations. It provides full functionality and follows Laravel best practices.

---

## Configuring emerchantpay

### emerchantpay Dashboard Setup

1. Login to emerchantpay:
   - **Staging**: https://emp.staging.merchant.emerchantpay.net
   - **Production**: https://emp.merchant.emerchantpay.net

2. Navigate to: **Settings → Notifications → Webhook URL**

3. Configure your webhook URL:

   **For Laravel Endpoint:**
   ```
   https://yourdomain.com/api/webhooks/emp
   ```

   **For Node.js-Compatible Endpoint:**
   ```
   https://yourdomain.com/api/emp/notifications/emerchantpay
   ```

4. Select notification events:
   - ✅ Transaction Status Updates
   - ✅ Chargebacks (Laravel endpoint only)
   - ✅ Refunds

5. Save configuration

### Local Development with ngrok

```bash
# Start your Laravel app
php artisan serve  # Runs on localhost:8000

# In another terminal, start ngrok
ngrok http 8000

# Copy the HTTPS URL (e.g., https://abc123.ngrok.io)
# Configure in emerchantpay:
#   Laravel: https://abc123.ngrok.io/api/webhooks/emp
#   Node.js: https://abc123.ngrok.io/api/emp/notifications/emerchantpay

# View webhook traffic at:
http://localhost:4040
```

---

## Notification System

Both webhook endpoints support notifications when enabled. Notifications can be sent via:
- Email
- Slack
- Custom webhook URL

### Enable Notifications

Add to your `.env` file:

```bash
# Enable webhook notifications
WEBHOOK_NOTIFICATIONS_ENABLED=true

# Configure what events to notify about
WEBHOOK_NOTIFY_APPROVED=false      # Don't spam on every approval
WEBHOOK_NOTIFY_DECLINED=true       # Notify on declined transactions
WEBHOOK_NOTIFY_CHARGEBACK=true     # Always notify on chargebacks
WEBHOOK_NOTIFY_ERROR=true          # Notify on errors

# Slack notifications
SLACK_WEBHOOK_URL=https://hooks.slack.com/services/YOUR/WEBHOOK/URL
SLACK_CHANNEL=#payments
SLACK_USERNAME="Tether Webhooks"

# Email notifications
WEBHOOK_EMAIL_RECIPIENTS=admin@example.com,finance@example.com

# Custom webhook forwarding (optional)
WEBHOOK_CUSTOM_NOTIFICATION_URL=https://your-monitoring-tool.com/webhook
```

### Slack Notification Example

When a chargeback is received, you'll get a Slack message like:

```
🚨 Chargeback Received

Chargeback ID: cb_12345
Original Transaction: tx_67890
Amount: 15.00 EUR
Reason Code: AC04
Reason: Account closed

Billing Attempt ID: 42
Debtor ID: 123
```

### Email Notification Example

```
Subject: [high] Chargeback Received

A chargeback has been received for transaction tx_67890.

Reason: Account closed (AC04)
Amount: 15.00 EUR
Chargeback ID: cb_12345
```

### Custom Webhook Forwarding

If you set `WEBHOOK_CUSTOM_NOTIFICATION_URL`, all webhook events will be forwarded to that URL with this payload:

```json
{
  "title": "Chargeback Received",
  "body": "A chargeback has been received...",
  "type": "chargeback",
  "fields": [
    {"title": "Chargeback ID", "value": "cb_12345", "short": true},
    {"title": "Amount", "value": "15.00 EUR", "short": true}
  ],
  "data": {
    "unique_id": "cb_12345",
    "original_transaction_unique_id": "tx_67890",
    ...
  }
}
```

---

## Testing Both Endpoints

Use the comprehensive test script:

```bash
chmod +x tests/webhooks/test-emp-webhooks.sh
./tests/webhooks/test-emp-webhooks.sh
```

This script tests:
- ✅ Both webhook endpoints
- ✅ All status types
- ✅ Signature verification
- ✅ Error handling
- ✅ Idempotency

---

## Signature Verification

Both endpoints use the same signature algorithm:

```
signature = SHA1(unique_id + password)
```

**Example (Bash):**
```bash
UNIQUE_ID="tx_12345"
PASSWORD="948d93cb68916cc893d234f549a825fa0c91c826"
SIGNATURE=$(echo -n "${UNIQUE_ID}${PASSWORD}" | shasum -a 1 | cut -d' ' -f1)
echo $SIGNATURE
```

**Example (PHP):**
```php
$uniqueId = 'tx_12345';
$password = config('services.emp.password');
$signature = hash('sha1', $uniqueId . $password);
```

**Example (Node.js):**
```javascript
const crypto = require('crypto');
const uniqueId = 'tx_12345';
const password = '948d93cb68916cc893d234f549a825fa0c91c826';
const signature = crypto.createHash('sha1')
    .update(uniqueId + password)
    .digest('hex');
```

---

## Related Documentation

- [WEBHOOK_TESTING.md](WEBHOOK_TESTING.md) - Complete testing guide
- [WEBHOOK_NOTIFICATIONS.md](WEBHOOK_NOTIFICATIONS.md) - Notification setup guide
- [API.md](API.md) - Full API documentation
- [ARCHITECTURE.md](ARCHITECTURE.md) - System architecture

---

## Support

For issues:
- **Laravel endpoint**: Check `storage/logs/laravel.log`
- **Node.js endpoint**: Check `storage/logs/laravel.log` (same logging)
- **emerchantpay**: support@emerchantpay.com

Enable debug logging:
```bash
LOG_LEVEL=debug
```

Monitor webhooks:
```bash
tail -f storage/logs/laravel.log | grep "webhook"
```
