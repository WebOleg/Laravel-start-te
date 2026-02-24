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

class WebhookRelayController extends Controller
{
    protected WebhookRelayService $service;

    public function __construct(WebhookRelayService $service)
    {
        $this->service = $service;
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(Request $request)
    {
        $relays = WebhookRelay::with('empAccounts')
                              ->orderByDesc('created_at')
                              ->paginate(min((int) $request->input('per_page', 20), 100));

        return WebhookRelayResource::collection($relays);
    }

    /**
     * @param StoreWebhookRelayRequest $request
     * @return JsonResponse
     * @throws \Illuminate\Validation\ValidationException
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
     * @param StoreWebhookRelayRequest $request
     * @param WebhookRelay $webhook_relay
     * @return JsonResponse
     * @throws ValidationException
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
     * @param WebhookRelay $webhook_relay
     * @return JsonResponse
     */
    public function destroy(WebhookRelay $webhook_relay): JsonResponse
    {
        $webhook_relay->delete();

        // Redeploy Nginx config to remove the deleted proxy block
        $this->service->deployProxies();

        return response()->json(['message' => 'Deleted successfully']);
    }
}
