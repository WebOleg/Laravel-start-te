# Tether Laravel - Complete Testing Guide

## Overview
This guide covers the complete end-to-end testing flow for the Tether debt recovery platform, from file upload through payment processing and webhook handling.

---

## Complete Business Flow

```
1. FILE UPLOAD
   ↓
   Upload Controller → store()
   ├─ Validates file format/size
   ├─ Async? (>100 records)
   │  └─ ProcessUploadJob → ProcessUploadChunkJob (parallel)
   └─ ProcessUploadChunkJob (direct for small files)
      ├─ Parse CSV/Excel
      ├─ Map columns to Debtor fields
      ├─ Validate IBAN (normalize, hash)
      ├─ Deduplication check (blacklist, chargebacked, recovered)
      └─ Create Debtor (validation_status = 'pending')

2. VALIDATION
   ↓
   POST /admin/uploads/{id}/validate
   └─ DebtorValidationService::validateUpload()
      ├─ Check required fields
      ├─ Validate name, IBAN, amount, country
      ├─ Check for encoding issues
      ├─ Check blacklist (IBAN, name, email)
      └─ Update validation_status (valid/invalid)

3. VOP VERIFICATION (REQUIRED GATE)
   ↓
   POST /admin/uploads/{id}/verify-vop
   └─ ProcessVopJob → ProcessVopChunkJob (parallel)
      ├─ Select: validation_status='valid', iban_valid=true
      ├─ Check VopLog cache (IBAN hash)
      ├─ If miss → VopScoringService → IbanApiService
      └─ Create VopLog with score & result

4. BILLING SYNC (TRANSACTION CREATION) ⚡
   ↓
   POST /admin/uploads/{id}/sync
   ├─ Check VOP completed (100% for eligible debtors)
   ├─ Count eligible debtors
   └─ ProcessBillingJob → ProcessBillingChunkJob (parallel, rate-limited)
      ├─ Select: validation_status='valid', status='pending'
      ├─ EmpBillingService::billDebtor()
      │  ├─ Build SDD request
      │  ├─ Call EmpClient::sddSale()
      │  └─ Create BillingAttempt (status='pending')
      ├─ Update BillingAttempt with response
      └─ Update Debtor status (recovered if approved, pending if error)

5. PAYMENT PROCESSING (External)
   ↓
   EmerchantPay Gateway
   ├─ Validates IBAN via SEPA clearinghouse
   ├─ Submits SDD mandate to issuing bank
   ├─ Receives authorization (approved/declined)
   └─ Sends async webhooks

6. WEBHOOK NOTIFICATIONS
   ↓
   POST /api/webhooks/emp
   ├─ Validate signature
   ├─ Dedup check
   └─ ProcessEmpWebhookJob (async)
      ├─ If sdd_sale: Update BillingAttempt status
      └─ If chargeback:
         ├─ Update status → 'chargebacked'
         ├─ Auto-blacklist if reason_code matches
         └─ Store chargeback metadata

7. RECONCILIATION (For stale pending)
   ↓
   POST /admin/uploads/{id}/reconcile
   └─ ProcessReconciliationJob → ProcessReconciliationChunkJob
      ├─ Find: status='pending', unique_id set, age>2hrs
      ├─ EmpBillingService::reconcile()
      │  └─ Query EMP for current status
      └─ Update BillingAttempt if status changed
```

---

## Testing Checklist

### Phase 1: Import & Validation ✅

**1.1 File Upload**
- [x] Upload CSV/XLSX file
- [x] Verify debtors created in database
- [x] Check `uploads.status = 'completed'`
- [x] Verify `debtors.validation_status = 'pending'`

```bash
# Upload file
POST /admin/uploads
Content-Type: multipart/form-data
file: debtors.csv

# Check database
SELECT id, validation_status, iban, first_name, last_name, amount
FROM debtors
WHERE upload_id = {upload_id};
```

**1.2 Validation**
- [ ] Trigger validation endpoint
- [ ] Check valid debtors: `validation_status = 'valid'`
- [ ] Check invalid debtors: `validation_status = 'invalid'`, `validation_errors` populated
- [ ] Verify IBAN format validation
- [ ] Verify amount validation (€1.00 - €50,000)
- [ ] Verify blacklist check

```bash
# Trigger validation
POST /admin/uploads/{upload_id}/validate

# Expected results
SELECT
  id,
  validation_status,
  validation_errors,
  iban_valid,
  validated_at
FROM debtors
WHERE upload_id = {upload_id};

# Should see:
# - validation_status: 'valid' or 'invalid'
# - validation_errors: [] for valid, ['error1', 'error2'] for invalid
# - iban_valid: true/false
# - validated_at: timestamp
```

---

### Phase 2: VOP Verification (REQUIRED GATE)

**2.1 VOP Initiation**
- [ ] Trigger VOP verification
- [ ] Monitor queue: `vop` queue processing
- [ ] Check `vop_logs` table populated
- [ ] Verify VOP results distribution

```bash
# Trigger VOP
POST /admin/uploads/{upload_id}/verify-vop

# Monitor queue
docker-compose exec app php artisan queue:work --queue=vop --verbose

# Check results
SELECT
  v.debtor_id,
  v.result,
  v.vop_score,
  v.bank_name,
  v.bic,
  v.iban_valid,
  d.iban,
  d.first_name,
  d.last_name
FROM vop_logs v
JOIN debtors d ON v.debtor_id = d.id
WHERE v.upload_id = {upload_id};
```

**2.2 VOP Results**
Expected results:
- `verified`: Name matches exactly (score 80-100)
- `likely_verified`: High confidence match (score 50-79)
- `inconclusive`: Unable to determine (score 20-49)
- `mismatch`: Name doesn't match (score 10-19)
- `rejected`: Name clearly doesn't match (score 0-9)

**2.3 VOP Gate Check**
- [ ] Verify 100% VOP completion before billing
- [ ] Test billing blocked if VOP incomplete

```bash
# Attempt billing before VOP complete (should fail)
POST /admin/uploads/{upload_id}/sync
# Expected: Error "VOP verification not complete"

# Check VOP progress
SELECT
  COUNT(*) as total_eligible,
  COUNT(vl.id) as vop_completed
FROM debtors d
LEFT JOIN vop_logs vl ON d.id = vl.debtor_id
WHERE d.upload_id = {upload_id}
  AND d.validation_status = 'valid'
  AND d.iban_valid = true;
```

---

### Phase 3: Transaction Initiation (BILLING SYNC) ⚡

**3.1 Billing Initiation**
- [ ] Trigger billing sync
- [ ] Monitor queue: `billing` queue processing
- [ ] Check `billing_attempts` created
- [ ] Verify rate limiting (50 req/sec)

```bash
# Trigger billing
POST /admin/uploads/{upload_id}/sync

# Monitor queue
docker-compose exec app php artisan queue:work --queue=billing --verbose

# Check billing attempts created
SELECT
  ba.id,
  ba.debtor_id,
  ba.transaction_id,
  ba.unique_id,
  ba.status,
  ba.amount,
  ba.attempt_number,
  ba.error_code,
  ba.error_message,
  d.iban,
  d.first_name,
  d.last_name
FROM billing_attempts ba
JOIN debtors d ON ba.debtor_id = d.id
WHERE ba.upload_id = {upload_id};
```

**3.2 Billing Results**
Expected statuses:
- `pending`: Waiting for webhook or processing
- `approved`: Successfully authorized
- `declined`: Rejected by bank
- `error`: Technical/API error
- `pending_async`: Async processing by gateway

**3.3 Transaction ID Format**
```
transaction_id: tether_{debtor_id}_{date}_{random}
Example: tether_12345_20260105_abc123
```

**3.4 Request Payload Verification**
- [ ] Check `billing_attempts.request_payload` contains:
  - `transaction_id`
  - `amount`, `currency` (EUR)
  - `iban`, `bic`
  - `first_name`, `last_name`
  - `email`
  - `notification_url` (webhook endpoint)
  - `usage` (payment description)

```bash
# Check request payload
SELECT
  id,
  transaction_id,
  request_payload,
  response_payload
FROM billing_attempts
WHERE upload_id = {upload_id}
LIMIT 1;
```

---

### Phase 4: Webhook Testing

**4.1 sdd_sale Webhook (Transaction Status)**

Test payload:
```json
{
  "notification_type": "sdd_sale",
  "unique_id": "abc123xyz789",
  "transaction_id": "tether_12345_20260105_abc123",
  "status": "approved",
  "amount": 12345,
  "currency": "EUR",
  "signature": "<sha1(unique_id + api_password)>"
}
```

Test cases:
- [ ] **Valid signature** - should process
- [ ] **Invalid signature** - should reject (401)
- [ ] **Duplicate webhook** - should deduplicate (use cache)
- [ ] **Status: approved** - updates `billing_attempts.status = 'approved'`, `debtors.status = 'recovered'`
- [ ] **Status: declined** - updates `billing_attempts.status = 'declined'`
- [ ] **Status: error** - updates `billing_attempts.status = 'error'`

```bash
# Send webhook
curl -X POST http://localhost:8000/api/webhooks/emp \
  -H "Content-Type: application/json" \
  -d '{
    "notification_type": "sdd_sale",
    "unique_id": "YOUR_UNIQUE_ID",
    "transaction_id": "YOUR_TRANSACTION_ID",
    "status": "approved",
    "amount": 12345,
    "currency": "EUR",
    "signature": "CALCULATED_SHA1_HASH"
  }'

# Verify update
SELECT status, processed_at, response_payload
FROM billing_attempts
WHERE unique_id = 'YOUR_UNIQUE_ID';
```

**4.2 Chargeback Webhook**

Test payload:
```json
{
  "notification_type": "chargeback",
  "original_transaction_unique_id": "abc123xyz789",
  "unique_id": "chargeback_xyz789",
  "amount": 12345,
  "currency": "EUR",
  "reason": "Incorrect account number",
  "reason_code": "AC01",
  "signature": "<sha1(unique_id + api_password)>"
}
```

Test cases:
- [ ] **Chargeback received** - updates `billing_attempts.status = 'chargebacked'`
- [ ] **Auto-blacklist** - creates entry in `blacklists` table
- [ ] **Blacklist fields** - IBAN hash, name, email
- [ ] **Metadata stored** - `billing_attempts.meta.chargeback` populated

```bash
# Send chargeback webhook
curl -X POST http://localhost:8000/api/webhooks/emp \
  -H "Content-Type: application/json" \
  -d '{
    "notification_type": "chargeback",
    "original_transaction_unique_id": "YOUR_UNIQUE_ID",
    "unique_id": "chargeback_12345",
    "amount": 12345,
    "currency": "EUR",
    "reason": "Incorrect account number",
    "reason_code": "AC01",
    "signature": "CALCULATED_SHA1_HASH"
  }'

# Verify chargeback
SELECT
  ba.status,
  ba.meta,
  bl.iban,
  bl.first_name,
  bl.last_name,
  bl.reason,
  bl.source
FROM billing_attempts ba
LEFT JOIN blacklists bl ON bl.iban_hash = (
  SELECT iban_hash FROM debtors WHERE id = ba.debtor_id
)
WHERE ba.unique_id = 'YOUR_UNIQUE_ID';

# Expected:
# - ba.status = 'chargebacked'
# - ba.meta contains chargeback data
# - bl row exists with source = 'chargeback'
```

**4.3 Signature Calculation**

```php
// PHP
$signature = sha1($unique_id . env('EMP_API_PASSWORD'));

// Bash
echo -n "abc123xyz789YOUR_API_PASSWORD" | sha1sum
```

**4.4 Webhook Deduplication**
- [ ] Send same webhook twice within 1 hour
- [ ] Second request should be ignored (cached)
- [ ] Check logs for "Duplicate webhook detected"

---

### Phase 5: Edge Cases & Security

**5.1 Invalid Webhook Signature**
```bash
# Send with incorrect signature
curl -X POST http://localhost:8000/api/webhooks/emp \
  -H "Content-Type: application/json" \
  -d '{
    "notification_type": "sdd_sale",
    "unique_id": "test123",
    "status": "approved",
    "signature": "invalid_signature"
  }'

# Expected: 401 Unauthorized
```

**5.2 Blacklist Prevention**
```bash
# 1. Chargeback a debtor (creates blacklist entry)
# 2. Re-upload same debtor in new file
POST /admin/uploads (with chargebacked debtor)

# 3. Check debtor skipped
SELECT * FROM debtors WHERE iban_hash = 'CHARGEBACKED_IBAN_HASH';
# Should not exist or have validation_status = 'invalid'

# 4. Check upload meta
SELECT meta FROM uploads WHERE id = {new_upload_id};
# Should show skipped count
```

**5.3 Rate Limiting**
- [ ] Billing sync respects 50 requests/second
- [ ] Monitor `ProcessBillingChunkJob` with `--verbose`
- [ ] Check for rate limit delays in logs

**5.4 Circuit Breaker**
- [ ] Simulate 10 consecutive API failures
- [ ] Verify circuit opens (5-min timeout)
- [ ] Check error: "Circuit breaker is open"

---

### Phase 6: Reconciliation

**6.1 Manual Reconciliation**
```bash
# Set up: Create old pending transaction
UPDATE billing_attempts
SET status = 'pending', created_at = NOW() - INTERVAL '3 hours'
WHERE id = {billing_attempt_id};

# Trigger reconciliation
POST /admin/uploads/{upload_id}/reconcile

# Or bulk reconciliation
POST /admin/reconciliation/bulk

# Check updated status
SELECT
  id,
  status,
  last_reconciled_at,
  reconciliation_attempts,
  response_payload
FROM billing_attempts
WHERE id = {billing_attempt_id};
```

**6.2 Reconciliation Criteria**
Eligible for reconciliation:
- `status = 'pending'`
- `unique_id` is set (has EMP transaction ID)
- Created ≥ 2 hours ago
- `reconciliation_attempts < 10`

---

## PHPUnit Test Suite

**Run all tests**
```bash
docker-compose exec app php artisan test
```

**Test by category**
```bash
# Webhook tests
docker-compose exec app php artisan test --filter=EmpWebhookTest

# Billing tests
docker-compose exec app php artisan test --filter=BillingTest

# Validation tests
docker-compose exec app php artisan test --filter=ValidationTest

# Blacklist tests
docker-compose exec app php artisan test --filter=BlacklistTest

# VOP tests
docker-compose exec app php artisan test --filter=VopTest
```

---

## Complete E2E Test Flow

```bash
# 1. Upload file
POST /admin/uploads
→ File: debtors.csv
→ Check: uploads.status = 'completed'
→ Check: debtors created with validation_status = 'pending'

# 2. Validate debtors
POST /admin/uploads/{id}/validate
→ Check: debtors.validation_status = 'valid'/'invalid'
→ Check: validation_errors populated for invalid

# 3. VOP verification
POST /admin/uploads/{id}/verify-vop
→ Check: vop_logs created
→ Check: result in ['verified', 'likely_verified', 'inconclusive', 'mismatch', 'rejected']
→ Check: 100% completion before next step

# 4. Billing sync (TRANSACTION CREATION)
POST /admin/uploads/{id}/sync
→ Check: billing_attempts created with status = 'pending'
→ Check: transaction_id, unique_id populated
→ Check: request_payload has IBAN, amount, notification_url

# 5. Webhook: approved
POST /api/webhooks/emp
Body: {
  "notification_type": "sdd_sale",
  "unique_id": "<from billing_attempt>",
  "status": "approved",
  "signature": "<sha1(unique_id + api_password)>"
}
→ Check: billing_attempts.status = 'approved'
→ Check: debtors.status = 'recovered'
→ Check: debtors.recovered_at timestamp set

# 6. Webhook: chargeback
POST /api/webhooks/emp
Body: {
  "notification_type": "chargeback",
  "original_transaction_unique_id": "<unique_id>",
  "reason_code": "AC01",
  "signature": "<calculated>"
}
→ Check: billing_attempts.status = 'chargebacked'
→ Check: blacklists table has new entry
→ Check: meta.chargeback populated

# 7. Re-upload chargebacked debtor
POST /admin/uploads (with same IBAN)
→ Check: Debtor skipped during import (deduplication)
→ Check: uploads.meta shows skipped count

# 8. Reconciliation
# Manually set: billing_attempts.status = 'pending', created_at = 3 hours ago
POST /admin/uploads/{id}/reconcile
→ Queries EMP API for real status
→ Check: billing_attempts.status updated
→ Check: last_reconciled_at timestamp
→ Check: reconciliation_attempts incremented
```

---

## Database Verification Queries

**Check upload progress**
```sql
SELECT
  id,
  filename,
  status,
  total_records,
  processed_records,
  failed_records,
  processing_started_at,
  processing_completed_at
FROM uploads
WHERE id = {upload_id};
```

**Check validation results**
```sql
SELECT
  validation_status,
  COUNT(*) as count
FROM debtors
WHERE upload_id = {upload_id}
GROUP BY validation_status;
```

**Check VOP completion**
```sql
SELECT
  COUNT(DISTINCT d.id) as total_eligible,
  COUNT(DISTINCT vl.debtor_id) as vop_completed,
  ROUND(COUNT(DISTINCT vl.debtor_id) * 100.0 / COUNT(DISTINCT d.id), 2) as completion_pct
FROM debtors d
LEFT JOIN vop_logs vl ON d.id = vl.debtor_id
WHERE d.upload_id = {upload_id}
  AND d.validation_status = 'valid'
  AND d.iban_valid = true;
```

**Check billing attempt distribution**
```sql
SELECT
  status,
  COUNT(*) as count,
  SUM(amount) as total_amount
FROM billing_attempts
WHERE upload_id = {upload_id}
GROUP BY status;
```

**Check blacklist entries**
```sql
SELECT
  id,
  iban,
  first_name,
  last_name,
  email,
  reason,
  source,
  created_at
FROM blacklists
ORDER BY created_at DESC
LIMIT 10;
```

---

## Queue Monitoring

**Start queue worker**
```bash
docker-compose exec app php artisan queue:work \
  --queue=default,vop,billing,webhooks \
  --verbose \
  --tries=3
```

**Monitor Horizon (if enabled)**
```bash
# Access Horizon dashboard
http://localhost:8000/horizon

# Check failed jobs
docker-compose exec app php artisan horizon:list failed
```

**Check queue status**
```bash
docker-compose exec app php artisan queue:monitor default,vop,billing,webhooks
```

---

## Key Files Reference

### Controllers
- [app/Http/Controllers/Admin/UploadController.php](app/Http/Controllers/Admin/UploadController.php) - File upload & validation
- [app/Http/Controllers/Admin/BillingController.php](app/Http/Controllers/Admin/BillingController.php) - Billing sync
- [app/Http/Controllers/Admin/ReconciliationController.php](app/Http/Controllers/Admin/ReconciliationController.php) - Transaction reconciliation
- [app/Http/Controllers/Api/EmpWebhookController.php](app/Http/Controllers/Api/EmpWebhookController.php) - Webhook handling

### Services
- [app/Services/DebtorValidationService.php](app/Services/DebtorValidationService.php) - Debtor validation logic
- [app/Services/VopVerificationService.php](app/Services/VopVerificationService.php) - VOP orchestration
- [app/Services/VopScoringService.php](app/Services/VopScoringService.php) - VOP scoring via IBAN API
- [app/Services/EmpBillingService.php](app/Services/EmpBillingService.php) - EMP transaction submission
- [app/Services/EmpWebhookService.php](app/Services/EmpWebhookService.php) - Webhook processing
- [app/Services/BlacklistService.php](app/Services/BlacklistService.php) - Blacklist management

### Jobs
- [app/Jobs/ProcessUploadJob.php](app/Jobs/ProcessUploadJob.php) - File parsing orchestration
- [app/Jobs/ProcessUploadChunkJob.php](app/Jobs/ProcessUploadChunkJob.php) - Debtor import (parallel)
- [app/Jobs/ProcessVopJob.php](app/Jobs/ProcessVopJob.php) - VOP orchestration
- [app/Jobs/ProcessVopChunkJob.php](app/Jobs/ProcessVopChunkJob.php) - VOP verification (parallel)
- [app/Jobs/ProcessBillingJob.php](app/Jobs/ProcessBillingJob.php) - Billing orchestration
- [app/Jobs/ProcessBillingChunkJob.php](app/Jobs/ProcessBillingChunkJob.php) - Transaction submission (parallel)
- [app/Jobs/ProcessEmpWebhookJob.php](app/Jobs/ProcessEmpWebhookJob.php) - Async webhook processing
- [app/Jobs/ProcessReconciliationJob.php](app/Jobs/ProcessReconciliationJob.php) - Reconciliation orchestration

### Models
- [app/Models/Upload.php](app/Models/Upload.php) - File upload tracking
- [app/Models/Debtor.php](app/Models/Debtor.php) - Debtor records
- [app/Models/BillingAttempt.php](app/Models/BillingAttempt.php) - Transaction attempts
- [app/Models/VopLog.php](app/Models/VopLog.php) - VOP verification results
- [app/Models/Blacklist.php](app/Models/Blacklist.php) - Blacklisted entities

---

## Success Criteria

A complete E2E test passes when:

1. ✅ File uploaded and debtors imported
2. ✅ Validation runs, debtors marked valid/invalid
3. ✅ VOP verification completes (100%)
4. ✅ Billing sync creates BillingAttempts with transaction_id
5. ✅ Webhook updates BillingAttempt status to 'approved'
6. ✅ Debtor status changes to 'recovered'
7. ✅ Chargeback webhook marks BillingAttempt as 'chargebacked'
8. ✅ Blacklist entry created automatically
9. ✅ Re-upload of chargebacked debtor is skipped (deduplication)
10. ✅ Reconciliation updates stale pending transactions

---

## Troubleshooting

**Queue jobs not processing**
```bash
# Check queue worker is running
docker-compose ps queue

# Restart queue worker
docker-compose restart queue

# Check for failed jobs
docker-compose exec app php artisan queue:failed
```

**Webhook signature validation fails**
```bash
# Verify API password in .env
EMP_API_PASSWORD=your_password

# Calculate signature
echo -n "unique_id_here${EMP_API_PASSWORD}" | sha1sum

# Check webhook controller logs
docker-compose logs -f app | grep "Webhook signature"
```

**VOP verification stuck**
```bash
# Check VOP API credentials
IBAN_SUITE_API_KEY=your_api_key

# Test VOP API directly
docker-compose exec app php artisan tinker
>>> app(\App\Services\VopScoringService::class)->score($debtor);
```

**Billing sync fails**
```bash
# Check EMP credentials
EMP_API_USERNAME=your_username
EMP_API_PASSWORD=your_password
EMP_API_ENDPOINT=https://staging.gate.empcard.com

# Test EMP connection
docker-compose exec app php artisan tinker
>>> app(\App\Services\EmpClient::class)->testConnection();
```

---

## Next Steps

After completing these tests:

1. Run full PHPUnit suite: `php artisan test`
2. Deploy to staging environment
3. Test with real EMP staging credentials
4. Monitor webhook delivery and reconciliation
5. Validate production readiness checklist
6. Load test with high-volume uploads

For production deployment, see [infrastructure/](infrastructure/) folder for service-specific configurations.
