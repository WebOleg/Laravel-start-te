<?php

/**
 * Admin Dashboard controller for overview statistics.
 *
 * CB Rate formula: chargebacks / approved (EMP-aligned).
 * Supports filtering by tether_instance_id (preferred) or emp_account_id (legacy).
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Upload;
use App\Models\Debtor;
use App\Models\VopLog;
use App\Models\BillingAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use OpenApi\Annotations as OA;

class DashboardController extends Controller
{
    /**
     * Get admin dashboard overview.
     *
     * @OA\Get(
     *     path="/api/admin/dashboard",
     *     summary="Get admin dashboard overview",
     *     description="Returns a comprehensive dashboard with upload stats, debtor stats (by status, country, IBAN validity), VOP verification stats, billing stats (by status, approval/chargeback rates, amounts), recent activity (uploads and billing), and 7-day trends. All sections support filtering by tether_instance_id or emp_account_id, and billing/debtor sections support month/year filtering.",
     *     tags={"Dashboard"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="month", in="query", required=false, description="Filter billing/debtor stats by month (1-12)", @OA\Schema(type="integer", minimum=1, maximum=12, example=3)),
     *     @OA\Parameter(name="year", in="query", required=false, description="Filter billing/debtor stats by year", @OA\Schema(type="integer", minimum=2020, maximum=2100, example=2025)),
     *     @OA\Parameter(name="emp_account_id", in="query", required=false, description="Filter by EMP account", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="tether_instance_id", in="query", required=false, description="Filter by Tether instance (takes priority over emp_account_id)", @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Dashboard data",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="uploads", type="object",
     *                     @OA\Property(property="total", type="integer", example=120),
     *                     @OA\Property(property="pending", type="integer", example=5),
     *                     @OA\Property(property="processing", type="integer", example=2),
     *                     @OA\Property(property="completed", type="integer", example=100),
     *                     @OA\Property(property="failed", type="integer", example=13),
     *                     @OA\Property(property="today", type="integer", example=3),
     *                     @OA\Property(property="this_week", type="integer", example=15)
     *                 ),
     *                 @OA\Property(property="debtors", type="object",
     *                     @OA\Property(property="total", type="integer", example=50000),
     *                     @OA\Property(property="by_status", type="object",
     *                         @OA\Property(property="pending", type="integer", example=5000),
     *                         @OA\Property(property="processing", type="integer", example=1000),
     *                         @OA\Property(property="approved", type="integer", example=35000),
     *                         @OA\Property(property="chargebacked", type="integer", example=2000),
     *                         @OA\Property(property="recovered", type="integer", example=500),
     *                         @OA\Property(property="failed", type="integer", example=6500)
     *                     ),
     *                     @OA\Property(property="total_amount", type="number", format="float", example=2500000.00),
     *                     @OA\Property(property="recovered_amount", type="number", format="float", example=1650000.00),
     *                     @OA\Property(property="recovery_rate", type="number", format="float", example=66.0),
     *                     @OA\Property(property="by_country", type="object", example={"DE": 20000, "FR": 10000, "NL": 8000}),
     *                     @OA\Property(property="valid_iban_rate", type="number", format="float", example=95.5)
     *                 ),
     *                 @OA\Property(property="vop", type="object",
     *                     @OA\Property(property="total", type="integer", example=45000),
     *                     @OA\Property(property="by_result", type="object",
     *                         @OA\Property(property="verified", type="integer", example=30000),
     *                         @OA\Property(property="likely_verified", type="integer", example=5000),
     *                         @OA\Property(property="inconclusive", type="integer", example=3000),
     *                         @OA\Property(property="mismatch", type="integer", example=5000),
     *                         @OA\Property(property="rejected", type="integer", example=2000)
     *                     ),
     *                     @OA\Property(property="verification_rate", type="number", format="float", example=77.78),
     *                     @OA\Property(property="average_score", type="number", format="float", example=72.5),
     *                     @OA\Property(property="today", type="integer", example=500)
     *                 ),
     *                 @OA\Property(property="billing", type="object",
     *                     @OA\Property(property="total_attempts", type="integer", example=40000),
     *                     @OA\Property(property="by_status", type="object",
     *                         @OA\Property(property="pending", type="integer", example=500),
     *                         @OA\Property(property="approved", type="integer", example=30000),
     *                         @OA\Property(property="declined", type="integer", example=5000),
     *                         @OA\Property(property="error", type="integer", example=2000),
     *                         @OA\Property(property="voided", type="integer", example=500),
     *                         @OA\Property(property="chargebacked", type="integer", example=2000)
     *                     ),
     *                     @OA\Property(property="approval_rate", type="number", format="float", example=75.0),
     *                     @OA\Property(property="chargeback_rate", type="number", format="float", example=6.67),
     *                     @OA\Property(property="total_approved_amount", type="number", format="float", example=1500000.00),
     *                     @OA\Property(property="total_pending_amount", type="number", format="float", example=25000.00),
     *                     @OA\Property(property="total_chargeback_amount", type="number", format="float", example=100000.00),
     *                     @OA\Property(property="today", type="integer", example=200),
     *                     @OA\Property(property="average_attempts_per_debtor", type="number", format="float", example=0.8)
     *                 ),
     *                 @OA\Property(property="recent_activity", type="object",
     *                     @OA\Property(property="recent_uploads", type="array",
     *                         @OA\Items(
     *                             @OA\Property(property="id", type="integer", example=120),
     *                             @OA\Property(property="original_filename", type="string", example="debtors_march.csv"),
     *                             @OA\Property(property="status", type="string", example="completed"),
     *                             @OA\Property(property="total_records", type="integer", example=500),
     *                             @OA\Property(property="created_at", type="string", format="date-time")
     *                         )
     *                     ),
     *                     @OA\Property(property="recent_billing", type="array",
     *                         @OA\Items(
     *                             @OA\Property(property="id", type="integer", example=40000),
     *                             @OA\Property(property="debtor_id", type="integer", example=42),
     *                             @OA\Property(property="status", type="string", example="approved"),
     *                             @OA\Property(property="amount", type="number", format="float", example=49.99),
     *                             @OA\Property(property="emp_created_at", type="string", format="date-time", nullable=true),
     *                             @OA\Property(property="created_at", type="string", format="date-time")
     *                         )
     *                     )
     *                 ),
     *                 @OA\Property(property="trends", type="array",
     *                     @OA\Items(
     *                         @OA\Property(property="date", type="string", format="date", example="2025-03-15"),
     *                         @OA\Property(property="uploads", type="integer", example=3),
     *                         @OA\Property(property="debtors", type="integer", example=500),
     *                         @OA\Property(property="billing_attempts", type="integer", example=400),
     *                         @OA\Property(property="successful_payments", type="integer", example=300),
     *                         @OA\Property(property="chargebacks", type="integer", example=15)
     *                     )
     *                 ),
     *                 @OA\Property(property="filters", type="object",
     *                     @OA\Property(property="month", type="integer", nullable=true, example=3),
     *                     @OA\Property(property="year", type="integer", nullable=true, example=2025),
     *                     @OA\Property(property="emp_account_id", type="integer", nullable=true, example=null),
     *                     @OA\Property(property="tether_instance_id", type="integer", nullable=true, example=null)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'month' => 'nullable|integer|min:1|max:12',
            'year' => 'nullable|integer|min:2020|max:2100',
            'emp_account_id' => 'nullable|integer|exists:emp_accounts,id',
            'tether_instance_id' => 'nullable|integer|exists:tether_instances,id',
        ]);

        $month = $request->input('month');
        $year = $request->input('year');
        $empAccountId = $request->input('emp_account_id');
        $tetherInstanceId = $request->input('tether_instance_id');

        $filter = compact('empAccountId', 'tetherInstanceId');

        return response()->json([
            'data' => [
                'uploads' => $this->getUploadStats($filter),
                'debtors' => $this->getDebtorStats($month, $year, $filter),
                'vop' => $this->getVopStats($filter),
                'billing' => $this->getBillingStats($month, $year, $filter),
                'recent_activity' => $this->getRecentActivity($filter),
                'trends' => $this->getTrends($filter),
                'filters' => [
                    'month' => $month,
                    'year' => $year,
                    'emp_account_id' => $empAccountId,
                    'tether_instance_id' => $tetherInstanceId,
                ],
            ],
        ]);
    }

    private function applyAccountFilter($query, array $filter, string $table = ''): void
    {
        $prefix = $table ? "{$table}." : '';

        if (!empty($filter['tetherInstanceId'])) {
            $query->where("{$prefix}tether_instance_id", $filter['tetherInstanceId']);
        } elseif (!empty($filter['empAccountId'])) {
            $query->where("{$prefix}emp_account_id", $filter['empAccountId']);
        }
    }

    private function getUploadStats(array $filter): array
    {
        $query = Upload::query();
        $this->applyAccountFilter($query, $filter);

        return [
            'total' => (clone $query)->count(),
            'pending' => (clone $query)->where('status', Upload::STATUS_PENDING)->count(),
            'processing' => (clone $query)->where('status', Upload::STATUS_PROCESSING)->count(),
            'completed' => (clone $query)->where('status', Upload::STATUS_COMPLETED)->count(),
            'failed' => (clone $query)->where('status', Upload::STATUS_FAILED)->count(),
            'today' => (clone $query)->whereDate('created_at', today())->count(),
            'this_week' => (clone $query)->whereBetween('created_at', [now()->startOfWeek(), now()])->count(),
        ];
    }

    private function getFilteredUploadIds(array $filter): ?array
    {
        if (!empty($filter['tetherInstanceId'])) {
            return Upload::where('tether_instance_id', $filter['tetherInstanceId'])->pluck('id')->toArray();
        }

        if (!empty($filter['empAccountId'])) {
            return Upload::where('emp_account_id', $filter['empAccountId'])->pluck('id')->toArray();
        }

        return null;
    }

    private function getDebtorStats(?int $month = null, ?int $year = null, array $filter = []): array
    {
        $startDate = null;
        $endDate = null;

        if ($month && $year) {
            $startDate = Carbon::create($year, $month, 1)->startOfMonth();
            $endDate = Carbon::create($year, $month, 1)->endOfMonth();
        }

        $excludedCbCodes = config('tether.chargeback.excluded_cb_reason_codes', []);

        $billedQuery = BillingAttempt::query();
        $approvedQuery = BillingAttempt::where('status', BillingAttempt::STATUS_APPROVED);
        $chargebackedQuery = BillingAttempt::where('status', BillingAttempt::STATUS_CHARGEBACKED)
            ->where(function ($q) use ($excludedCbCodes) {
                if (!empty($excludedCbCodes)) {
                    $q->whereNotIn('chargeback_reason_code', $excludedCbCodes)
                      ->orWhereNull('chargeback_reason_code');
                }
            });

        $this->applyAccountFilter($billedQuery, $filter);
        $this->applyAccountFilter($approvedQuery, $filter);
        $this->applyAccountFilter($chargebackedQuery, $filter);

        if ($startDate && $endDate) {
            $billedQuery->whereRaw('COALESCE(emp_created_at, created_at) BETWEEN ? AND ?', [$startDate, $endDate]);
            $approvedQuery->whereRaw('COALESCE(emp_created_at, created_at) BETWEEN ? AND ?', [$startDate, $endDate]);
            $chargebackedQuery->whereRaw('COALESCE(emp_created_at, created_at) BETWEEN ? AND ?', [$startDate, $endDate]);
        }

        $totalBilled = $billedQuery->sum('amount');
        $totalApproved = $approvedQuery->sum('amount');
        $totalChargebacked = $chargebackedQuery->sum('amount');
        $netRecovered = $totalApproved - $totalChargebacked;

        $debtorQuery = Debtor::query();

        if (!empty($filter['tetherInstanceId'])) {
            $debtorQuery->where('tether_instance_id', $filter['tetherInstanceId']);
        } else {
            $uploadIds = $this->getFilteredUploadIds($filter);
            if ($uploadIds !== null) {
                $debtorQuery->whereIn('upload_id', $uploadIds);
            }
        }

        $totalDebtors = (clone $debtorQuery)->count();

        return [
            'total' => $totalDebtors,
            'by_status' => [
                'pending' => (clone $debtorQuery)->where('status', Debtor::STATUS_PENDING)->count(),
                'processing' => (clone $debtorQuery)->where('status', Debtor::STATUS_PROCESSING)->count(),
                'approved' => (clone $debtorQuery)->where('status', Debtor::STATUS_APPROVED)->count(),
                'chargebacked' => (clone $debtorQuery)->where('status', Debtor::STATUS_CHARGEBACKED)->count(),
                'recovered' => (clone $debtorQuery)->where('status', Debtor::STATUS_RECOVERED)->count(),
                'failed' => (clone $debtorQuery)->where('status', Debtor::STATUS_FAILED)->count(),
            ],
            'total_amount' => round($totalBilled, 2),
            'recovered_amount' => round($netRecovered, 2),
            'recovery_rate' => $totalBilled > 0
                ? round(($netRecovered / $totalBilled) * 100, 2)
                : 0,
            'by_country' => (clone $debtorQuery)->select('country', DB::raw('count(*) as count'))
                ->whereNotNull('country')
                ->groupBy('country')
                ->orderByDesc('count')
                ->limit(10)
                ->pluck('count', 'country'),
            'valid_iban_rate' => $this->calculateRate(
                (clone $debtorQuery)->where('iban_valid', true)->count(),
                $totalDebtors
            ),
        ];
    }

    private function getVopStats(array $filter = []): array
    {
        $vopQuery = VopLog::query();

        if (!empty($filter['tetherInstanceId'])) {
            $debtorIds = Debtor::where('tether_instance_id', $filter['tetherInstanceId'])->pluck('id')->toArray();
            $vopQuery->whereIn('debtor_id', $debtorIds);
        } else {
            $uploadIds = $this->getFilteredUploadIds($filter);
            if ($uploadIds !== null) {
                $debtorIds = Debtor::whereIn('upload_id', $uploadIds)->pluck('id')->toArray();
                $vopQuery->whereIn('debtor_id', $debtorIds);
            }
        }

        $total = (clone $vopQuery)->count();

        return [
            'total' => $total,
            'by_result' => [
                'verified' => (clone $vopQuery)->where('result', VopLog::RESULT_VERIFIED)->count(),
                'likely_verified' => (clone $vopQuery)->where('result', VopLog::RESULT_LIKELY_VERIFIED)->count(),
                'inconclusive' => (clone $vopQuery)->where('result', VopLog::RESULT_INCONCLUSIVE)->count(),
                'mismatch' => (clone $vopQuery)->where('result', VopLog::RESULT_MISMATCH)->count(),
                'rejected' => (clone $vopQuery)->where('result', VopLog::RESULT_REJECTED)->count(),
            ],
            'verification_rate' => $this->calculateRate(
                (clone $vopQuery)->whereIn('result', [VopLog::RESULT_VERIFIED, VopLog::RESULT_LIKELY_VERIFIED])->count(),
                $total
            ),
            'average_score' => round((clone $vopQuery)->avg('vop_score') ?? 0, 2),
            'today' => (clone $vopQuery)->whereDate('created_at', today())->count(),
        ];
    }

    private function getBillingStats(?int $month = null, ?int $year = null, array $filter = []): array
    {
        $startDate = null;
        $endDate = null;

        if ($month && $year) {
            $startDate = Carbon::create($year, $month, 1)->startOfMonth();
            $endDate = Carbon::create($year, $month, 1)->endOfMonth();
        }

        $excludedCbCodes = config('tether.chargeback.excluded_cb_reason_codes', []);

        $baseQuery = BillingAttempt::query();
        $this->applyAccountFilter($baseQuery, $filter);

        if ($startDate && $endDate) {
            $baseQuery->whereRaw('COALESCE(emp_created_at, created_at) BETWEEN ? AND ?', [$startDate, $endDate]);
        }

        $total = (clone $baseQuery)->count();
        $successful = (clone $baseQuery)->where('status', BillingAttempt::STATUS_APPROVED)->count();

        $chargebackQuery = (clone $baseQuery)->where('status', BillingAttempt::STATUS_CHARGEBACKED)
            ->where(function ($q) use ($excludedCbCodes) {
                if (!empty($excludedCbCodes)) {
                    $q->whereNotIn('chargeback_reason_code', $excludedCbCodes)
                      ->orWhereNull('chargeback_reason_code');
                }
            });

        $chargebacked = $chargebackQuery->count();
        $totalAmount = (clone $baseQuery)->where('status', BillingAttempt::STATUS_APPROVED)->sum('amount');
        $chargebackAmount = $chargebackQuery->sum('amount');
        $pendingAmount = (clone $baseQuery)->where('status', BillingAttempt::STATUS_PENDING)->sum('amount');

        $pending = (clone $baseQuery)->where('status', BillingAttempt::STATUS_PENDING)->count();
        $declined = (clone $baseQuery)->where('status', BillingAttempt::STATUS_DECLINED)->count();
        $error = (clone $baseQuery)->where('status', BillingAttempt::STATUS_ERROR)->count();
        $voided = (clone $baseQuery)->where('status', BillingAttempt::STATUS_VOIDED)->count();

        $todayQuery = BillingAttempt::whereRaw('DATE(COALESCE(emp_created_at, created_at)) = ?', [today()->toDateString()]);
        $this->applyAccountFilter($todayQuery, $filter);

        $debtorCount = Debtor::count();
        if (!empty($filter['tetherInstanceId'])) {
            $debtorCount = Debtor::where('tether_instance_id', $filter['tetherInstanceId'])->count();
        } elseif (!empty($filter['empAccountId'])) {
            $uploadIds = $this->getFilteredUploadIds($filter);
            if ($uploadIds !== null) {
                $debtorCount = Debtor::whereIn('upload_id', $uploadIds)->count();
            }
        }

        return [
            'total_attempts' => $total,
            'by_status' => [
                'pending' => $pending,
                'approved' => $successful,
                'declined' => $declined,
                'error' => $error,
                'voided' => $voided,
                'chargebacked' => $chargebacked,
            ],
            'approval_rate' => $this->calculateRate($successful, $total),
            'chargeback_rate' => $this->calculateRate($chargebacked, $successful),
            'total_approved_amount' => round($totalAmount, 2),
            'total_pending_amount' => round($pendingAmount, 2),
            'total_chargeback_amount' => round($chargebackAmount, 2),
            'today' => $todayQuery->count(),
            'average_attempts_per_debtor' => round(
                $total / max($debtorCount, 1),
                2
            ),
        ];
    }

    private function getRecentActivity(array $filter = []): array
    {
        $uploadsQuery = Upload::select('id', 'original_filename', 'status', 'total_records', 'created_at');
        $billingQuery = BillingAttempt::select('id', 'debtor_id', 'status', 'amount', 'emp_created_at', 'created_at')
            ->with('debtor:id,first_name,last_name');

        $this->applyAccountFilter($uploadsQuery, $filter);
        $this->applyAccountFilter($billingQuery, $filter);

        return [
            'recent_uploads' => $uploadsQuery
                ->latest()
                ->limit(5)
                ->get(),
            'recent_billing' => $billingQuery
                ->orderByRaw('COALESCE(emp_created_at, created_at) DESC')
                ->limit(5)
                ->get(),
        ];
    }

    private function getTrends(array $filter = []): array
    {
        $days = 7;
        $trends = [];
        $uploadIds = $this->getFilteredUploadIds($filter);
        $excludedCbCodes = config('tether.chargeback.excluded_cb_reason_codes', []);

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');

            $uploadsQuery = Upload::whereDate('created_at', $date);
            $debtorsQuery = Debtor::whereDate('created_at', $date);
            $billingQuery = BillingAttempt::whereRaw('DATE(COALESCE(emp_created_at, created_at)) = ?', [$date]);
            $successQuery = BillingAttempt::whereRaw('DATE(COALESCE(emp_created_at, created_at)) = ?', [$date])
                ->where('status', BillingAttempt::STATUS_APPROVED);
            $cbQuery = BillingAttempt::whereRaw('DATE(COALESCE(emp_created_at, created_at)) = ?', [$date])
                ->where('status', BillingAttempt::STATUS_CHARGEBACKED)
                ->where(function ($q) use ($excludedCbCodes) {
                    if (!empty($excludedCbCodes)) {
                        $q->whereNotIn('chargeback_reason_code', $excludedCbCodes)
                          ->orWhereNull('chargeback_reason_code');
                    }
                });

            $this->applyAccountFilter($uploadsQuery, $filter);
            $this->applyAccountFilter($billingQuery, $filter);
            $this->applyAccountFilter($successQuery, $filter);
            $this->applyAccountFilter($cbQuery, $filter);

            if (!empty($filter['tetherInstanceId'])) {
                $debtorsQuery->where('tether_instance_id', $filter['tetherInstanceId']);
            } elseif ($uploadIds !== null) {
                $debtorsQuery->whereIn('upload_id', $uploadIds);
            }

            $trends[] = [
                'date' => $date,
                'uploads' => $uploadsQuery->count(),
                'debtors' => $debtorsQuery->count(),
                'billing_attempts' => $billingQuery->count(),
                'successful_payments' => $successQuery->count(),
                'chargebacks' => $cbQuery->count(),
            ];
        }

        return $trends;
    }

    private function calculateRate(int $numerator, int $denominator): float
    {
        if ($denominator === 0) {
            return 0;
        }

        return round(($numerator / $denominator) * 100, 2);
    }
}
