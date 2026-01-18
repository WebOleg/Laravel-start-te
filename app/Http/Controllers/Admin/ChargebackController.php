<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\ChargebackService;
use App\Http\Resources\ChargebackResource;

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
}
