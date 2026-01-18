# VOP Configuration Guide

## Current Issues in Production

Your production `.env` file has several VOP configuration issues:

### 1. **Wrong API URL** ⚠️
```env
# CURRENT (WRONG):
IBAN_API_URL=https://api.iban.com/clients/verify/v3/

# CORRECT:
IBAN_API_URL=https://api.iban.com/clients/api/v4/iban/
```

**Issue**: The production `.env` is using the BAV (name matching) endpoint for the main IBAN validation API. This is incorrect.

### 2. **Empty API Key** ❌
```env
IBAN_API_KEY=
```

**Issue**: No API key is configured, so even if mock mode was disabled, API calls would fail.

### 3. **Mock Mode Enabled** ⚠️
```env
IBAN_API_MOCK=true
```

**Issue**: Production is running with 100% fake/mock data. No real IBAN validation is happening.

### 4. **Missing BAV Configuration** ❌
```env
# These variables are MISSING from production .env:
BAV_API_URL=...
BAV_ENABLED=...
BAV_SAMPLING_PERCENTAGE=...
BAV_DAILY_LIMIT=...
```

**Issue**: BAV name matching is completely disabled by default.

---

## IBAN.com API Overview

Based on the API documentation, there are **TWO separate APIs**:

### API 1: IBAN Suite V4 (Bank Lookup)
- **URL**: `https://api.iban.com/clients/api/v4/iban/`
- **Method**: `POST`
- **Content-Type**: `application/x-www-form-urlencoded`
- **Purpose**: Validate IBAN, get bank details, BIC, SEPA support
- **Cost**: Unlimited calls on paid plan
- **Used by**: `IbanApiService`

**Request Parameters**:
```
iban: DE46500700100927353010
api_key: your_api_key
format: json
```

**Response**:
```json
{
  "bank_data": {
    "bic": "DEUTDEFF",
    "bank": "Deutsche Bank",
    "branch": "...",
    "address": "...",
    "city": "Frankfurt",
    "country_iso": "DE",
    "account": "0927353010",
    "bank_code": "50070010"
  },
  "sepa_data": {
    "SCT": "YES",
    "SDD": "YES",
    "COR1": "YES",
    "B2B": "NO",
    "SCC": "NO"
  },
  "validations": {
    "iban": {"code": "001", "message": "IBAN Check digit is correct"},
    "account": {"code": "002", "message": "Account Number check digit is correct"},
    ...
  }
}
```

### API 2: BAV V3 (Name Matching)
- **URL**: `https://api.iban.com/clients/api/verify/v3/`
- **Method**: `POST`
- **Content-Type**: `application/json`
- **Auth**: `x-api-key` header
- **Purpose**: Verify if account holder name matches IBAN
- **Cost**: Per-request pricing (expensive!)
- **Countries**: Only 20 EU countries supported
- **Used by**: `IbanBavService`

**Supported Countries**: AT, BE, CY, DE, EE, ES, FI, FR, GR, HR, IE, IT, LT, LU, LV, MT, NL, PT, SI, SK

**Request**:
```json
{
  "IBAN": "DE46500700100927353010",
  "name": "John Doe"
}
```

**Response**:
```json
{
  "query": {"success": true},
  "result": {
    "valid": true,
    "name_match": "yes",  // or "partial", "no", "unavailable"
    "bic": "DEUTDEFF"
  }
}
```

---

## Fixed Configuration

### For Development/Testing (Mock Mode)
```env
# IBAN.com API - Mock Mode (no API calls)
IBAN_API_KEY=
IBAN_API_URL=https://api.iban.com/clients/api/v4/iban/
IBAN_API_MOCK=true

# BAV - Disabled in mock mode
BAV_API_URL=https://api.iban.com/clients/api/verify/v3/
BAV_ENABLED=false
BAV_SAMPLING_PERCENTAGE=10
BAV_DAILY_LIMIT=100
```

### For Staging (Real API, Limited BAV)
```env
# IBAN.com API - Real API calls
IBAN_API_KEY=your_staging_api_key_here
IBAN_API_URL=https://api.iban.com/clients/api/v4/iban/
IBAN_API_MOCK=false

# BAV - Enabled with conservative limits
BAV_API_URL=https://api.iban.com/clients/api/verify/v3/
BAV_ENABLED=true
BAV_SAMPLING_PERCENTAGE=5
BAV_DAILY_LIMIT=50
```

### For Production (Real API, Full BAV)
```env
# IBAN.com API - Real API calls
IBAN_API_KEY=your_production_api_key_here
IBAN_API_URL=https://api.iban.com/clients/api/v4/iban/
IBAN_API_MOCK=false

# BAV - Enabled with production limits
BAV_API_URL=https://api.iban.com/clients/api/verify/v3/
BAV_ENABLED=true
BAV_SAMPLING_PERCENTAGE=10
BAV_DAILY_LIMIT=100
```

---

## How to Get an API Key

1. Go to https://www.iban.com/
2. Sign up for an account
3. Purchase a subscription:
   - **IBAN Suite V4**: Unlimited validation API
   - **BAV V3**: Name matching API (pay per request)
4. Get your API key from the client area
5. Add it to your `.env` file

---

## Cost Optimization Strategy

The app is already designed to minimize BAV costs:

### 1. **Sampling** (ProcessVopJob.php)
- Small uploads (≤1000 debtors): Only 10% get BAV
- Large uploads (>1000 debtors): Maximum 100 BAV calls
- Saves 90% of BAV costs

### 2. **Daily Limits** (ProcessVopJob.php)
- Default: 100 BAV verifications per day
- Prevents runaway costs

### 3. **IBAN Caching** (VopVerificationService.php)
- Reuses previous VOP results for same IBAN
- Stored in `vop_logs` table via `iban_hash`
- No duplicate API calls

### 4. **Database Caching** (IbanApiService.php)
- Bank data saved to `bank_references` table
- Uses bank code as key
- Reduces API calls for same banks

### 5. **Memory Caching** (IbanApiService.php)
- 24-hour cache for IBAN lookups
- Uses Laravel Cache

**Example Cost Savings**:
- Upload with 5000 debtors
- Without optimization: 5000 BAV calls × $0.10 = **$500**
- With optimization: 100 BAV calls × $0.10 = **$10**
- **Savings: 98%**

---

## Testing VOP

### Test with Mock Mode (Free)
```env
IBAN_API_MOCK=true
BAV_ENABLED=false
```

Upload a CSV and run VOP verification. Check the logs to see mock responses.

### Test Single IBAN (API Endpoint)
```bash
POST /admin/vop/verify-single
{
  "iban": "DE46500700100927353010",
  "name": "John Doe",
  "use_mock": true
}
```

### Test with Real API (Costs Money)
```env
IBAN_API_KEY=your_test_api_key
IBAN_API_MOCK=false
BAV_ENABLED=true
BAV_DAILY_LIMIT=10  # Keep it low for testing!
```

---

## Migration Plan: Mock → Production

### Step 1: Get API Key
- Purchase IBAN.com subscription
- Get API key

### Step 2: Test in Staging
```env
# Staging .env
IBAN_API_KEY=your_staging_key
IBAN_API_MOCK=false
BAV_ENABLED=true
BAV_SAMPLING_PERCENTAGE=5   # Low percentage for testing
BAV_DAILY_LIMIT=20          # Low limit for testing
```

Upload a small CSV (100 debtors) and verify:
- Bank lookups work
- BAV name matching works
- Costs are as expected

### Step 3: Deploy to Production
```env
# Production .env
IBAN_API_KEY=your_production_key
IBAN_API_MOCK=false
BAV_ENABLED=true
BAV_SAMPLING_PERCENTAGE=10
BAV_DAILY_LIMIT=100
```

### Step 4: Monitor
- Check Laravel logs for API errors
- Monitor daily BAV usage
- Review costs in IBAN.com dashboard
- Adjust sampling percentage if needed

---

## Troubleshooting

### Error: "API Key is invalid" (Code 301)
- Check `IBAN_API_KEY` is correct
- Verify subscription is active

### Error: "No queries available" (Code 303)
- Your API quota is exhausted
- Upgrade plan or wait for quota reset

### Error: "Country does not support IBAN standard" (Code 207)
- The IBAN country code is invalid
- Check debtor data for typos

### BAV Always Returns "unavailable"
- Check if country is in supported list (20 countries)
- Bank may not support name matching
- This is normal, not an error

### All VOP Scores Are the Same
- `IBAN_API_MOCK=true` → Using mock data
- Set to `false` for real validation

---

## Summary of Changes Needed

### Production .env File
```diff
# IBAN.com BAV API
-IBAN_API_KEY=
-IBAN_API_URL=https://api.iban.com/clients/verify/v3/
-IBAN_API_MOCK=true
+IBAN_API_KEY=your_production_api_key_here
+IBAN_API_URL=https://api.iban.com/clients/api/v4/iban/
+IBAN_API_MOCK=false
+
+# BAV Configuration
+BAV_API_URL=https://api.iban.com/clients/api/verify/v3/
+BAV_ENABLED=true
+BAV_SAMPLING_PERCENTAGE=10
+BAV_DAILY_LIMIT=100
```

**Critical**: The URL was wrong. It should be `/clients/api/v4/iban/`, not `/clients/verify/v3/`.
