<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDescriptorRequest;
use App\Http\Resources\DescriptorResource;
use App\Models\TransactionDescriptor;
use App\Services\DescriptorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DescriptorController extends Controller
{
    protected DescriptorService $service;

    public function __construct(DescriptorService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $descriptors = TransactionDescriptor::with('empAccount')
                                            ->orderByDesc('is_default')
                                            ->orderBy('year')
                                            ->orderBy('month')
                                            ->paginate(min((int) $request->input('per_page', 20), 100));

        return DescriptorResource::collection($descriptors);
    }

    public function store(StoreDescriptorRequest $request): JsonResponse
    {
        $this->service->ensureSingleDefault(
            $request->is_default,
            empAccountId: $request->emp_account_id
        );

        $descriptor = TransactionDescriptor::create($request->validated());

        return response()->json(['data' => $descriptor], 201);
    }

    public function update(StoreDescriptorRequest $request, TransactionDescriptor $descriptor): JsonResponse
    {
        $this->service->ensureSingleDefault(
            $request->is_default,
            ignoreId: $descriptor->id,
            empAccountId: $request->emp_account_id
        );

        $descriptor->update($request->validated());

        return response()->json(['data' => $descriptor]);
    }

    public function destroy(TransactionDescriptor $descriptor): JsonResponse
    {
        $descriptor->delete();
        return response()->json(['message' => 'Deleted successfully']);
    }
}
