<?php

/**
 * Admin controller for uploads management.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUploadRequest;
use App\Http\Resources\UploadResource;
use App\Models\Upload;
use App\Services\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\JsonResponse;

class UploadController extends Controller
{
    public function __construct(
        private FileUploadService $uploadService
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Upload::withCount('debtors');

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $uploads = $query->latest()->paginate($request->input('per_page', 20));

        return UploadResource::collection($uploads);
    }

    public function show(Upload $upload): UploadResource
    {
        $upload->load(['uploader']);
        $upload->loadCount('debtors');

        return new UploadResource($upload);
    }

    public function store(StoreUploadRequest $request): JsonResponse
    {
        try {
            $result = $this->uploadService->process(
                $request->file('file'),
                $request->user()?->id
            );

            return response()->json([
                'data' => new UploadResource($result['upload']),
                'meta' => [
                    'created' => $result['created'],
                    'failed' => $result['failed'],
                    'errors' => array_slice($result['errors'], 0, 10),
                ],
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
