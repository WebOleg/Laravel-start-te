<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDescriptorRequest;
use App\Http\Resources\DescriptorResource;
use App\Models\TransactionDescriptor;
use App\Services\DescriptorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

class DescriptorController extends Controller
{
    protected DescriptorService $service;

    public function __construct(DescriptorService $service)
    {
        $this->service = $service;
    }

    /**
     * List all billing descriptors.
     *
     * @OA\Get(
     *     path="/api/admin/billing/descriptors",
     *     summary="List billing descriptors",
     *     description="Returns a paginated list of transaction descriptors ordered by default status and date. Each descriptor defines the text that appears on a debtor's bank statement for SEPA transactions.",
     *     tags={"Descriptors"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="per_page", in="query", required=false, description="Results per page (max 100)", @OA\Schema(type="integer", default=20, maximum=100)),
     *     @OA\Response(
     *         response=200,
     *         description="Paginated list of descriptors",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Descriptor")),
     *             @OA\Property(property="links", type="object"),
     *             @OA\Property(property="meta", type="object",
     *                 @OA\Property(property="current_page", type="integer", example=1),
     *                 @OA\Property(property="last_page", type="integer", example=1),
     *                 @OA\Property(property="per_page", type="integer", example=20),
     *                 @OA\Property(property="total", type="integer", example=5)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function index(Request $request)
    {
        $descriptors = TransactionDescriptor::with('empAccount')
            ->orderByDesc('is_default')
            ->orderBy('year')
            ->orderBy('month')
            ->paginate(min((int) $request->input('per_page', 20), 100));

        return DescriptorResource::collection($descriptors);
    }

    /**
     * Create a new billing descriptor.
     *
     * @OA\Post(
     *     path="/api/admin/billing/descriptors",
     *     summary="Create a billing descriptor",
     *     description="Creates a new transaction descriptor. If marked as default, any existing default for the same EMP account scope is automatically unset. Supports global defaults (emp_account_id=null) and per-account defaults.",
     *     tags={"Descriptors"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/DescriptorInput")
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Descriptor created",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/Descriptor")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(StoreDescriptorRequest $request): JsonResponse
    {
        $this->service->ensureSingleDefault(
            $request->is_default,
            empAccountId: $request->emp_account_id
        );

        $descriptor = TransactionDescriptor::create($request->validated());

        $this->service->invalidateCache($request->emp_account_id);

        return response()->json(['data' => $descriptor], 201);
    }

    /**
     * Show a single descriptor.
     *
     * @OA\Get(
     *     path="/api/admin/billing/descriptors/{descriptor}",
     *     summary="Get a single billing descriptor",
     *     description="Returns a specific transaction descriptor by ID.",
     *     tags={"Descriptors"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="descriptor", in="path", required=true, description="Descriptor ID", @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Descriptor details",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/Descriptor")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Descriptor not found")
     * )
     */
    public function show(TransactionDescriptor $descriptor): JsonResponse
    {
        return response()->json(['data' => new DescriptorResource($descriptor->load('empAccount'))]);
    }

    /**
     * Update a billing descriptor.
     *
     * @OA\Put(
     *     path="/api/admin/billing/descriptors/{descriptor}",
     *     summary="Update a billing descriptor",
     *     description="Updates an existing transaction descriptor. If marked as default, any other default for the same EMP account scope is automatically unset. Invalidates descriptor cache.",
     *     tags={"Descriptors"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="descriptor", in="path", required=true, description="Descriptor ID", @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/DescriptorInput")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Descriptor updated",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/Descriptor")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Descriptor not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(StoreDescriptorRequest $request, TransactionDescriptor $descriptor): JsonResponse
    {
        $this->service->ensureSingleDefault(
            $request->is_default,
            ignoreId: $descriptor->id,
            empAccountId: $request->emp_account_id
        );

        $descriptor->update($request->validated());

        $this->service->invalidateCache($request->emp_account_id);

        return response()->json(['data' => $descriptor]);
    }

    /**
     * Delete a billing descriptor.
     *
     * @OA\Delete(
     *     path="/api/admin/billing/descriptors/{descriptor}",
     *     summary="Delete a billing descriptor",
     *     description="Permanently deletes a transaction descriptor and invalidates the descriptor cache.",
     *     tags={"Descriptors"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="descriptor", in="path", required=true, description="Descriptor ID", @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Descriptor deleted",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Deleted successfully")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Descriptor not found")
     * )
     */
    public function destroy(TransactionDescriptor $descriptor): JsonResponse
    {
        $empAccountId = $descriptor->emp_account_id;
        $descriptor->delete();

        $this->service->invalidateCache($empAccountId);

        return response()->json(['message' => 'Deleted successfully']);
    }
}
