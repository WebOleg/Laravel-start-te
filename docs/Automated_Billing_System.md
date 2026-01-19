1. BILLING MODELS OVERVIEW
   ==========================

The system distinguishes between manual, one-time billing and automated,
recurring billing pipelines.

| Model    | Type      | Frequency      | Amount Range    | Primary Goal     |
|----------|-----------|----------------|-----------------|------------------|
| Legacy   | Manual    | One-time       | Any             | Manual oversight |
| Flywheel | Automated | Every 90 Days  | €1.99 - €4.95   | Retention        |
| Recovery | Automated | Every 6 Months | €29.99 - €99.99 | Debt Recovery    |

Core Rules:
- IBAN Exclusivity: An IBAN (iban_hash) cannot belong to both Flywheel and
  Recovery.
- Lifetime Cap: Maximum cumulative billing per debtor is €750.
- Auto-Switching: System automatically moves rows to the correct model based
  on the amount during upload.


2. DATABASE ARCHITECTURE
   ========================

The 'debtor_profiles' table is the source of truth for recurring states.

Key Fields:
- iban_hash: Primary key for model exclusivity (SHA256).
- billing_model: legacy, flywheel, or recovery.
- next_bill_at: Calculated based on cycle (90 days vs 6 months).
- lifetime_charged_amount: Cumulative total vs the €750 cap.

Table Links:
- uploads: Stores the model selected at file import.
- debtors: Linked to debtor_profile_id.
- billing_attempts: Includes cycle_anchor for idempotency.


3. THE IMPORT & RESOLUTION PIPELINE
   ===================================

1. Model Resolution:
    - Upload = Legacy -> Set to Legacy.
    - Upload = Flywheel/Recovery -> Validate Amount. If out of range,
      auto-switch to correct model or fallback to Legacy.

2. Profile Linking:
    - Checks if iban_hash exists.
    - Conflict Handling: If a profile is Flywheel and the new row is
      Recovery (or vice versa), the row is SKIPPED to maintain model purity.

3. Profile Creation:
    - New IBANs trigger profile creation with resolved model and amount.


4. AUTOMATED BILLING EXECUTION
   ==============================

The billing process is managed by: 'php artisan billing:dispatch'

CRONTAB CONFIGURATION:
----------------------
Add the following to the server crontab to run the scheduler every minute:

* * * * * cd /opt/tether-staging/backend && /usr/bin/docker compose exec -T app php artisan schedule:run >> /var/log/laravel-scheduler.log 2>&1

Execution Phases:
- Phase 1 (Validation): Identifies due debtors; dispatches
  ProcessValidationChunkJob.
- Phase 2 (VOP): Verifies bank account status via ProcessVopChunkJob.
- Phase 3 (Billing): Queries profiles due() and underLifetimeCap();
  dispatches ProcessBillingChunkJob.

Concurrency:
Uses Redis locks (billing:lock:{type}:{id}) with 30-min TTL.


5. API & ANALYTICS
   ==================

All endpoints support filtering by 'billing_model'.

Statistical Endpoints:
- Chargebacks: api/admin/stats/chargeback-rates?model=flywheel
- Bank Analytics: api/admin/analytics/bic?model=recovery
- Upload Stats: api/admin/uploads/{id}/billing-stats?debtor_type=flywheel
