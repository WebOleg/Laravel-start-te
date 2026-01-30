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

    public function index(): JsonResponse
    {
        $descriptors = TransactionDescriptor::orderByDesc('is_default')
                                            ->orderBy('year')
                                            ->orderBy('month')
                                            ->get();

        return response()->json(['data' => $descriptors]);
    }

    public function store(StoreDescriptorRequest $request): JsonResponse
    {
        $this->service->ensureSingleDefault($request->is_default);

        $descriptor = TransactionDescriptor::create($request->validated());

        return response()->json(['data' => $descriptor], 201);
    }

    public function update(StoreDescriptorRequest $request, TransactionDescriptor $descriptor): JsonResponse
    {
        $this->service->ensureSingleDefault($request->is_default, $descriptor->id);

        $descriptor->update($request->validated());

        return response()->json(['data' => $descriptor]);
    }

    public function destroy(TransactionDescriptor $descriptor): JsonResponse
    {
        $descriptor->delete();
        return response()->json(['message' => 'Deleted successfully']);
    }
}
