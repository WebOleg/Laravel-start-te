<?php

/**
 * Controller for managing file uploads and debtor validation.
 */

namespace App\Http\Controllers\Admin;

use App\Enums\BillingModel;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUploadRequest;
use App\Http\Resources\UploadResource;
use App\Http\Resources\DebtorResource;
use App\Models\DebtorProfile;
use App\Models\EmpAccount;
use App\Models\Upload;
use App\Models\Debtor;
use App\Models\BillingAttempt;
use App\Services\FileUploadService;
use App\Services\FilePreValidationService;
use App\Services\DebtorValidationService;
use App\Jobs\ProcessValidationJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class UploadController extends Controller
{
    private const ASYNC_THRESHOLD = 100;

    public function __construct(
        private FileUploadService $uploadService,
        private FilePreValidationService $preValidationService,
        private DebtorValidationService $validationService
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => 'nullable|string|in:pending,processing,completed,failed',
            'emp_account_id' => 'nullable|integer|exists:emp_accounts,id',
            'tether_instance_id' => 'nullable|integer|exists:tether_instances,id',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $excludedCbCodes = config('tether.chargeback.excluded_cb_reason_codes', []);

        $query = Upload::with(['empAccount', 'tetherInstance'])->withCount([
            'debtors',
            'debtors as valid_count' => function ($q) {
                $q->where('validation_status', Debtor::VALIDATION_VALID);
            },
            'debtors as invalid_count' => function ($q) {
                $q->where('validation_status', Debtor::VALIDATION_INVALID);
            },
            'billingAttempts as billed_with_emp_count' => function ($q) {
                $q->where('status', BillingAttempt::STATUS_APPROVED);
            },
            'billingAttempts as chargeback_count' => function ($q) use ($excludedCbCodes) {
                $q->where('status', BillingAttempt::STATUS_CHARGEBACKED);
                if (!empty($excludedCbCodes)) {
                    $q->where(function ($inner) use ($excludedCbCodes) {
                        $inner->whereNotIn('chargeback_reason_code', $excludedCbCodes)
                            ->orWhereNull('chargeback_reason_code');
                    });
                }
            },
        ])->withSum(['billingAttempts as approved_amount' => function ($q) {
            $q->where('status', BillingAttempt::STATUS_APPROVED);
        }], 'amount')->withSum(['billingAttempts as chargeback_amount' => function ($q) use ($excludedCbCodes) {
            $q->where('status', BillingAttempt::STATUS_CHARGEBACKED);
            if (!empty($excludedCbCodes)) {
                $q->where(function ($inner) use ($excludedCbCodes) {
                    $inner->whereNotIn('chargeback_reason_code', $excludedCbCodes)
                        ->orWhereNull('chargeback_reason_code');
                });
            }
        }], 'amount');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('tether_instance_id')) {
            $query->where('tether_instance_id', $request->input('tether_instance_id'));
        } elseif ($request->filled('emp_account_id')) {
            $query->where('emp_account_id', $request->input('emp_account_id'));
        }

        $uploads = $query->latest()->paginate($request->input('per_page', 20));

        return UploadResource::collection($uploads);
    }

    public function show(Upload $upload): UploadResource
    {
        $upload->load(['uploader', 'empAccount', 'tetherInstance']);
        $upload->loadCount([
            'debtors',
            'debtors as valid_count' => function ($q) {
                $q->where('validation_status', Debtor::VALIDATION_VALID);
            },
            'debtors as invalid_count' => function ($q) {
                $q->where('validation_status', Debtor::VALIDATION_INVALID);
            },
            'debtors as bav_excluded_count' => function ($q) {
                $q->whereHas('vopLogs', function ($vopQuery) {
                    $vopQuery->where('name_match', 'no');
                });
            },
            'debtors as bav_passed_count' => function ($q) {
                $q->whereHas('vopLogs', function ($vopQuery) {
                    $vopQuery->whereIn('name_match', ['yes', 'partial']);
                });
            },
            'debtors as bav_verified_count' => function ($q) {
                $q->whereHas('vopLogs', function ($vopQuery) {
                    $vopQuery->whereNotNull('name_match');
                });
            },
            'billingAttempts as billed_with_emp_count' => function ($q) {
                $q->where('status', BillingAttempt::STATUS_APPROVED);
            },
            'billingAttempts as chargeback_count' => function ($q) {
                $q->where('status', BillingAttempt::STATUS_CHARGEBACKED);
            },
        ]);

        $upload->loadSum(['billingAttempts as approved_amount' => function ($q) {
            $q->where('status', BillingAttempt::STATUS_APPROVED);
        }], 'amount');

        $upload->loadSum(['billingAttempts as chargeback_amount' => function ($q) {
            $q->where('status', BillingAttempt::STATUS_CHARGEBACKED);
        }], 'amount');

        return new UploadResource($upload);
    }

    public function store(StoreUploadRequest $request): JsonResponse
    {
        try {
            $file = $request->file('file');

            $billingModel = BillingModel::from(
                $request->input('billing_model', BillingModel::Legacy->value)
            );

            $empAccountId = $request->input('emp_account_id');
            $tetherInstanceId = $request->input('tether_instance_id');
            $applyGlobalLock = $request->boolean('apply_global_lock');

            $preValidation = $this->preValidationService->validate($file);
            if (!$preValidation['valid']) {
                return response()->json([
                    'message' => 'File validation failed.',
                    'errors' => $preValidation['errors'],
                ], 422);
            }

            $forceAsync = $request->boolean('async');

            if ($forceAsync || $this->shouldProcessAsync($file)) {
                $result = $this->uploadService->processAsync(
                    $file,
                    $request->user()?->id,
                    $billingModel,
                    $empAccountId,
                    $applyGlobalLock,
                    $tetherInstanceId
                );

                return response()->json([
                    'data' => new UploadResource($result['upload']),
                    'meta' => [
                        'queued' => true,
                        'message' => 'File queued for processing. Check status for updates.',
                    ],
                ], 202);
            }

            $result = $this->uploadService->process(
                $file,
                $request->user()?->id,
                $billingModel,
                $empAccountId,
                $applyGlobalLock,
                $tetherInstanceId
            );

            return response()->json([
                'data' => new UploadResource($result['upload']),
                'meta' => [
                    'queued' => false,
                    'created' => $result['created'],
                    'failed' => $result['failed'],
                    'skipped' => $result['skipped'],
                    'errors' => array_slice($result['errors'], 0, 10),
                ],
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function status(Upload $upload): JsonResponse
    {
        $upload->loadCount('debtors');

        return response()->json([
            'data' => [
                'id' => $upload->id,
                'status' => $upload->status,
                'total_records' => $upload->total_records,
                'processed_records' => $upload->processed_records,
                'failed_records' => $upload->failed_records,
                'debtors_count' => $upload->debtors_count,
                'progress' => $this->calculateProgress($upload),
                'is_complete' => in_array($upload->status, [
                    Upload::STATUS_COMPLETED,
                    Upload::STATUS_FAILED,
                ]),
            ],
        ]);
    }

    public function debtors(Upload $upload, Request $request): AnonymousResourceCollection
    {
        $query = $upload->debtors()->with(['latestBillingAttempt', 'debtorProfile']);

        if ($request->filled('debtor_type') && $request->input('debtor_type') !== 'all') {
            $type = $request->input('debtor_type');

            if ($type === DebtorProfile::MODEL_LEGACY) {
                $query->where(function ($q) {
                    $q->whereDoesntHave('debtorProfile')
                        ->orWhereHas('debtorProfile', function ($subQ) {
                            $subQ->where('billing_model', DebtorProfile::MODEL_LEGACY);
                        });
                });
            } else {
                $query->whereHas('debtorProfile', function ($q) use ($type) {
                    $q->where('billing_model', $type);
                });
            }
        }

        if ($request->has('validation_status')) {
            $query->where('validation_status', $request->input('validation_status'));
        }

        if ($request->boolean('exclude_chargebacked')) {
            $query->whereDoesntHave('billingAttempts', function ($q) {
                $q->where('status', BillingAttempt::STATUS_CHARGEBACKED);
            });
        }

        if ($request->has('search')) {
            $search = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', $search)
                    ->orWhere('last_name', 'like', $search)
                    ->orWhere('iban', 'like', $search)
                    ->orWhere('email', 'like', $search);
            });
        }

        $debtors = $query->latest()->paginate($request->input('per_page', 50));

        return DebtorResource::collection($debtors);
    }

    public function validate(Request $request, Upload $upload): JsonResponse
    {
        $request->validate([
            'skip_bic_blacklist' => 'nullable|boolean',
        ]);

        if ($upload->status === Upload::STATUS_PROCESSING) {
            return response()->json([
                'message' => 'Upload is still processing. Please wait.',
            ], 422);
        }

        if ($upload->isValidationProcessing()) {
            return response()->json([
                'message' => 'Validation already in progress.',
                'status' => 'processing',
            ], 200);
        }

        if ($request->has('skip_bic_blacklist')) {
            $upload->update([
                'skip_bic_blacklist' => $request->boolean('skip_bic_blacklist'),
            ]);
        }

        ProcessValidationJob::dispatch($upload);

        return response()->json([
            'message' => 'Validation started',
            'status' => 'processing',
        ], 202);
    }

    public function validationStats(Upload $upload, Request $request): JsonResponse
    {
        $modelStats = $upload->debtors()
            ->leftJoin('debtor_profiles', 'debtors.debtor_profile_id', '=', 'debtor_profiles.id')
            ->selectRaw("
            COUNT(*) as all_count,
            SUM(CASE WHEN debtor_profiles.billing_model = ? THEN 1 ELSE 0 END) as flywheel,
            SUM(CASE WHEN debtor_profiles.billing_model = ? THEN 1 ELSE 0 END) as recovery,
            SUM(CASE WHEN debtor_profiles.billing_model = ? OR debtors.debtor_profile_id IS NULL THEN 1 ELSE 0 END) as legacy
        ", [
            DebtorProfile::MODEL_FLYWHEEL,
            DebtorProfile::MODEL_RECOVERY,
            DebtorProfile::MODEL_LEGACY,
        ])
            ->toBase()
            ->first();

        $query = $upload->debtors();

        if ($request->filled('debtor_type') && $request->input('debtor_type') !== 'all') {
            $type = $request->input('debtor_type');

            if ($type === DebtorProfile::MODEL_LEGACY) {
                $query->where(function ($q) {
                    $q->whereDoesntHave('debtorProfile')
                        ->orWhereHas('debtorProfile', fn($sub) => $sub->where('billing_model', DebtorProfile::MODEL_LEGACY));
                });
            } else {
                $query->whereHas('debtorProfile', fn($q) => $q->where('billing_model', $type));
            }
        }

        $stats = (clone $query)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN validation_status = ? THEN 1 ELSE 0 END) as valid,
                SUM(CASE WHEN validation_status = ? THEN 1 ELSE 0 END) as invalid,
                SUM(CASE WHEN validation_status = ? THEN 1 ELSE 0 END) as pending
            ", [Debtor::VALIDATION_VALID, Debtor::VALIDATION_INVALID, Debtor::VALIDATION_PENDING])
            ->first();

        $driver = DB::connection()->getDriverName();
        $blacklistQuery = $upload->debtors()
            ->where('validation_status', Debtor::VALIDATION_INVALID);

        if ($driver === 'pgsql') {
            $blacklisted = $blacklistQuery
                ->whereRaw("validation_errors::text LIKE ?", ['%blacklist%'])
                ->count();
        } else {
            $blacklisted = $blacklistQuery
                ->where('validation_errors', 'like', '%blacklist%')
                ->count();
        }

        $chargebacked = $upload->debtors()
            ->whereHas('billingAttempts', function ($query) {
                $query->where('status', BillingAttempt::STATUS_CHARGEBACKED);
            })
            ->count();

        $meta = $upload->meta ?? [];
        $skipped = $meta['skipped'] ?? null;

        $priceBreakdown = $upload->debtors()
            ->where('validation_status', Debtor::VALIDATION_VALID)
            ->selectRaw('amount, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('amount')
            ->orderByDesc('count')
            ->get()
            ->map(fn($row) => [
                'amount' => (float) $row->amount,
                'count' => (int) $row->count,
                'total' => round((float) $row->total, 2),
            ]);

        $validTotalAmount = $upload->debtors()
            ->where('validation_status', Debtor::VALIDATION_VALID)
            ->sum('amount');

        $cbBreakdown = DB::table('billing_attempts')
            ->join('debtors', 'billing_attempts.debtor_id', '=', 'debtors.id')
            ->where('debtors.upload_id', $upload->id)
            ->selectRaw("
                debtors.amount,
                SUM(CASE WHEN billing_attempts.status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN billing_attempts.status = 'chargebacked' THEN 1 ELSE 0 END) as chargebacks,
                SUM(CASE WHEN billing_attempts.status = 'approved' THEN billing_attempts.amount ELSE 0 END) as approved_volume,
                SUM(CASE WHEN billing_attempts.status = 'chargebacked' THEN billing_attempts.amount ELSE 0 END) as cb_volume
            ")
            ->groupBy('debtors.amount')
            ->orderByDesc('chargebacks')
            ->get()
            ->map(fn($row) => [
                'amount' => (float) $row->amount,
                'approved' => (int) $row->approved,
                'chargebacks' => (int) $row->chargebacks,
                'approved_volume' => round((float) $row->approved_volume, 2),
                'cb_volume' => round((float) $row->cb_volume, 2),
                'cb_rate' => (int) $row->approved > 0
                    ? round((int) $row->chargebacks / (int) $row->approved * 100, 2)
                    : 0,
                'cb_rate_amount' => (float) $row->approved_volume > 0
                    ? round((float) $row->cb_volume / (float) $row->approved_volume * 100, 2)
                    : 0,
            ]);

        return response()->json([
            'data' => [
                'total' => (int) $stats->total,
                'valid' => (int) $stats->valid,
                'invalid' => (int) $stats->invalid - $blacklisted,
                'pending' => (int) $stats->pending,
                'blacklisted' => $blacklisted,
                'chargebacked' => $chargebacked,
                'ready_for_sync' => (clone $query)->readyForSync()->count(),
                'skipped' => $skipped,
                'is_processing' => $upload->isValidationProcessing(),
                'skip_bic_blacklist' => $upload->skip_bic_blacklist ?? false,
                'model_counts' => [
                    'all' => (int) $modelStats->all_count,
                    'legacy' => (int) $modelStats->legacy,
                    'flywheel' => (int) $modelStats->flywheel,
                    'recovery' => (int) $modelStats->recovery,
                ],
                'price_breakdown' => $priceBreakdown,
                'valid_total_amount' => round((float) $validTotalAmount, 2),
                'cb_breakdown' => $cbBreakdown,
            ],
        ]);
    }

    public function reassign(Request $request, Upload $upload): JsonResponse
    {
        $validated = $request->validate([
            'emp_account_id' => 'required|integer|exists:emp_accounts,id',
        ]);

        $targetAccountId = $validated['emp_account_id'];
        $targetAccount = EmpAccount::findOrFail($targetAccountId);

        if (!$targetAccount->is_active) {
            return response()->json([
                'message' => 'Target EMP account is not active.',
            ], 422);
        }

        if ($upload->emp_account_id === $targetAccountId) {
            return response()->json([
                'message' => 'Upload is already assigned to this account.',
            ], 422);
        }

        $previousAccountId = $upload->emp_account_id;

        Log::info('Upload reassign started', [
            'upload_id' => $upload->id,
            'from_account_id' => $previousAccountId,
            'to_account_id' => $targetAccountId,
            'to_account_name' => $targetAccount->name,
            'admin_id' => $request->user()?->id,
        ]);

        try {
            $result = DB::transaction(function () use ($upload, $targetAccountId) {
                $upload->update(['emp_account_id' => $targetAccountId]);

                $debtorsUpdated = Debtor::where('upload_id', $upload->id)
                    ->where(function ($q) use ($targetAccountId) {
                        $q->where('emp_account_id', '!=', $targetAccountId)
                            ->orWhereNull('emp_account_id');
                    })
                    ->update(['emp_account_id' => $targetAccountId]);

                $debtorIds = Debtor::where('upload_id', $upload->id)->pluck('id');

                $pendingBillingUpdated = 0;
                $skippedSubmitted = 0;

                if ($debtorIds->isNotEmpty()) {
                    $pendingBillingUpdated = DB::table('billing_attempts')
                        ->whereIn('debtor_id', $debtorIds)
                        ->where('status', 'pending')
                        ->whereNull('unique_id')
                        ->where(function ($q) use ($targetAccountId) {
                            $q->where('emp_account_id', '!=', $targetAccountId)
                                ->orWhereNull('emp_account_id');
                        })
                        ->update(['emp_account_id' => $targetAccountId]);

                    $skippedSubmitted = DB::table('billing_attempts')
                        ->whereIn('debtor_id', $debtorIds)
                        ->where('status', 'pending')
                        ->whereNotNull('unique_id')
                        ->count();
                }

                return [
                    'debtors_updated' => $debtorsUpdated,
                    'pending_billing_updated' => $pendingBillingUpdated,
                    'skipped_submitted' => $skippedSubmitted,
                ];
            });

            Log::info('Upload reassign completed', [
                'upload_id' => $upload->id,
                'from_account_id' => $previousAccountId,
                'to_account_id' => $targetAccountId,
                'debtors_updated' => $result['debtors_updated'],
                'pending_billing_updated' => $result['pending_billing_updated'],
                'skipped_submitted' => $result['skipped_submitted'],
                'admin_id' => $request->user()?->id,
            ]);

            $upload->load('empAccount');

            $message = "Upload reassigned to {$targetAccount->name}.";
            if ($result['skipped_submitted'] > 0) {
                $message .= " {$result['skipped_submitted']} pending attempts already submitted to EMP were left unchanged.";
            }

            return response()->json([
                'message' => $message,
                'data' => [
                    'upload' => new UploadResource($upload),
                    'debtors_updated' => $result['debtors_updated'],
                    'pending_billing_updated' => $result['pending_billing_updated'],
                    'skipped_submitted' => $result['skipped_submitted'],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Upload reassign failed', [
                'upload_id' => $upload->id,
                'target_account_id' => $targetAccountId,
                'error' => $e->getMessage(),
                'admin_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'Reassign failed. No changes were made.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateSettings(Request $request, Upload $upload): JsonResponse
    {
        $validated = $request->validate([
            'max_billing_amount' => 'nullable|numeric|min:0|max:999999.99',
        ]);

        $previousValue = $upload->max_billing_amount;
        $upload->update($validated);

        Log::info('Upload settings updated', [
            'upload_id' => $upload->id,
            'max_billing_amount' => [
                'from' => $previousValue,
                'to' => $upload->max_billing_amount,
            ],
            'admin_id' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Upload settings updated.',
            'data' => new UploadResource($upload),
        ]);
    }

    public function billingCycles(Upload $upload): JsonResponse
    {
        $cycles = DB::table('billing_attempts')
            ->where('upload_id', $upload->id)
            ->select(
                'attempt_number',
                'status',
                DB::raw('COUNT(*) as count'),
                DB::raw('COALESCE(SUM(amount), 0) as total_amount')
            )
            ->groupBy('attempt_number', 'status')
            ->orderBy('attempt_number')
            ->orderBy('status')
            ->get();

        $grouped = [];
        foreach ($cycles as $row) {
            $cycle = $row->attempt_number;
            if (!isset($grouped[$cycle])) {
                $grouped[$cycle] = [
                    'cycle' => $cycle,
                    'statuses' => [],
                    'total_count' => 0,
                    'total_amount' => 0,
                ];
            }
            $grouped[$cycle]['statuses'][$row->status] = [
                'count' => (int) $row->count,
                'amount' => round((float) $row->total_amount, 2),
            ];
            $grouped[$cycle]['total_count'] += (int) $row->count;
            $grouped[$cycle]['total_amount'] += (float) $row->total_amount;
        }

        foreach ($grouped as &$cycle) {
            $cycle['total_amount'] = round($cycle['total_amount'], 2);
        }

        $totalApprovedAmount = DB::table('billing_attempts')
            ->where('upload_id', $upload->id)
            ->whereIn('status', [BillingAttempt::STATUS_APPROVED, BillingAttempt::STATUS_PENDING])
            ->sum('amount');

        return response()->json([
            'data' => [
                'cycles' => array_values($grouped),
                'total_cycles' => count($grouped),
                'total_billed_amount' => round((float) $totalApprovedAmount, 2),
                'max_billing_amount' => $upload->max_billing_amount ? (float) $upload->max_billing_amount : null,
                'cap_remaining' => $upload->max_billing_amount
                    ? max(0, round((float) $upload->max_billing_amount - (float) $totalApprovedAmount, 2))
                    : null,
            ],
        ]);
    }

    public function destroy(Upload $upload): JsonResponse
    {
        if ($upload->canBeHardDeleted()) {
            Storage::disk('s3')->delete($upload->file_path);
            $upload->forceDelete();
            return response()->json([
                'success'   => true,
                'message'   => 'Uploaded File deleted successfully.',
            ], 200);
        }

        if ($upload->canBeSoftDeleted()) {
            $upload->debtors()->delete();
            $upload->delete();
            return response()->json([
                'success' => true,
                'message' => 'Upload and associated debtors deleted successfully.',
            ], 200);
        }

        return response()->json([
            'success' => false,
            'message' => 'Upload cannot be deleted as it has associated debtors.',
        ], 403);
    }

    public function filterChargebacks(Upload $upload): JsonResponse
    {
        $chargebackedDebtors = $upload->debtors()
            ->whereHas('billingAttempts', function ($query) {
                $query->where('status', BillingAttempt::STATUS_CHARGEBACKED);
            })
            ->get();

        $count = $chargebackedDebtors->count();

        if ($count === 0) {
            return response()->json([
                'message' => 'No chargebacked records found',
                'data' => ['removed' => 0],
            ]);
        }

        foreach ($chargebackedDebtors as $debtor) {
            $debtor->delete();
        }

        return response()->json([
            'message' => "Removed {$count} chargebacked records",
            'data' => ['removed' => $count],
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'nullable|string|min:1|max:60',
        ]);

        $query = $request->input('query', '');

        $cacheKey = 'upload_search:' . md5(strtolower(trim($query)));

        $this->trackCacheKey($cacheKey);

        $uploads = Cache::remember($cacheKey, 300, function () use ($query) {
            return Upload::where('filename', 'like', "%{$query}%")
                ->orWhere('original_filename', 'like', "%{$query}%")
                ->latest()
                ->take(5)
                ->get();
        });

        return response()->json([
            'data' => UploadResource::collection($uploads),
        ]);
    }

    private function shouldProcessAsync($file): bool
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, ['xlsx', 'xls'])) {
            return $file->getSize() > 100 * 1024;
        }

        $lineCount = 0;
        $handle = fopen($file->getPathname(), 'r');
        while (fgets($handle) !== false && $lineCount < self::ASYNC_THRESHOLD + 10) {
            $lineCount++;
        }
        fclose($handle);

        return $lineCount > self::ASYNC_THRESHOLD;
    }

    private function calculateProgress(Upload $upload): float
    {
        if ($upload->total_records === 0) {
            return 0;
        }

        $processed = $upload->processed_records + $upload->failed_records;
        return round(($processed / $upload->total_records) * 100, 2);
    }

    private function trackCacheKey(string $key): void
    {
        $keys = Cache::get('upload_search_keys', []);

        if (!in_array($key, $keys)) {
            $keys[] = $key;
            Cache::put('upload_search_keys', $keys, 86400);
        }
    }
}
