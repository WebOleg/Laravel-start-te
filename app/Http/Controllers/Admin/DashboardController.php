<?php

/**
 * Admin Dashboard controller for overview statistics.
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

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'month' => 'nullable|integer|min:1|max:12',
            'year' => 'nullable|integer|min:2020|max:2100',
            'emp_account_id' => 'nullable|integer|exists:emp_accounts,id',
        ]);

        $month = $request->input('month');
        $year = $request->input('year');
        $empAccountId = $request->input('emp_account_id');

        return response()->json([
            'data' => [
                'uploads' => $this->getUploadStats($empAccountId),
                'debtors' => $this->getDebtorStats($month, $year, $empAccountId),
                'vop' => $this->getVopStats(),
                'billing' => $this->getBillingStats($month, $year, $empAccountId),
                'recent_activity' => $this->getRecentActivity($empAccountId),
                'trends' => $this->getTrends($empAccountId),
                'filters' => [
                    'month' => $month,
                    'year' => $year,
                    'emp_account_id' => $empAccountId,
                ],
            ],
        ]);
    }

    private function getUploadStats(?int $empAccountId = null): array
    {
        $query = Upload::query();
        
        if ($empAccountId) {
            $query->where('emp_account_id', $empAccountId);
        }

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

    private function getDebtorStats(?int $month = null, ?int $year = null, ?int $empAccountId = null): array
    {
        $startDate = null;
        $endDate = null;

        if ($month && $year) {
            $startDate = Carbon::create($year, $month, 1)->startOfMonth();
            $endDate = Carbon::create($year, $month, 1)->endOfMonth();
        }

        $billedQuery = BillingAttempt::query();
        $approvedQuery = BillingAttempt::where('status', BillingAttempt::STATUS_APPROVED);
        $chargebackedQuery = BillingAttempt::where('status', BillingAttempt::STATUS_CHARGEBACKED);

        if ($empAccountId) {
            $billedQuery->where('emp_account_id', $empAccountId);
            $approvedQuery->where('emp_account_id', $empAccountId);
            $chargebackedQuery->where('emp_account_id', $empAccountId);
        }

        if ($startDate && $endDate) {
            // Use emp_created_at (real EMP transaction date) with fallback to created_at
            $billedQuery->whereRaw('COALESCE(emp_created_at, created_at) BETWEEN ? AND ?', [$startDate, $endDate]);
            $approvedQuery->whereRaw('COALESCE(emp_created_at, created_at) BETWEEN ? AND ?', [$startDate, $endDate]);
            $chargebackedQuery->whereRaw('COALESCE(emp_created_at, created_at) BETWEEN ? AND ?', [$startDate, $endDate]);
        }

        $totalBilled = $billedQuery->sum('amount');
        $totalApproved = $approvedQuery->sum('amount');
        $totalChargebacked = $chargebackedQuery->sum('amount');
        $netRecovered = $totalApproved - $totalChargebacked;

        return [
            'total' => Debtor::count(),
            'by_status' => [
                'pending' => Debtor::where('status', Debtor::STATUS_PENDING)->count(),
                'processing' => Debtor::where('status', Debtor::STATUS_PROCESSING)->count(),
                'approved' => Debtor::where('status', Debtor::STATUS_APPROVED)->count(),
                'chargebacked' => Debtor::where('status', Debtor::STATUS_CHARGEBACKED)->count(),
                'recovered' => Debtor::where('status', Debtor::STATUS_RECOVERED)->count(),
                'failed' => Debtor::where('status', Debtor::STATUS_FAILED)->count(),
            ],
            'total_amount' => round($totalBilled, 2),
            'recovered_amount' => round($netRecovered, 2),
            'recovery_rate' => $totalBilled > 0
                ? round(($netRecovered / $totalBilled) * 100, 2)
                : 0,
            'by_country' => Debtor::select('country', DB::raw('count(*) as count'))
                ->whereNotNull('country')
                ->groupBy('country')
                ->orderByDesc('count')
                ->limit(10)
                ->pluck('count', 'country'),
            'valid_iban_rate' => $this->calculateRate(
                Debtor::where('iban_valid', true)->count(),
                Debtor::count()
            ),
        ];
    }

    private function getVopStats(): array
    {
        $total = VopLog::count();

        return [
            'total' => $total,
            'by_result' => [
                'verified' => VopLog::where('result', VopLog::RESULT_VERIFIED)->count(),
                'likely_verified' => VopLog::where('result', VopLog::RESULT_LIKELY_VERIFIED)->count(),
                'inconclusive' => VopLog::where('result', VopLog::RESULT_INCONCLUSIVE)->count(),
                'mismatch' => VopLog::where('result', VopLog::RESULT_MISMATCH)->count(),
                'rejected' => VopLog::where('result', VopLog::RESULT_REJECTED)->count(),
            ],
            'verification_rate' => $this->calculateRate(
                VopLog::whereIn('result', [VopLog::RESULT_VERIFIED, VopLog::RESULT_LIKELY_VERIFIED])->count(),
                $total
            ),
            'average_score' => round(VopLog::avg('vop_score') ?? 0, 2),
            'today' => VopLog::whereDate('created_at', today())->count(),
        ];
    }

    private function getBillingStats(?int $month = null, ?int $year = null, ?int $empAccountId = null): array
    {
        $startDate = null;
        $endDate = null;

        if ($month && $year) {
            $startDate = Carbon::create($year, $month, 1)->startOfMonth();
            $endDate = Carbon::create($year, $month, 1)->endOfMonth();
        }

        // Base queries with date filter using emp_created_at
        $baseQuery = BillingAttempt::query();
        
        if ($empAccountId) {
            $baseQuery->where('emp_account_id', $empAccountId);
        }
        
        if ($startDate && $endDate) {
            $baseQuery->whereRaw('COALESCE(emp_created_at, created_at) BETWEEN ? AND ?', [$startDate, $endDate]);
        }

        $total = (clone $baseQuery)->count();
        $successful = (clone $baseQuery)->where('status', BillingAttempt::STATUS_APPROVED)->count();
        $chargebacked = (clone $baseQuery)->where('status', BillingAttempt::STATUS_CHARGEBACKED)->count();
        $totalAmount = (clone $baseQuery)->where('status', BillingAttempt::STATUS_APPROVED)->sum('amount');
        $chargebackAmount = (clone $baseQuery)->where('status', BillingAttempt::STATUS_CHARGEBACKED)->sum('amount');

        $pending = (clone $baseQuery)->where('status', BillingAttempt::STATUS_PENDING)->count();
        $declined = (clone $baseQuery)->where('status', BillingAttempt::STATUS_DECLINED)->count();
        $error = (clone $baseQuery)->where('status', BillingAttempt::STATUS_ERROR)->count();
        $voided = (clone $baseQuery)->where('status', BillingAttempt::STATUS_VOIDED)->count();

        // Today query with emp_account filter
        $todayQuery = BillingAttempt::whereRaw('DATE(COALESCE(emp_created_at, created_at)) = ?', [today()->toDateString()]);
        if ($empAccountId) {
            $todayQuery->where('emp_account_id', $empAccountId);
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
            'chargeback_rate' => $this->calculateRate($chargebacked, $successful + $chargebacked),
            'total_approved_amount' => round($totalAmount, 2),
            'total_chargeback_amount' => round($chargebackAmount, 2),
            'today' => $todayQuery->count(),
            'average_attempts_per_debtor' => round(
                $total / max(Debtor::count(), 1),
                2
            ),
        ];
    }

    private function getRecentActivity(?int $empAccountId = null): array
    {
        $uploadsQuery = Upload::select('id', 'original_filename', 'status', 'total_records', 'created_at');
        $billingQuery = BillingAttempt::select('id', 'debtor_id', 'status', 'amount', 'emp_created_at', 'created_at')
            ->with('debtor:id,first_name,last_name');

        if ($empAccountId) {
            $uploadsQuery->where('emp_account_id', $empAccountId);
            $billingQuery->where('emp_account_id', $empAccountId);
        }

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

    private function getTrends(?int $empAccountId = null): array
    {
        $days = 7;
        $trends = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            
            $uploadsQuery = Upload::whereDate('created_at', $date);
            $billingQuery = BillingAttempt::whereRaw('DATE(COALESCE(emp_created_at, created_at)) = ?', [$date]);
            $successQuery = BillingAttempt::whereRaw('DATE(COALESCE(emp_created_at, created_at)) = ?', [$date])
                ->where('status', BillingAttempt::STATUS_APPROVED);
            $cbQuery = BillingAttempt::whereRaw('DATE(COALESCE(emp_created_at, created_at)) = ?', [$date])
                ->where('status', BillingAttempt::STATUS_CHARGEBACKED);

            if ($empAccountId) {
                $uploadsQuery->where('emp_account_id', $empAccountId);
                $billingQuery->where('emp_account_id', $empAccountId);
                $successQuery->where('emp_account_id', $empAccountId);
                $cbQuery->where('emp_account_id', $empAccountId);
            }

            $trends[] = [
                'date' => $date,
                'uploads' => $uploadsQuery->count(),
                'debtors' => Debtor::whereDate('created_at', $date)->count(),
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
