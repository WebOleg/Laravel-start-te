<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\ChargebackService;
use App\Http\Resources\ChargebackResource;
use App\Models\Upload;

class ChargebackController extends Controller
{
    public function __construct(
        protected ChargebackService $chargebackService,
    )
    {}

    public function index(Request $request)
    {
        $chargebacks = $this->chargebackService->getChargebacks($request);
        return ChargebackResource::collection($chargebacks);
    }

    public function codes(){
        $errorCodes = $this->chargebackService->getUniqueChargebacksErrorCodes();
        return response()->json(['data' => $errorCodes]);
    }

    /**
     * Get chargeback reasons breakdown for a specific upload
     */
    public function uploadReasons(Request $request, Upload $upload)
    {
        $request->validate([
            'emp_account_id' => 'nullable|integer|exists:emp_accounts,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        $data = $this->chargebackService->getUploadChargebackReasons(
            $upload,
            $request->only(['emp_account_id', 'date_from', 'date_to'])
        );

        return response()->json(['data' => $data]);
    }

    /**
     * Get individual records for a specific chargeback reason code within an upload
     */
    public function uploadReasonRecords(Request $request, Upload $upload, string $code)
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $records = $this->chargebackService->getUploadChargebackRecordsByCode(
            $upload,
            $code,
            $request->input('per_page', 20)
        );

        return ChargebackResource::collection($records);
    }
}
