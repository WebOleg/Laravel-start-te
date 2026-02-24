<?php

/**
 * Controller for managing Tether Instances.
 * Provides list for admin panel instance selector.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TetherInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TetherInstanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = TetherInstance::with('acquirerAccount:id,name,slug,is_active');

        if ($request->boolean('active_only', false)) {
            $query->where('is_active', true);
        }

        $instances = $query->ordered()->get()->map(fn ($instance) => [
            'id' => $instance->id,
            'name' => $instance->name,
            'slug' => $instance->slug,
            'acquirer_type' => $instance->acquirer_type,
            'is_active' => $instance->is_active,
            'emp_account' => $instance->acquirerAccount ? [
                'id' => $instance->acquirerAccount->id,
                'name' => $instance->acquirerAccount->name,
                'slug' => $instance->acquirerAccount->slug,
            ] : null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $instances,
        ]);
    }
}
