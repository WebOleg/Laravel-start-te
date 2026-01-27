<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDescriptorRequest;
use App\Models\TransactionDescriptor;
use Illuminate\Http\JsonResponse;

class DescriptorController extends Controller
{
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
        $this->handleDefaultToggle($request->is_default);

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
        $this->handleDefaultToggle($request->is_default, $descriptor->id);

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

    /**
     * If setting a new default, un-set any existing default.
     *
     * @param bool $isNewDefault
     * @param int|null $ignoreId
     * @return void
     */
    private function handleDefaultToggle(bool $isNewDefault, ?int $ignoreId = null): void
    {
        if ($isNewDefault) {
            TransactionDescriptor::where('is_default', true)
                                 ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
                                 ->update(['is_default' => false]);
        }
    }
}
