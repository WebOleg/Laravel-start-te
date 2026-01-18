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
        ]);

        $month = $request->input('month');
        $year = $request->input('year');

        return response()->json([
            'data' => [
                'uploads' => $this->getUploadStats(),
                'debtors' => $this->getDebtorStats($month, $year),
                'vop' => $this->getVopStats(),
                'billing' => $this->getBillingStats($month, $year),
                'recent_activity' => $this->getRecentActivity(),
                'trends' => $this->getTrends(),
                'filters' => [
                    'month' => $month,
                    'year' => $year,
                ],
            ],
        ]);
    }

    private function getUploadStats(): array
    {
        return [
            'total' => Upload::count(),
            'pending' => Upload::where('status', Upload::STATUS_PENDING)->count(),
            'processing' => Upload::where('status', Upload::STATUS_PROCESSING)->count(),
            'completed' => Upload::where('status', Upload::STATUS_COMPLETED)->count(),
            'failed' => Upload::where('status', Upload::STATUS_FAILED)->count(),
            'today' => Upload::whereDate('created_at', today())->count(),
            'this_week' => Upload::whereBetween('created_at', [now()->startOfWeek(), now()])->count(),
        ];
    }

    private function getDebtorStats(?int $month = null, ?int $year = null): array
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

    private function getBillingStats(?int $month = null, ?int $year = null): array
    {
        $startDate = null;
        $endDate = null;

        if ($month && $year) {
            $startDate = Carbon::create($year, $month, 1)->startOfMonth();
            $endDate = Carbon::create($year, $month, 1)->endOfMonth();
        }

        // Base queries with date filter using emp_created_at
        $baseQuery = BillingAttempt::query();
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
            'today' => BillingAttempt::whereRaw('DATE(COALESCE(emp_created_at, created_at)) = ?', [today()->toDateString()])->count(),
            'average_attempts_per_debtor' => round(
                $total / max(Debtor::count(), 1),
                2
            ),
        ];
    }

    private function getRecentActivity(): array
    {
        return [
            'recent_uploads' => Upload::select('id', 'original_filename', 'status', 'total_records', 'created_at')
                ->latest()
                ->limit(5)
                ->get(),
            'recent_billing' => BillingAttempt::select('id', 'debtor_id', 'status', 'amount', 'emp_created_at', 'created_at')
                ->with('debtor:id,first_name,last_name')
                ->orderByRaw('COALESCE(emp_created_at, created_at) DESC')
                ->limit(5)
                ->get(),
        ];
    }

    private function getTrends(): array
    {
        $days = 7;
        $trends = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $trends[] = [
                'date' => $date,
                'uploads' => Upload::whereDate('created_at', $date)->count(),
                'debtors' => Debtor::whereDate('created_at', $date)->count(),
                'billing_attempts' => BillingAttempt::whereRaw('DATE(COALESCE(emp_created_at, created_at)) = ?', [$date])->count(),
                'successful_payments' => BillingAttempt::whereRaw('DATE(COALESCE(emp_created_at, created_at)) = ?', [$date])
                    ->where('status', BillingAttempt::STATUS_APPROVED)
                    ->count(),
                'chargebacks' => BillingAttempt::whereRaw('DATE(COALESCE(emp_created_at, created_at)) = ?', [$date])
                    ->where('status', BillingAttempt::STATUS_CHARGEBACKED)
                    ->count(),
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
