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
use OpenApi\Annotations as OA;

class TetherInstanceController extends Controller
{
    /**
     * List all Tether instances.
     *
     * @OA\Get(
     *     path="/api/admin/tether-instances",
     *     summary="List Tether instances",
     *     description="Returns all Tether instances with their associated acquirer (EMP) account. Used for the admin panel instance selector dropdown. Optionally filter to active instances only.",
     *     tags={"Tether Instances"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="active_only", in="query", required=false, description="Return only active instances", @OA\Schema(type="boolean", default=false)),
     *     @OA\Response(
     *         response=200,
     *         description="List of Tether instances",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Production EU"),
     *                     @OA\Property(property="slug", type="string", example="production-eu"),
     *                     @OA\Property(property="acquirer_type", type="string", example="emp"),
     *                     @OA\Property(property="is_active", type="boolean", example=true),
     *                     @OA\Property(property="emp_account", type="object", nullable=true,
     *                         @OA\Property(property="id", type="integer", example=1),
     *                         @OA\Property(property="name", type="string", example="Primary Account"),
     *                         @OA\Property(property="slug", type="string", example="primary-account")
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
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
