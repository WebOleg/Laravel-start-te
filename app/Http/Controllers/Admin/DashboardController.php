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
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'uploads' => $this->getUploadStats(),
                'debtors' => $this->getDebtorStats(),
                'vop' => $this->getVopStats(),
                'billing' => $this->getBillingStats(),
                'recent_activity' => $this->getRecentActivity(),
                'trends' => $this->getTrends(),
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

    private function getDebtorStats(): array
    {
        $totalAmount = Debtor::sum('amount');
        $recoveredAmount = Debtor::where('status', Debtor::STATUS_RECOVERED)->sum('amount');

        return [
            'total' => Debtor::count(),
            'by_status' => [
                'pending' => Debtor::where('status', Debtor::STATUS_PENDING)->count(),
                'processing' => Debtor::where('status', Debtor::STATUS_PROCESSING)->count(),
                'recovered' => Debtor::where('status', Debtor::STATUS_RECOVERED)->count(),
                'failed' => Debtor::where('status', Debtor::STATUS_FAILED)->count(),
            ],
            'total_amount' => round($totalAmount, 2),
            'recovered_amount' => round($recoveredAmount, 2),
            'recovery_rate' => $totalAmount > 0 
                ? round(($recoveredAmount / $totalAmount) * 100, 2) 
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
            'average_score' => round(VopLog::avg('score') ?? 0, 2),
            'today' => VopLog::whereDate('created_at', today())->count(),
        ];
    }

    private function getBillingStats(): array
    {
        $total = BillingAttempt::count();
        $successful = BillingAttempt::where('status', BillingAttempt::STATUS_APPROVED)->count();
        $totalAmount = BillingAttempt::where('status', BillingAttempt::STATUS_APPROVED)->sum('amount');

        return [
            'total_attempts' => $total,
            'by_status' => [
                'pending' => BillingAttempt::where('status', BillingAttempt::STATUS_PENDING)->count(),
                'approved' => $successful,
                'declined' => BillingAttempt::where('status', BillingAttempt::STATUS_DECLINED)->count(),
                'error' => BillingAttempt::where('status', BillingAttempt::STATUS_ERROR)->count(),
                'voided' => BillingAttempt::where('status', BillingAttempt::STATUS_VOIDED)->count(),
            ],
            'approval_rate' => $this->calculateRate($successful, $total),
            'total_approved_amount' => round($totalAmount, 2),
            'today' => BillingAttempt::whereDate('created_at', today())->count(),
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
            'recent_billing' => BillingAttempt::select('id', 'debtor_id', 'status', 'amount', 'created_at')
                ->with('debtor:id,first_name,last_name')
                ->latest()
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
                'billing_attempts' => BillingAttempt::whereDate('created_at', $date)->count(),
                'successful_payments' => BillingAttempt::whereDate('created_at', $date)
                    ->where('status', BillingAttempt::STATUS_APPROVED)
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
