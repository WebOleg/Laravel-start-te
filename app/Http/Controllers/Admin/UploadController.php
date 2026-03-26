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
use App\Services\BillingResyncService;
use App\Jobs\ProcessValidationJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OpenApi\Annotations as OA;

class UploadController extends Controller
{
    private const ASYNC_THRESHOLD = 100;

    public function __construct(
        private FileUploadService $uploadService,
        private FilePreValidationService $preValidationService,
        private DebtorValidationService $validationService,
        private BillingResyncService $resyncService,
    ) {}

    /**
     * List uploads.
     *
     * @OA\Get(
     *     path="/api/admin/uploads",
     *     summary="List uploads",
     *     description="Returns a paginated list of uploads with debtor counts, validation stats, billing stats (approved/chargeback counts and amounts). Supports filtering by status and account.",
     *     tags={"Uploads"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="status", in="query", required=false, @OA\Schema(type="string", enum={"pending", "processing", "completed", "failed"})),
     *     @OA\Parameter(name="emp_account_id", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="tether_instance_id", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=20, maximum=100)),
     *     @OA\Response(
     *         response=200,
     *         description="Paginated uploads",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Upload")),
     *             @OA\Property(property="links", type="object"),
     *             @OA\Property(property="meta", type="object")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
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

    /**
     * Show upload details.
     *
     * @OA\Get(
     *     path="/api/admin/uploads/{upload}",
     *     summary="Get upload details",
     *     description="Returns detailed upload information including uploader, EMP account, Tether instance, debtor counts (valid, invalid, BAV stats), billing amounts, ready-for-sync counts, and enriched billing run history with per-run recovery stats.",
     *     tags={"Uploads"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="upload", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Upload details",
     *         @OA\JsonContent(@OA\Property(property="data", ref="#/components/schemas/Upload"))
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Upload not found")
     * )
     */
    public function show(Upload $upload): UploadResource
    {
        $excludedCbCodes = config('tether.chargeback.excluded_cb_reason_codes', []);

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
            'billingAttempts as chargeback_count' => function ($q) use ($excludedCbCodes) {
                $q->where('status', BillingAttempt::STATUS_CHARGEBACKED);
                if (!empty($excludedCbCodes)) {
                    $q->where(function ($inner) use ($excludedCbCodes) {
                        $inner->whereNotIn('chargeback_reason_code', $excludedCbCodes)
                            ->orWhereNull('chargeback_reason_code');
                    });
                }
            },
        ]);

        $upload->loadSum(['billingAttempts as approved_amount' => function ($q) {
            $q->where('status', BillingAttempt::STATUS_APPROVED);
        }], 'amount');

        $upload->loadSum(['billingAttempts as chargeback_amount' => function ($q) use ($excludedCbCodes) {
            $q->where('status', BillingAttempt::STATUS_CHARGEBACKED);
            if (!empty($excludedCbCodes)) {
                $q->where(function ($inner) use ($excludedCbCodes) {
                    $inner->whereNotIn('chargeback_reason_code', $excludedCbCodes)
                        ->orWhereNull('chargeback_reason_code');
                });
            }
        }], 'amount');

        $this->enrichShowStats($upload);

        return new UploadResource($upload);
    }

    /**
     * Upload a file and create debtors.
     *
     * @OA\Post(
     *     path="/api/admin/uploads",
     *     summary="Upload a file",
     *     description="Uploads a CSV/XLSX file, pre-validates headers (IBAN, amount, name required), auto-maps columns, and creates debtor records. Small files (<100 rows) are processed synchronously; larger files are queued. Supports billing model selection, EMP account/Tether instance assignment, global IBAN lock, cooldown, and chargeback check skip.",
     *     tags={"Uploads"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"file"},
     *                 @OA\Property(property="file", type="string", format="binary"),
     *                 @OA\Property(property="billing_model", type="string", enum={"legacy", "flywheel", "recovery"}, default="legacy"),
     *                 @OA\Property(property="emp_account_id", type="integer", nullable=true),
     *                 @OA\Property(property="tether_instance_id", type="integer", nullable=true),
     *                 @OA\Property(property="apply_global_lock", type="boolean", default=false, description="Prevent cross-instance IBAN billing"),
     *                 @OA\Property(property="is_30d_cool", type="boolean", nullable=true, description="Enable/disable 30-day cooldown"),
     *                 @OA\Property(property="skip_chargeback_check", type="boolean", default=false),
     *                 @OA\Property(property="async", type="boolean", default=false, description="Force async processing")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="File processed synchronously",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/Upload"),
     *             @OA\Property(property="meta", type="object",
     *                 @OA\Property(property="queued", type="boolean", example=false),
     *                 @OA\Property(property="created", type="integer", example=95),
     *                 @OA\Property(property="failed", type="integer", example=5),
     *                 @OA\Property(property="skipped", type="object"),
     *                 @OA\Property(property="errors", type="array", @OA\Items(type="object"))
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=202,
     *         description="File queued for async processing",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/Upload"),
     *             @OA\Property(property="meta", type="object",
     *                 @OA\Property(property="queued", type="boolean", example=true),
     *                 @OA\Property(property="message", type="string", example="File queued for processing. Check status for updates.")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="File validation failed")
     * )
     */
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
            $is30dCool = $request->has('is_30d_cool') ? $request->boolean('is_30d_cool') : null;
            $skipChargebackCheck = $request->boolean('skip_chargeback_check');

            $preValidation = $this->preValidationService->validate($file);
            if (!$preValidation['valid']) {
                $response = [
                    'message' => 'File validation failed.',
                    'errors' => $preValidation['errors'],
                ];

                if (!empty($preValidation['warnings'])) {
                    $response['warnings'] = $preValidation['warnings'];
                }
                if (!empty($preValidation['suggestions'])) {
                    $response['suggestions'] = $preValidation['suggestions'];
                }

                return response()->json($response, 422);
            }

            $forceAsync = $request->boolean('async');

            if ($forceAsync || $this->shouldProcessAsync($file)) {
                $result = $this->uploadService->processAsync(
                    $file,
                    $request->user()?->id,
                    $billingModel,
                    $empAccountId,
                    $applyGlobalLock,
                    $tetherInstanceId,
                    $is30dCool,
                    $skipChargebackCheck
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
                $tetherInstanceId,
                $is30dCool,
                $skipChargebackCheck
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

    /**
     * Get upload processing status.
     *
     * @OA\Get(
     *     path="/api/admin/uploads/{upload}/status",
     *     summary="Get upload processing status",
     *     description="Returns current processing progress for an upload including record counts and completion percentage.",
     *     tags={"Uploads"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="upload", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Processing status",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="status", type="string", example="processing"),
     *                 @OA\Property(property="total_records", type="integer", example=1000),
     *                 @OA\Property(property="processed_records", type="integer", example=500),
     *                 @OA\Property(property="failed_records", type="integer", example=10),
     *                 @OA\Property(property="debtors_count", type="integer", example=490),
     *                 @OA\Property(property="progress", type="number", format="float", example=51.0),
     *                 @OA\Property(property="is_complete", type="boolean", example=false)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Upload not found")
     * )
     */
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

    /**
     * List debtors for an upload.
     *
     * @OA\Get(
     *     path="/api/admin/uploads/{upload}/debtors",
     *     summary="List debtors for an upload",
     *     description="Returns a paginated list of debtors belonging to the upload. Supports filtering by billing model, validation status, chargeback exclusion, and text search.",
     *     tags={"Uploads"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="upload", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="debtor_type", in="query", required=false, @OA\Schema(type="string", enum={"all", "legacy", "flywheel", "recovery"})),
     *     @OA\Parameter(name="validation_status", in="query", required=false, @OA\Schema(type="string", enum={"pending", "valid", "invalid"})),
     *     @OA\Parameter(name="exclude_chargebacked", in="query", required=false, @OA\Schema(type="boolean")),
     *     @OA\Parameter(name="search", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=50)),
     *     @OA\Response(
     *         response=200,
     *         description="Paginated debtors",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Debtor")),
     *             @OA\Property(property="links", type="object"),
     *             @OA\Property(property="meta", type="object")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Upload not found")
     * )
     */
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

    /**
     * Run validation on upload debtors.
     *
     * @OA\Post(
     *     path="/api/admin/uploads/{upload}/validate",
     *     summary="Run validation on upload debtors",
     *     description="Dispatches validation jobs for all debtors in the upload. Resets validation state for unbilled debtors. Optionally updates skip_bic_blacklist and skip_chargeback_check flags before validating.",
     *     tags={"Uploads"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="upload", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="skip_bic_blacklist", type="boolean", nullable=true),
     *             @OA\Property(property="skip_chargeback_check", type="boolean", nullable=true)
     *         )
     *     ),
     *     @OA\Response(response=202, description="Validation started", @OA\JsonContent(@OA\Property(property="message", type="string", example="Validation started"), @OA\Property(property="status", type="string", example="processing"))),
     *     @OA\Response(response=200, description="Validation already in progress", @OA\JsonContent(@OA\Property(property="message", type="string", example="Validation already in progress."), @OA\Property(property="status", type="string", example="processing"))),
     *     @OA\Response(response=422, description="Upload still processing"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Upload not found")
     * )
     */
    public function validate(Request $request, Upload $upload): JsonResponse
    {
        $request->validate([
            'skip_bic_blacklist' => 'nullable|boolean',
            'skip_chargeback_check' => 'nullable|boolean',
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

        $updateData = [];

        if ($request->has('skip_bic_blacklist')) {
            $updateData['skip_bic_blacklist'] = $request->boolean('skip_bic_blacklist');
        }

        if ($request->has('skip_chargeback_check')) {
            $updateData['skip_chargeback_check'] = $request->boolean('skip_chargeback_check');
        }

        if (!empty($updateData)) {
            $upload->update($updateData);
        }

        $upload->debtors()
            ->where('validation_status', '!=', 'chargebacked')
            ->whereDoesntHave('billingAttempts', function ($q) {
                $q->whereIn('status', [
                    BillingAttempt::STATUS_APPROVED,
                    BillingAttempt::STATUS_PENDING,
                ]);
            })
            ->update([
                'validated_at'       => null,
                'validation_status'  => Debtor::VALIDATION_PENDING,
                'validation_errors'  => null,
            ]);

        ProcessValidationJob::dispatch($upload);

        return response()->json([
            'message' => 'Validation started',
            'status' => 'processing',
        ], 202);
    }

    /**
     * Get detailed validation stats for an upload.
     *
     * @OA\Get(
     *     path="/api/admin/uploads/{upload}/validation-stats",
     *     summary="Get validation stats for an upload",
     *     description="Returns detailed validation statistics including counts by status, billing model breakdown, blacklisted/chargebacked counts, price point breakdown with CB rates, and resync state.",
     *     tags={"Uploads"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="upload", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="debtor_type", in="query", required=false, @OA\Schema(type="string", enum={"all", "legacy", "flywheel", "recovery"})),
     *     @OA\Response(
     *         response=200,
     *         description="Validation statistics",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="total", type="integer", example=1000),
     *                 @OA\Property(property="valid", type="integer", example=900),
     *                 @OA\Property(property="invalid", type="integer", example=70),
     *                 @OA\Property(property="pending", type="integer", example=10),
     *                 @OA\Property(property="blacklisted", type="integer", example=20),
     *                 @OA\Property(property="chargebacked", type="integer", example=30),
     *                 @OA\Property(property="ready_for_sync", type="integer", example=850),
     *                 @OA\Property(property="current_resync_count", type="integer", example=0),
     *                 @OA\Property(property="skipped", type="object", nullable=true),
     *                 @OA\Property(property="is_processing", type="boolean", example=false),
     *                 @OA\Property(property="skip_bic_blacklist", type="boolean", example=false),
     *                 @OA\Property(property="skip_chargeback_check", type="boolean", example=false),
     *                 @OA\Property(property="model_counts", type="object",
     *                     @OA\Property(property="all", type="integer", example=1000),
     *                     @OA\Property(property="legacy", type="integer", example=800),
     *                     @OA\Property(property="flywheel", type="integer", example=150),
     *                     @OA\Property(property="recovery", type="integer", example=50)
     *                 ),
     *                 @OA\Property(property="price_breakdown", type="array", @OA\Items(
     *                     @OA\Property(property="amount", type="number", format="float", example=49.99),
     *                     @OA\Property(property="count", type="integer", example=500),
     *                     @OA\Property(property="total", type="number", format="float", example=24995.00)
     *                 )),
     *                 @OA\Property(property="valid_total_amount", type="number", format="float", example=44991.00),
     *                 @OA\Property(property="cb_breakdown", type="array", @OA\Items(
     *                     @OA\Property(property="amount", type="number", format="float", example=49.99),
     *                     @OA\Property(property="approved", type="integer", example=400),
     *                     @OA\Property(property="chargebacks", type="integer", example=20),
     *                     @OA\Property(property="approved_volume", type="number", format="float", example=19996.00),
     *                     @OA\Property(property="cb_volume", type="number", format="float", example=999.80),
     *                     @OA\Property(property="cb_rate", type="number", format="float", example=5.0),
     *                     @OA\Property(property="cb_rate_amount", type="number", format="float", example=5.0)
     *                 ))
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Upload not found")
     * )
     */
    public function validationStats(Upload $upload, Request $request): JsonResponse
    {
        $excludedCbCodes = config('tether.chargeback.excluded_cb_reason_codes', []);

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
            ->whereHas('billingAttempts', function ($query) use ($excludedCbCodes) {
                $query->where('status', BillingAttempt::STATUS_CHARGEBACKED);
                if (!empty($excludedCbCodes)) {
                    $query->where(function ($inner) use ($excludedCbCodes) {
                        $inner->whereNotIn('chargeback_reason_code', $excludedCbCodes)
                            ->orWhereNull('chargeback_reason_code');
                    });
                }
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
            ->where('debtors.upload_id', $upload->id);

        if (!empty($excludedCbCodes)) {
            $cbBreakdown = $cbBreakdown->where(function ($q) use ($excludedCbCodes) {
                $q->whereNotIn('billing_attempts.chargeback_reason_code', $excludedCbCodes)
                    ->orWhereNull('billing_attempts.chargeback_reason_code');
            });
        }

        $cbBreakdown = $cbBreakdown->selectRaw("
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

        $isResyncProcessing = Cache::has("billing_resync_{$upload->id}")
            && count($upload->billing_runs ?? []) > 0;

        $currentResyncCount = 0;
        if ($isResyncProcessing) {
            $currentResyncCount = $this->resyncService->getResyncableDebtors($upload)->count();
        }

        return response()->json([
            'data' => [
                'total' => (int) $stats->total,
                'valid' => (int) $stats->valid,
                'invalid' => (int) $stats->invalid - $blacklisted,
                'pending' => (int) $stats->pending,
                'blacklisted' => $blacklisted,
                'chargebacked' => $chargebacked,
                'ready_for_sync' => (clone $query)->readyForSync()->count(),
                'current_resync_count' => $currentResyncCount,
                'skipped' => $skipped,
                'is_processing' => $upload->isValidationProcessing(),
                'skip_bic_blacklist' => $upload->skip_bic_blacklist ?? false,
                'skip_chargeback_check' => $upload->skip_chargeback_check ?? false,
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

    /**
     * Reassign upload to a different EMP account.
     *
     * @OA\Post(
     *     path="/api/admin/uploads/{upload}/reassign",
     *     summary="Reassign upload to a different EMP account",
     *     description="Reassigns the upload and all its debtors to a new EMP account. Only allowed if the upload has no billing attempts. Submitted attempts (with unique_id) are left unchanged.",
     *     tags={"Uploads"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="upload", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"emp_account_id"}, @OA\Property(property="emp_account_id", type="integer", example=2))),
     *     @OA\Response(
     *         response=200,
     *         description="Upload reassigned",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Upload reassigned to Primary Account."),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="upload", ref="#/components/schemas/Upload"),
     *                 @OA\Property(property="debtors_updated", type="integer", example=500),
     *                 @OA\Property(property="pending_billing_updated", type="integer", example=10),
     *                 @OA\Property(property="skipped_submitted", type="integer", example=2)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=422, description="Cannot reassign: upload has billing attempts or already assigned"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Upload not found"),
     *     @OA\Response(response=500, description="Transaction failed")
     * )
     */
    public function reassign(Request $request, Upload $upload): JsonResponse
    {
        $validated = $request->validate([
            'emp_account_id' => 'required|integer|exists:emp_accounts,id',
        ]);

        $targetAccountId = $validated['emp_account_id'];
        $targetAccount = EmpAccount::findOrFail($targetAccountId);

        // Block reassign if upload has any billing attempts.
        $hasBillingAttempts = $upload->billingAttempts()->exists();
        if ($hasBillingAttempts) {
            return response()->json([
                'message' => 'Cannot reassign: this upload already has billing attempts. Account can only be changed before any payment is submitted to EMP.',
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

    /**
     * Update upload settings.
     *
     * @OA\Patch(
     *     path="/api/admin/uploads/{upload}/settings",
     *     summary="Update upload settings",
     *     description="Updates configurable upload settings such as max_billing_amount (billing cap per debtor).",
     *     tags={"Uploads"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="upload", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(@OA\Property(property="max_billing_amount", type="number", format="float", nullable=true, minimum=0, maximum=999999.99, example=500.00))),
     *     @OA\Response(response=200, description="Settings updated", @OA\JsonContent(@OA\Property(property="message", type="string", example="Upload settings updated."), @OA\Property(property="data", ref="#/components/schemas/Upload"))),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Upload not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
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

    /**
     * Get billing cycles for an upload.
     *
     * @OA\Get(
     *     path="/api/admin/uploads/{upload}/billing-cycles",
     *     summary="Get billing cycles for an upload",
     *     description="Returns billing attempt statistics grouped by attempt_number (cycle) and status, with total billed amount and billing cap remaining.",
     *     tags={"Uploads"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="upload", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Billing cycles",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="cycles", type="array", @OA\Items(
     *                     @OA\Property(property="cycle", type="integer", example=1),
     *                     @OA\Property(property="statuses", type="object", example={"approved": {"count": 400, "amount": 19960.00}, "declined": {"count": 50, "amount": 2499.50}}),
     *                     @OA\Property(property="total_count", type="integer", example=500),
     *                     @OA\Property(property="total_amount", type="number", format="float", example=24995.00)
     *                 )),
     *                 @OA\Property(property="total_cycles", type="integer", example=2),
     *                 @OA\Property(property="total_billed_amount", type="number", format="float", example=39960.00),
     *                 @OA\Property(property="max_billing_amount", type="number", format="float", nullable=true, example=50000.00),
     *                 @OA\Property(property="cap_remaining", type="number", format="float", nullable=true, example=10040.00)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Upload not found")
     * )
     */
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

    /**
     * Delete an upload.
     *
     * @OA\Delete(
     *     path="/api/admin/uploads/{upload}",
     *     summary="Delete an upload",
     *     description="Hard-deletes upload and S3 file if no debtors exist. Soft-deletes upload and debtors if no billing attempts exist. Returns 403 if billing attempts exist.",
     *     tags={"Uploads"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="upload", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Upload deleted", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="message", type="string", example="Uploaded File deleted successfully."))),
     *     @OA\Response(response=403, description="Cannot delete (has billing attempts)", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=false), @OA\Property(property="message", type="string"))),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Upload not found")
     * )
     */
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

    /**
     * Filter and remove chargebacked debtors.
     *
     * @OA\Post(
     *     path="/api/admin/uploads/{upload}/filter-chargebacks",
     *     summary="Remove chargebacked debtors from upload",
     *     description="Soft-deletes all debtors in the upload that have at least one chargebacked billing attempt.",
     *     tags={"Uploads"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="upload", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Chargebacked debtors removed",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Removed 15 chargebacked records"),
     *             @OA\Property(property="data", type="object", @OA\Property(property="removed", type="integer", example=15))
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Upload not found")
     * )
     */
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

    /**
     * Search uploads.
     *
     * @OA\Get(
     *     path="/api/admin/uploads/search",
     *     summary="Search uploads by filename",
     *     description="Returns up to 5 uploads matching the filename query. Results are cached for 5 minutes.",
     *     tags={"Uploads"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="query", in="query", required=false, @OA\Schema(type="string", minLength=1, maxLength=60)),
     *     @OA\Response(
     *         response=200,
     *         description="Search results",
     *         @OA\JsonContent(@OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Upload")))
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
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

    /**
     * Set cooldown for an upload.
     *
     * @OA\Patch(
     *     path="/api/admin/uploads/{upload}/cooldown",
     *     summary="Set 30-day cooldown for an upload",
     *     description="Enables or disables the 30-day cooling period. Only applicable to Legacy billing model uploads. Disabling cooldown enables resync functionality.",
     *     tags={"Uploads"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="upload", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"is_30d_cool"}, @OA\Property(property="is_30d_cool", type="boolean"))),
     *     @OA\Response(response=200, description="Cooldown updated", @OA\JsonContent(@OA\Property(property="data", ref="#/components/schemas/Upload"))),
     *     @OA\Response(response=422, description="Not applicable to non-Legacy uploads"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Upload not found")
     * )
     */
    public function setCooldown(Request $request, Upload $upload): JsonResponse
    {
        $request->validate([
            'is_30d_cool' => 'required|boolean',
        ]);

        if ($request->boolean('is_30d_cool') && $upload->billing_model !== BillingModel::Legacy->value) {
            return response()->json([
                'message' => 'The 30-day cooling period is only applicable to Legacy billing model uploads. ' .
                             'Flywheel and Recovery models manage their own billing cycles independently.',
            ], 422);
        }

        $upload->update([
            'is_30d_cool' => $request->boolean('is_30d_cool'),
        ]);

        return response()->json([
            'data' => new UploadResource($upload->fresh()),
        ]);
    }

    private function enrichShowStats(Upload $upload): void
    {
        if ($upload->is_30d_cool === false) {
            $resyncEligible = $this->resyncService->getResyncableDebtors($upload);

            $upload->setAttribute('ready_for_sync_count', $resyncEligible->count());
            $upload->setAttribute('ready_for_sync_amount', round((float) (clone $resyncEligible)->sum('amount'), 2));
        } else {
            $upload->setAttribute('ready_for_sync_count', $upload->debtors()->readyForSync()->count());
            $upload->setAttribute('ready_for_sync_amount', round(
                (float) $upload->debtors()->readyForSync()->sum('amount'),
                2
            ));
        }

        $billingRuns = $upload->billing_runs ?? [];

        if (!empty($billingRuns)) {
            $approvedAttempts = $upload->billingAttempts()
                ->where('status', BillingAttempt::STATUS_APPROVED)
                ->select(['amount', 'created_at'])
                ->get();

            $billingRuns = array_map(function (array $run) use ($approvedAttempts) {
                try {
                    $startedAt   = isset($run['started_at'])   ? \Carbon\Carbon::parse($run['started_at'])   : null;
                    $completedAt = isset($run['completed_at']) ? \Carbon\Carbon::parse($run['completed_at']) : null;
                } catch (\Carbon\Exceptions\InvalidFormatException $e) {
                    return array_merge($run, [
                        'recovered_count'  => 0,
                        'recovered_amount' => 0.0,
                    ]);
                }

                $runAttempts = $approvedAttempts->filter(function ($attempt) use ($startedAt, $completedAt) {
                    if (!$startedAt) {
                        return false;
                    }
                    return $attempt->created_at->gte($startedAt)
                        && (!$completedAt || $attempt->created_at->lte($completedAt));
                });

                return array_merge($run, [
                    'recovered_count'  => $runAttempts->count(),
                    'recovered_amount' => round((float) $runAttempts->sum('amount'), 2),
                ]);
            }, $billingRuns);
        }

        $upload->setAttribute('enriched_billing_runs', $billingRuns);
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
