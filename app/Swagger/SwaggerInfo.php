<?php

namespace App\Swagger;

use OpenApi\Annotations as OA;

/**
 * @OA\Info(
 *     title="Billing & Debt Recovery API",
 *     version="1.0.0",
 *     description="API for managing debtors, billing attempts, uploads, VOP verification, BAV, chargebacks, EMP accounts, reconciliation, and admin operations.",
 *     @OA\Contact(
 *         email="dev@yourcompany.com"
 *     )
 * )
 *
 * @OA\Server(
 *     url="/api",
 *     description="Local Development Server"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT",
 *     description="Login via /api/login, then pass the token as: Bearer {token}"
 * )
 *
 *
 * @OA\Tag(name="Auth", description="Authentication, 2FA setup, OTP verification, login/logout")
 * @OA\Tag(name="Dashboard", description="Admin dashboard overview with aggregated stats across uploads, debtors, VOP, billing, and 7-day trends")
 * @OA\Tag(name="Stats", description="Chargeback statistics by country, reason code, bank, and price point with configurable period/model/account filters")
 * @OA\Tag(name="BIC Analytics", description="Bank-level (BIC) transaction analytics for risk monitoring — chargeback rates, price point breakdowns, code breakdowns, and CSV export")
 * @OA\Tag(name="Uploads", description="File upload management — CSV/XLSX ingestion, debtor creation, validation, settings, cooldown, reassignment, billing cycles, and search")
 * @OA\Tag(name="VOP", description="Verification of Payee — bulk VOP verification for uploads, single IBAN verification via BAV API, and VOP log retrieval")
 * @OA\Tag(name="VOP Logs", description="VOP verification log entries with filtering by upload, debtor, result, BAV status, and text search")
 * @OA\Tag(name="BAV", description="Bank Account Verification for uploads — start/cancel verification, credit balance management, and per-upload progress tracking")
 * @OA\Tag(name="BAV Batches", description="Standalone BAV batch verification — CSV upload with auto-detect columns, batch processing with deduplication, progress polling, and results download")
 * @OA\Tag(name="Billing", description="SEPA Direct Debit billing — sync/resync dispatch, void transactions, cancel active billing, and per-upload billing statistics")
 * @OA\Tag(name="Billing Attempts", description="Individual billing attempt management — listing with filters, retry failed attempts, and single attempt details")
 * @OA\Tag(name="Clean Users", description="Clean users export — debtors with approved charges, no lifetime chargebacks, and not recently charged. Supports streaming and async export")
 * @OA\Tag(name="Reconciliation", description="Transaction reconciliation with EMP gateway — single attempt, per-upload, and bulk reconciliation with progress stats")
 * @OA\Tag(name="EMP", description="EMP merchant account management — list/activate accounts, monthly caps with usage tracking, inbound refresh sync, and 90-day chargeback stats")
 * @OA\Tag(name="Tether Instances", description="Tether instance listing for admin panel instance selector — includes acquirer account association")
 * @OA\Tag(name="Chargebacks", description="Chargeback records with statistics — list with filters, unique reason codes, per-upload reason breakdown, and per-code record drill-down")
 * @OA\Tag(name="Debtors", description="Debtor CRUD — listing with filters, update with field mapping and billing model changes, validation, orphan cleanup, and bulk EMP account reassignment")
 * @OA\Tag(name="Descriptors", description="Billing transaction descriptors — bank statement text management with per-account and global defaults, month/year targeting")
 * @OA\Tag(name="Webhook Relays", description="Webhook relay proxy configuration — custom domain to target URL routing with SSL provisioning and Nginx deployment")
 * @OA\Tag(name="Webhooks", description="External webhook endpoints — EMP gateway notifications with signature verification, deduplication, and async processing")
 *
 *
 *
 * @OA\Schema(
 *     schema="BicAnalyticsRow",
 *     type="object",
 *     description="Aggregated transaction metrics for a single BIC",
 *     @OA\Property(property="bic", type="string", example="DEUTDEFF"),
 *     @OA\Property(property="bank_country", type="string", example="DE"),
 *     @OA\Property(property="currency", type="string", example="EUR"),
 *     @OA\Property(property="amount", type="number", format="float", example=49.99),
 *     @OA\Property(property="total_transactions", type="integer", example=1200),
 *     @OA\Property(property="approved_count", type="integer", example=950),
 *     @OA\Property(property="declined_count", type="integer", example=150),
 *     @OA\Property(property="chargeback_count", type="integer", example=80),
 *     @OA\Property(property="error_count", type="integer", example=15),
 *     @OA\Property(property="pending_count", type="integer", example=5),
 *     @OA\Property(property="total_volume", type="number", format="float", example=59988.00),
 *     @OA\Property(property="approved_volume", type="number", format="float", example=47490.50),
 *     @OA\Property(property="chargeback_volume", type="number", format="float", example=3999.20),
 *     @OA\Property(property="cb_rate_count", type="number", format="float", example=7.77),
 *     @OA\Property(property="cb_rate_volume", type="number", format="float", example=7.77),
 *     @OA\Property(property="is_high_risk", type="boolean", example=false),
 *     @OA\Property(property="is_blacklisted", type="boolean", example=false)
 * )
 * @OA\Schema(
 *     schema="BillingAttempt",
 *     type="object",
 *     description="SEPA Direct Debit billing attempt",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="debtor_id", type="integer", example=42),
 *     @OA\Property(property="upload_id", type="integer", example=5),
 *     @OA\Property(property="transaction_id", type="string", nullable=true, example="TXN-20250315-001"),
 *     @OA\Property(property="unique_id", type="string", nullable=true, example="emp_abc123"),
 *     @OA\Property(property="amount", type="number", format="float", example=49.99),
 *     @OA\Property(property="currency", type="string", example="EUR"),
 *     @OA\Property(property="status", type="string", enum={"pending", "approved", "declined", "error", "voided", "chargebacked"}, example="approved"),
 *     @OA\Property(property="attempt_number", type="integer", example=1),
 *     @OA\Property(property="mid_reference", type="string", nullable=true, example="MID-001"),
 *     @OA\Property(property="error_code", type="string", nullable=true, example="insufficient_funds"),
 *     @OA\Property(property="error_message", type="string", nullable=true, example="Account has insufficient funds"),
 *     @OA\Property(property="is_approved", type="boolean", example=true),
 *     @OA\Property(property="is_final", type="boolean", example=true),
 *     @OA\Property(property="can_retry", type="boolean", example=false),
 *     @OA\Property(property="emp_created_at", type="string", format="date-time", nullable=true, example="2025-03-15T10:30:00+00:00"),
 *     @OA\Property(property="processed_at", type="string", format="date-time", nullable=true, example="2025-03-15T10:31:00+00:00"),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-03-15T10:30:00+00:00"),
 *     @OA\Property(property="debtor", type="object", nullable=true, description="Related debtor (when loaded)"),
 *     @OA\Property(property="emp_account", type="object", nullable=true,
 *         @OA\Property(property="id", type="integer", example=1),
 *         @OA\Property(property="name", type="string", example="Primary Account"),
 *         @OA\Property(property="slug", type="string", example="primary-account")
 *     )
 * )
 * @OA\Schema(
 *     schema="Chargeback",
 *     type="object",
 *     description="Chargebacked billing attempt with debtor and account details",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="error_code", type="string", nullable=true, example="MD06"),
 *     @OA\Property(property="error_message", type="string", nullable=true, example="Refund request by end customer"),
 *     @OA\Property(property="amount", type="number", format="float", example=49.99),
 *     @OA\Property(property="currency", type="string", example="EUR"),
 *     @OA\Property(property="bank_name", type="string", nullable=true, example="Deutsche Bank"),
 *     @OA\Property(property="bank_country", type="string", nullable=true, example="DE"),
 *     @OA\Property(property="processed_at", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="emp_created_at", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="chargebacked_at", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="transaction_id", type="string", nullable=true, example="TXN-20250315-001"),
 *     @OA\Property(property="debtor", type="object", nullable=true,
 *         @OA\Property(property="id", type="integer", example=42),
 *         @OA\Property(property="first_name", type="string", example="Hans"),
 *         @OA\Property(property="last_name", type="string", example="Mueller"),
 *         @OA\Property(property="email", type="string", example="hans@example.com"),
 *         @OA\Property(property="iban", type="string", nullable=true, example="DE89370400440532013000")
 *     ),
 *     @OA\Property(property="emp_account", type="object", nullable=true,
 *         @OA\Property(property="id", type="integer", example=1),
 *         @OA\Property(property="name", type="string", example="Primary Account"),
 *         @OA\Property(property="slug", type="string", example="primary-account")
 *     )
 * )
 *
 * @OA\Schema(
 *     schema="Debtor",
 *     type="object",
 *     description="Individual debt record with validation, VOP, and billing state",
 *     @OA\Property(property="id", type="integer", example=42),
 *     @OA\Property(property="upload_id", type="integer", example=5),
 *     @OA\Property(property="iban", type="string", example="DE89370400440532013000"),
 *     @OA\Property(property="iban_masked", type="string", example="DE89****3000"),
 *     @OA\Property(property="iban_valid", type="boolean", example=true),
 *     @OA\Property(property="first_name", type="string", example="Hans"),
 *     @OA\Property(property="last_name", type="string", example="Mueller"),
 *     @OA\Property(property="full_name", type="string", example="Hans Mueller"),
 *     @OA\Property(property="email", type="string", nullable=true, example="hans@example.com"),
 *     @OA\Property(property="phone", type="string", nullable=true, example="+49171234567"),
 *     @OA\Property(property="address", type="string", nullable=true),
 *     @OA\Property(property="street", type="string", nullable=true, example="Hauptstrasse"),
 *     @OA\Property(property="street_number", type="string", nullable=true, example="42"),
 *     @OA\Property(property="postcode", type="string", nullable=true, example="10115"),
 *     @OA\Property(property="city", type="string", nullable=true, example="Berlin"),
 *     @OA\Property(property="province", type="string", nullable=true),
 *     @OA\Property(property="country", type="string", nullable=true, example="DE"),
 *     @OA\Property(property="amount", type="number", format="float", example=49.99),
 *     @OA\Property(property="currency", type="string", example="EUR"),
 *     @OA\Property(property="status", type="string", enum={"uploaded", "pending", "processing", "approved", "chargebacked", "recovered", "failed"}, example="uploaded"),
 *     @OA\Property(property="validation_status", type="string", enum={"pending", "valid", "invalid"}, example="valid"),
 *     @OA\Property(property="validation_errors", type="array", nullable=true, @OA\Items(type="string"), example=null),
 *     @OA\Property(property="validated_at", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="vop_status", type="string", enum={"pending", "verified", "error"}, example="verified"),
 *     @OA\Property(property="vop_match", type="boolean", nullable=true, example=true),
 *     @OA\Property(property="vop_verified_at", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="bav_selected", type="boolean", example=false),
 *     @OA\Property(property="risk_class", type="string", nullable=true, enum={"low", "medium", "high"}, example="low"),
 *     @OA\Property(property="external_reference", type="string", nullable=true),
 *     @OA\Property(property="bank_name", type="string", nullable=true, example="Deutsche Bank"),
 *     @OA\Property(property="bic", type="string", nullable=true, example="DEUTDEFF"),
 *     @OA\Property(property="raw_data", type="object", nullable=true),
 *     @OA\Property(property="bank_name_reference", type="string", nullable=true, example="Deutsche Bank AG"),
 *     @OA\Property(property="bank_country_iso_reference", type="string", nullable=true, example="DE"),
 *     @OA\Property(property="emp_account_id", type="integer", nullable=true, example=1),
 *     @OA\Property(property="emp_account_name", type="string", nullable=true, example="Primary Account"),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time"),
 *     @OA\Property(property="upload", type="object", nullable=true, description="Related upload (when loaded)"),
 *     @OA\Property(property="latest_vop", type="object", nullable=true, description="Latest VOP log (when loaded)"),
 *     @OA\Property(property="latest_billing", type="object", nullable=true, description="Latest billing attempt (when loaded)"),
 *     @OA\Property(property="debtor_profile", type="object", nullable=true, description="Debtor profile with billing model (when loaded)")
 * )
 * @OA\Schema(
 *     schema="Descriptor",
 *     type="object",
 *     description="Transaction descriptor defining bank statement text for SEPA transactions",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="descriptor", type="string", example="ACME Corp Monthly"),
 *     @OA\Property(property="month", type="integer", nullable=true, minimum=1, maximum=12, example=3),
 *     @OA\Property(property="year", type="integer", nullable=true, example=2025),
 *     @OA\Property(property="is_default", type="boolean", example=true),
 *     @OA\Property(property="emp_account_id", type="integer", nullable=true, example=1),
 *     @OA\Property(property="emp_account", type="object", nullable=true,
 *         @OA\Property(property="id", type="integer", example=1),
 *         @OA\Property(property="name", type="string", example="Primary Account"),
 *         @OA\Property(property="slug", type="string", example="primary-account")
 *     ),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="DescriptorInput",
 *     type="object",
 *     description="Input for creating or updating a transaction descriptor",
 *     required={"descriptor", "is_default"},
 *     @OA\Property(property="descriptor", type="string", description="Text that appears on debtor's bank statement", example="ACME Corp Monthly"),
 *     @OA\Property(property="month", type="integer", nullable=true, description="Specific month (1-12) or null for default", minimum=1, maximum=12, example=3),
 *     @OA\Property(property="year", type="integer", nullable=true, description="Specific year or null for default", example=2025),
 *     @OA\Property(property="is_default", type="boolean", description="Whether this is the default descriptor for its scope", example=false),
 *     @OA\Property(property="emp_account_id", type="integer", nullable=true, description="EMP account scope (null for global)", example=1)
 * )
 * @OA\Schema(
 *     schema="Upload",
 *     type="object",
 *     description="File upload with debtor records, validation, billing, and resync state",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="filename", type="string", example="9b1deb4d.csv"),
 *     @OA\Property(property="original_filename", type="string", example="debtors_march.csv"),
 *     @OA\Property(property="file_size", type="integer", example=524288),
 *     @OA\Property(property="mime_type", type="string", example="text/csv"),
 *     @OA\Property(property="status", type="string", enum={"pending", "processing", "completed", "cancelling", "voiding", "cancelled", "failed"}, example="completed"),
 *     @OA\Property(property="total_records", type="integer", example=1000),
 *     @OA\Property(property="processed_records", type="integer", example=995),
 *     @OA\Property(property="failed_records", type="integer", example=5),
 *     @OA\Property(property="success_rate", type="number", format="float", example=99.5),
 *     @OA\Property(property="headers", type="array", nullable=true, @OA\Items(type="string")),
 *     @OA\Property(property="processing_started_at", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="processing_completed_at", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time"),
 *     @OA\Property(property="emp_account_id", type="integer", nullable=true, example=1),
 *     @OA\Property(property="emp_account", type="object", nullable=true,
 *         @OA\Property(property="id", type="integer", example=1),
 *         @OA\Property(property="name", type="string", example="Primary Account"),
 *         @OA\Property(property="slug", type="string", example="primary-account")
 *     ),
 *     @OA\Property(property="debtors_count", type="integer", example=995),
 *     @OA\Property(property="valid_count", type="integer", example=900),
 *     @OA\Property(property="invalid_count", type="integer", example=90),
 *     @OA\Property(property="approved_count", type="integer", example=700),
 *     @OA\Property(property="billed_with_emp_count", type="integer", example=700),
 *     @OA\Property(property="chargeback_count", type="integer", example=35),
 *     @OA\Property(property="approved_amount", type="number", format="float", example=34965.00),
 *     @OA\Property(property="chargeback_amount", type="number", format="float", example=1749.65),
 *     @OA\Property(property="approved_percentage", type="number", format="float", nullable=true, example=77.78),
 *     @OA\Property(property="cb_percentage", type="number", format="float", nullable=true, example=3.89),
 *     @OA\Property(property="cb_amount_percentage", type="number", format="float", nullable=true, example=4.76),
 *     @OA\Property(property="is_deletable", type="boolean", example=false),
 *     @OA\Property(property="is_30d_cool", type="boolean", nullable=true, example=true),
 *     @OA\Property(property="billing_runs", type="array", @OA\Items(type="object",
 *         @OA\Property(property="run", type="integer", example=1),
 *         @OA\Property(property="billing_model", type="string", example="legacy"),
 *         @OA\Property(property="status", type="string", example="completed"),
 *         @OA\Property(property="started_at", type="string", format="date-time", nullable=true),
 *         @OA\Property(property="completed_at", type="string", format="date-time", nullable=true),
 *         @OA\Property(property="recovered_count", type="integer", example=400),
 *         @OA\Property(property="recovered_amount", type="number", format="float", example=19960.00)
 *     )),
 *     @OA\Property(property="can_resync", type="boolean", example=false),
 *     @OA\Property(property="resync_count", type="integer", example=0),
 *     @OA\Property(property="max_resync", type="integer", example=100)
 * )
 * @OA\Schema(
 *     schema="WebhookRelay",
 *     type="object",
 *     description="Webhook relay proxy configuration that routes incoming webhooks from a custom domain to a target URL",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="domain", type="string", example="webhooks.example.com"),
 *     @OA\Property(property="target", type="string", format="url", example="https://api.internal.com/webhooks/emp"),
 *     @OA\Property(property="emp_accounts", type="array",
 *         @OA\Items(
 *             @OA\Property(property="id", type="integer", example=1),
 *             @OA\Property(property="name", type="string", example="Primary Account"),
 *             @OA\Property(property="slug", type="string", example="primary-account")
 *         )
 *     ),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="WebhookRelayInput",
 *     type="object",
 *     description="Input for creating or updating a webhook relay",
 *     required={"domain", "target", "emp_account_ids"},
 *     @OA\Property(property="domain", type="string", description="Custom domain for receiving webhooks", example="webhooks.example.com"),
 *     @OA\Property(property="target", type="string", format="url", description="Target URL to proxy webhooks to", example="https://api.internal.com/webhooks/emp"),
 *     @OA\Property(property="emp_account_ids", type="array", minItems=1, description="EMP accounts to associate with this relay", @OA\Items(type="integer"), example={1, 2})
 * )
 * @OA\Schema(
 *     schema="VopLog",
 *     type="object",
 *     description="VOP (Verification of Payee) verification log entry",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="debtor_id", type="integer", example=42),
 *     @OA\Property(property="upload_id", type="integer", example=1),
 *     @OA\Property(property="iban", type="string", nullable=true, example="DE89370400440532013000"),
 *     @OA\Property(property="iban_masked", type="string", example="DE89****3000"),
 *     @OA\Property(property="iban_valid", type="boolean", example=true),
 *     @OA\Property(property="bank_identified", type="boolean", example=true),
 *     @OA\Property(property="bank_name", type="string", nullable=true, example="Deutsche Bank"),
 *     @OA\Property(property="bic", type="string", nullable=true, example="DEUTDEFF"),
 *     @OA\Property(property="country", type="string", nullable=true, example="DE"),
 *     @OA\Property(property="vop_score", type="integer", example=85),
 *     @OA\Property(property="score_label", type="string", nullable=true, example="High"),
 *     @OA\Property(property="result", type="string", enum={"verified", "likely_verified", "inconclusive", "mismatch", "rejected"}, example="verified"),
 *     @OA\Property(property="name_match", type="string", nullable=true, enum={"yes", "partial", "no", "unavailable"}, example="yes"),
 *     @OA\Property(property="name_match_score", type="integer", nullable=true, example=100),
 *     @OA\Property(property="bav_verified", type="boolean", example=false),
 *     @OA\Property(property="is_positive", type="boolean", example=true),
 *     @OA\Property(property="is_negative", type="boolean", example=false),
 *     @OA\Property(property="has_name_match", type="boolean", example=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="debtor", nullable=true, description="Related debtor (when loaded)", ref="#/components/schemas/Debtor")
 * )
 */
class SwaggerInfo
{
}
