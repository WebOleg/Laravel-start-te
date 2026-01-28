<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDescriptorRequest;
use App\Models\TransactionDescriptor;
use App\Services\DescriptorService;
use Illuminate\Http\JsonResponse;

class DescriptorController extends Controller
{
    protected DescriptorService $service;

    public function __construct(DescriptorService $service)
    {
        $this->service = $service;
    }

    /**
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        // Default first, then by date ascending
        $descriptors = TransactionDescriptor::orderByDesc('is_default')
                                            ->orderBy('year')
                                            ->orderBy('month')
                                            ->get();

        return response()->json($descriptors);
    }

    /**
     * @param StoreDescriptorRequest $request
     * @return JsonResponse
     */
    public function store(StoreDescriptorRequest $request): JsonResponse
    {
        // 2. Use the Service for business logic
        $this->service->ensureSingleDefault($request->is_default);

        $descriptor = TransactionDescriptor::create($request->validated());

        return response()->json($descriptor, 201);
    }

    /**
     * @param StoreDescriptorRequest $request
     * @param TransactionDescriptor $descriptor
     * @return JsonResponse
     */
    public function update(StoreDescriptorRequest $request, TransactionDescriptor $descriptor): JsonResponse
    {
        // 2. Use the Service (passing the ID to ignore self)
        $this->service->ensureSingleDefault($request->is_default, $descriptor->id);

        $descriptor->update($request->validated());

        return response()->json($descriptor);
    }

    /**
     * @param TransactionDescriptor $descriptor
     * @return JsonResponse
     */
    public function destroy(TransactionDescriptor $descriptor): JsonResponse
    {
        $descriptor->delete();
        return response()->json(['message' => 'Deleted successfully']);
    }
}
