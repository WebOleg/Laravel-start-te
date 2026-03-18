<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWebhookRelayRequest;
use App\Http\Resources\WebhookRelayResource;
use App\Models\WebhookRelay;
use App\Services\WebhookRelayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Annotations as OA;

class WebhookRelayController extends Controller
{
    protected WebhookRelayService $service;

    public function __construct(WebhookRelayService $service)
    {
        $this->service = $service;
    }

    /**
     * List webhook relays.
     *
     * @OA\Get(
     *     path="/api/admin/webhook-relays",
     *     summary="List webhook relays",
     *     description="Returns a paginated list of webhook relay configurations with their associated EMP accounts. Each relay proxies incoming webhooks from a custom domain to a target URL.",
     *     tags={"Webhook Relays"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="per_page", in="query", required=false, description="Results per page (max 100)", @OA\Schema(type="integer", default=20, maximum=100)),
     *     @OA\Response(
     *         response=200,
     *         description="Paginated webhook relays",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/WebhookRelay")),
     *             @OA\Property(property="links", type="object"),
     *             @OA\Property(property="meta", type="object",
     *                 @OA\Property(property="current_page", type="integer", example=1),
     *                 @OA\Property(property="last_page", type="integer", example=1),
     *                 @OA\Property(property="per_page", type="integer", example=20),
     *                 @OA\Property(property="total", type="integer", example=3)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function index(Request $request)
    {
        $relays = WebhookRelay::with('empAccounts')
                              ->orderByDesc('created_at')
                              ->paginate(min((int) $request->input('per_page', 20), 100));

        return WebhookRelayResource::collection($relays);
    }

    /**
     * Create a webhook relay.
     *
     * @OA\Post(
     *     path="/api/admin/webhook-relays",
     *     summary="Create a webhook relay",
     *     description="Creates a new webhook relay with a custom domain and target URL. Associates it with one or more EMP accounts. Automatically provisions an SSL certificate and deploys the Nginx proxy configuration to the remote relay server.",
     *     tags={"Webhook Relays"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/WebhookRelayInput")
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Relay created and deployed",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/WebhookRelay")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error or duplicate domain")
     * )
     */
    public function store(StoreWebhookRelayRequest $request): JsonResponse
    {
        $this->service->ensureUniqueDomain($request->domain);

        $relay = WebhookRelay::create($request->only(['domain', 'target']));

        // Attach the multiple accounts to the pivot table
        $relay->empAccounts()->sync($request->emp_account_ids);

        $this->service->deployProxies();

        // Load the relationship before returning so the API response includes them
        $relay->load('empAccounts');

        return response()->json(['data' => new WebhookRelayResource($relay)], 201);
    }

    /**
     * Update a webhook relay.
     *
     * @OA\Put(
     *     path="/api/admin/webhook-relays/{webhook_relay}",
     *     summary="Update a webhook relay",
     *     description="Updates an existing webhook relay's domain, target URL, and EMP account associations. Re-deploys the Nginx proxy configuration after update. If the domain changed, provisions a new SSL certificate.",
     *     tags={"Webhook Relays"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="webhook_relay", in="path", required=true, description="Webhook Relay ID", @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/WebhookRelayInput")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Relay updated and redeployed",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/WebhookRelay")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Relay not found"),
     *     @OA\Response(response=422, description="Validation error or duplicate domain")
     * )
     */
    public function update(StoreWebhookRelayRequest $request, WebhookRelay $webhook_relay): JsonResponse
    {
        $this->service->ensureUniqueDomain($request->domain, $webhook_relay->id);

        $webhook_relay->update($request->only(['domain', 'target']));

        // Sync updates the pivot table (removes missing IDs, adds new ones)
        $webhook_relay->empAccounts()->sync($request->emp_account_ids);

        $this->service->deployProxies();

        $webhook_relay->load('empAccounts');

        return response()->json(['data' => new WebhookRelayResource($webhook_relay)]);
    }

    /**
     * Delete a webhook relay.
     *
     * @OA\Delete(
     *     path="/api/admin/webhook-relays/{webhook_relay}",
     *     summary="Delete a webhook relay",
     *     description="Deletes a webhook relay and redeploys the Nginx configuration to remove the proxy block from the remote relay server.",
     *     tags={"Webhook Relays"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="webhook_relay", in="path", required=true, description="Webhook Relay ID", @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Relay deleted",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Deleted successfully")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Relay not found")
     * )
     */
    public function destroy(WebhookRelay $webhook_relay): JsonResponse
    {
        $webhook_relay->delete();

        // Redeploy Nginx config to remove the deleted proxy block
        $this->service->deployProxies();

        return response()->json(['message' => 'Deleted successfully']);
    }
}
