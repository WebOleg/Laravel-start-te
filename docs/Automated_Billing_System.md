# Billing Systems Documentation

This document explains the architecture, logic, and technical implementation of the three billing models: **Legacy**, **Flywheel**, and **Recovery**.

---

## 1. Billing Models Overview

The system distinguishes between manual, one-time billing and automated, recurring billing pipelines.

| Model | Type | Frequency | Amount Range | Primary Goal |
| :--- | :--- | :--- | :--- | :--- |
| **Legacy** | Manual | One-time | Any | Direct manual intervention. |
| **Flywheel** | Automated | Every 90 Days | €1.99 - €4.95 | High-volume retention. |
| **Recovery** | Automated | Every 6 Months | €29.99 - €99.99 | Debt recovery / High-value. |

### Core Rules
* **IBAN Exclusivity:** A single IBAN (Debtor Profile) cannot belong to both Flywheel and Recovery models. The `iban_hash` acts as the primary key for this restriction.
* **Lifetime Cap:** The maximum cumulative billing amount for any single debtor across all cycles is **€750**.
* **Auto-Switching:** During upload, if the chosen model's amount constraints aren't met, the system automatically switches the row to the appropriate model (e.g., if "Flywheel" is selected but the amount is €50, it switches to "Recovery" or "Legacy").

---

## 2. Database Architecture

The system uses `debtor_profiles` as the source of truth for recurring billing states, linked to individual `debtors` and `billing_attempts`.

### Debtor Profiles (`debtor_profiles`)
This table manages the automation anchors for recurring models.
* `iban_hash`: Unique identifier ensuring model exclusivity.
* `billing_model`: `legacy`, `flywheel`, or `recovery`.
* `next_bill_at`: Calculated based on the model cycle (90 days vs 6 months).
* `lifetime_charged_amount`: Tracks progress toward the €750 cap.

### Key Table Amendments
* **`uploads`**: Stores the `billing_model` selected at the time of file import.
* **`debtors`**: Linked to `debtor_profile_id`. Holds a snapshot of the model used for that specific record.
* **`billing_attempts`**: Includes `cycle_anchor` and `debtor_profile_id` to prevent double-billing within the same period (idempotency).

---

## 3. The Import & Resolution Pipeline

When a spreadsheet is uploaded via `DebtorImportService`, the system follows a strict resolution logic:



1.  **Model Resolution:**
    * If Upload = Legacy → Row = Legacy.
    * If Upload = Flywheel/Recovery → Check Amount. The system validates if the amount fits the "Flywheel" or "Recovery" range. If not, it autoswitches to the correct model or falls back to Legacy.
2.  **Profile Linking:**
    * Checks if the `iban_hash` already exists.
    * **Conflict Handling:** * If a profile is already Legacy, it cannot be moved to a recurring model (Skip).
        * If a profile is Flywheel and the new row is Recovery (or vice versa), the row is **skipped** to maintain model purity.
3.  **Profile Creation:** If the IBAN is new, a profile is created with the resolved model and the initial billing amount.

---

## 4. Automated Billing Execution

The billing process is managed by the console command:
`php artisan billing:dispatch`

This command runs **every minute** and processes the pipeline in three distinct phases:

### Phase 1: Validation
Identifies debtors due for billing whose data requires validation and dispatches `ProcessValidationChunkJob`.

### Phase 2: VOP (Verification of Payment)
Checks the status of the bank account for validated candidates via `ProcessVopChunkJob`.

### Phase 3: Billing Execution
Queries `DebtorProfile` records that are `due()` and `underLifetimeCap()`. It then dispatches `ProcessBillingChunkJob` in chunks.

> **Concurrency Control:** The system uses Redis locks (`billing:lock:{type}:{id}`) with a 30-minute TTL to ensure that workers do not overlap on the same record.

---

## 5. API & Analytics

All administrative and analytical endpoints now support filtering by the `billing_model` to segment performance data.

### Statistical Endpoints
* **Chargeback Rates:** `api/admin/stats/chargeback-rates?model=flywheel`
* **BIC/Bank Analytics:** `api/admin/analytics/bic?model=recovery`
* **Upload Stats:** `api/admin/uploads/{id}/billing-stats?debtor_type=flywheel`

### Dashboard Integration
The UI allows filtering the **Billing History**, **Debtor List**, and **Analytics** screens by model. This enables side-by-side comparison of the "Flywheel" retention strategy versus the "Recovery" strategy.

---
