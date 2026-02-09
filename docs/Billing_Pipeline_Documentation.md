# Billing Pipeline Documentation: From Upload to Charge

This document outlines the technical flow of the billing system, detailing the lifecycle of a file upload from ingestion to the final execution of billing attempts. The system utilizes an **asynchronous, event-driven architecture** powered by Laravel Queues and Batching to handle high-volume datasets efficiently.

## System Architecture Overview

The pipeline consists of five distinct phases:
1.  **Ingestion:** File upload and storage.
2.  **Import:** Parsing and creating database records.
3.  **Validation:** Data integrity and blacklist checks.
4.  **VOP (Verification of Payee):** Bank account ownership verification.
5.  **Billing:** Strategy application and payment execution.

---

## Phase 1: File Ingestion & Storage

**EntryPoint:** `POST /api/admin/uploads`  
**Controller:** `UploadController::store`

1.  **Request Handling:**
    * Accepts `file`, `billing_model` (Legacy/Flywheel/Recovery), and `emp_account_id`.
2.  **Pre-Validation:**
    * `FilePreValidationService` performs an immediate check for critical file errors before storage.
3.  **Storage:**
    * File is uploaded to S3 (`uploads/{uuid}.ext`).
4.  **Processing Strategy Decision:**
    * **Sync vs Async:** The system analyzes file size/length.
    * *Condition:* If file > 100 lines OR (Excel && > 100KB).
    * *Action:* Dispatches `ProcessUploadJob` (Async) and returns HTTP `202 Accepted`.
    * *Otherwise:* Processes synchronously immediately.

---

## Phase 2: Import & Parsing (Async)

**Job:** `ProcessUploadJob`  
**Queue:** `default`

1.  **Initialization:**
    * Downloads file from S3 to local temp storage.
    * Parses headers using `SpreadsheetParserService`.
2.  **Batching:**
    * Rows are split into chunks (Default: **500 rows** per chunk).
    * Dispatches a Batch of `ProcessUploadChunkJob`.
3.  **Mapping & Creation:**
    * Column headers are normalized (e.g., `iban_number` -> `iban`).
    * Creates `Debtor` records with status `STATUS_UPLOADED`.
    * Associates debtors with the `Upload` ID.

---

## Phase 3: Validation

**EntryPoint:** `POST /api/admin/uploads/{upload}/validate`  
**Job:** `ProcessValidationJob`  
**Queue:** `high`

*Must be run before VOP or Billing.*

1.  **Dispatch:**
    * Selects all debtors in the upload where `validated_at` is null.
    * Batches `ProcessValidationChunkJob` (Chunk size: **100**).
2.  **Execution (`DebtorValidationService`):**
    * Validates IBAN format.
    * Checks against internal blacklists.
    * Updates `validation_status` to either `VALIDATION_VALID` or `VALIDATION_INVALID`.
    * Populates `validation_errors` JSON column if invalid.

---

## Phase 4: Verification of Payee (VOP)

**EntryPoint:** `POST /api/admin/uploads/{upload}/vop/verify`  
**Job:** `ProcessVopJob`  
**Queue:** `vop`

*Gatekeeper: Billing is blocked if VOP is required but incomplete.*

1.  **Sampling Strategy:**
    * Selects only `VALIDATION_VALID` debtors.
    * **BAV (Bank Account Verification):** Selects a subset of debtors based on `bav_sampling_percentage` (Default: 10%) and daily API limits.
2.  **Batch Processing:**
    * Dispatches `ProcessVopChunkJob` (Chunk size: **50**).
3.  **Execution:**
    * Calls `VopVerificationService`.
    * **Rate Limiting:** Enforces delays between external API requests (500ms standard, 1000ms for BAV) to prevent throttling.
4.  **Reporting:**
    * Upon batch completion, triggers `GenerateVopReportJob` to create a CSV report of results.

---

## Phase 5: Billing Synchronization (The Sync)

**EntryPoint:** `POST /api/admin/uploads/{upload}/billing/sync`  
**Controller:** `BillingController::sync`

This is the decision engine that determines **who** gets billed.

1.  **Pre-Flight Checks:**
    * **Locking:** Checks cache lock `billing_sync_{upload_id}` to prevent duplicate runs.
    * **VOP Gate:** Verifies all eligible debtors have passed VOP.
2.  **Eligibility Filtering:**
    * **Model Matching:** Filters debtors by the requested `billing_model` (e.g., only process 'Flywheel' debtors).
    * **Cross-Contamination Protection:** Excludes debtors if they already exist in a conflicting model (e.g., A debtor cannot be 'Flywheel' if they are already 'Recovery').
    * **Status Check:** Excludes debtors with `PENDING` or `APPROVED` attempts for this upload.
    * **VOP Results:** Excludes debtors with `name_match: no`.
3.  **Dispatch:**
    * Dispatches `ProcessBillingJob` to the `billing` queue.

---

## Phase 6: Billing Execution

**Job:** `ProcessBillingChunkJob`  
**Queue:** `billing`

Processes the actual charge attempts.

### 1. Safety Mechanisms
* **Rate Limiting:** Limits processing to **~50 requests/second** via Cache.
* **Circuit Breaker:**
    * Trigger: **10 consecutive failures**.
    * Action: Pauses processing for **5 minutes** (`release(300)`).

### 2. Transaction Logic (Per Debtor)
Inside a Database Transaction:

1.  **Profile Resolution:**
    * Finds existing `DebtorProfile` by IBAN hash or creates a new one.
2.  **Cycle & Exclusivity Check:**
    * **Conflict:** Skips if `profile->billing_model` conflicts with the current run.
    * **Cycle Lock:** Skips if `profile->next_bill_at` is in the future (Debtor is currently in a "paid" cycle).
3.  **Billing Service Call:**
    * Calls `EmpBillingService::billDebtor`.
    * Creates a `BillingAttempt` record.
4.  **Post-Processing:**
    * **If Approved:**
        * Updates `last_success_at` to `now()`.
        * Calculates `next_bill_at` (locks debtor for 30/90/180 days based on model).
    * **If Declined/Failed:**
        * Updates failure counters.

---

## Database State Transitions

| Phase | Upload Status | Debtor Status | Billing Attempt Status |
| :--- | :--- | :--- | :--- |
| **1. Ingestion** | `PENDING` | N/A | N/A |
| **2. Import** | `PROCESSING` -> `COMPLETED` | `STATUS_UPLOADED` | N/A |
| **3. Validation** | `COMPLETED` | `VALIDATION_VALID` | N/A |
| **4. Billing Sync** | `COMPLETED` | `VALIDATION_VALID` | `PENDING` |
| **5. Billing Exec** | `COMPLETED` | `STATUS_UPLOADED` | `APPROVED` / `DECLINED` |
