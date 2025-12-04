<?php

/**
 * Admin controller for uploads management.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UploadResource;
use App\Models\Upload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UploadController extends Controller
{
    /**
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Upload::withCount('debtors');

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $uploads = $query->latest()->paginate($request->input('per_page', 20));

        return UploadResource::collection($uploads);
    }

    /**
     * @return UploadResource
     */
    public function show(Upload $upload): UploadResource
    {
        $upload->load(['uploader']);
        $upload->loadCount('debtors');

        return new UploadResource($upload);
    }
}
