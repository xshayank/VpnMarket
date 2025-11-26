<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiAuditLog;
use App\Models\ApiWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WebhookController extends Controller
{
    /**
     * Default maximum webhooks per user
     */
    protected const DEFAULT_MAX_WEBHOOKS_PER_USER = 20;

    /**
     * List webhooks for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->reseller || ! $user->reseller->api_enabled) {
            return response()->json([
                'error' => true,
                'message' => 'API access is not enabled for your account',
            ], 403);
        }

        $webhooks = ApiWebhook::where('user_id', $user->id)
            ->select([
                'id', 'name', 'url', 'events', 'is_active',
                'failure_count', 'last_triggered_at', 'last_success_at',
                'last_failure_at', 'created_at',
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $webhooks,
        ]);
    }

    /**
     * Create a new webhook.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->reseller || ! $user->reseller->api_enabled) {
            return response()->json([
                'error' => true,
                'message' => 'API access is not enabled for your account',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'url' => 'required|url|max:500',
            'events' => 'required|array|min:1',
            'events.*' => 'string|in:'.implode(',', ApiWebhook::ALL_EVENTS),
            'api_key_id' => 'nullable|exists:api_keys,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Verify the API key belongs to the user
        if ($request->filled('api_key_id')) {
            $keyBelongsToUser = $user->apiKeys()
                ->where('id', $request->input('api_key_id'))
                ->exists();

            if (! $keyBelongsToUser) {
                return response()->json([
                    'error' => true,
                    'message' => 'The specified API key does not belong to you',
                ], 403);
            }
        }

        // Limit number of webhooks per user
        $webhookCount = ApiWebhook::where('user_id', $user->id)->count();
        $maxWebhooks = config('api.max_webhooks_per_user', self::DEFAULT_MAX_WEBHOOKS_PER_USER);

        if ($webhookCount >= $maxWebhooks) {
            return response()->json([
                'error' => true,
                'message' => "Maximum number of webhooks ({$maxWebhooks}) reached.",
            ], 429);
        }

        $webhook = ApiWebhook::create([
            'user_id' => $user->id,
            'api_key_id' => $request->input('api_key_id'),
            'name' => $request->input('name'),
            'url' => $request->input('url'),
            'secret' => ApiWebhook::generateSecret(),
            'events' => $request->input('events'),
            'is_active' => true,
        ]);

        // Log the creation
        ApiAuditLog::logAction(
            $user->id,
            $request->input('api_key_id'),
            'webhook.created',
            'webhook',
            (string) $webhook->id,
            ['name' => $webhook->name, 'events' => $webhook->events]
        );

        return response()->json([
            'data' => [
                'id' => $webhook->id,
                'name' => $webhook->name,
                'url' => $webhook->url,
                'secret' => $webhook->secret, // Only show once
                'events' => $webhook->events,
                'is_active' => $webhook->is_active,
                'created_at' => $webhook->created_at->toIso8601String(),
            ],
            'message' => 'Webhook created. Save the secret securely - it will not be shown again.',
        ], 201);
    }

    /**
     * Get a specific webhook.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->reseller || ! $user->reseller->api_enabled) {
            return response()->json([
                'error' => true,
                'message' => 'API access is not enabled for your account',
            ], 403);
        }

        $webhook = ApiWebhook::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (! $webhook) {
            return response()->json([
                'error' => true,
                'message' => 'Webhook not found',
            ], 404);
        }

        return response()->json([
            'data' => [
                'id' => $webhook->id,
                'name' => $webhook->name,
                'url' => $webhook->url,
                'events' => $webhook->events,
                'is_active' => $webhook->is_active,
                'failure_count' => $webhook->failure_count,
                'last_triggered_at' => $webhook->last_triggered_at?->toIso8601String(),
                'last_success_at' => $webhook->last_success_at?->toIso8601String(),
                'last_failure_at' => $webhook->last_failure_at?->toIso8601String(),
                'last_error' => $webhook->last_error,
                'created_at' => $webhook->created_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Update a webhook.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->reseller || ! $user->reseller->api_enabled) {
            return response()->json([
                'error' => true,
                'message' => 'API access is not enabled for your account',
            ], 403);
        }

        $webhook = ApiWebhook::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (! $webhook) {
            return response()->json([
                'error' => true,
                'message' => 'Webhook not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:100',
            'url' => 'nullable|url|max:500',
            'events' => 'nullable|array|min:1',
            'events.*' => 'string|in:'.implode(',', ApiWebhook::ALL_EVENTS),
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $updateData = array_filter([
            'name' => $request->input('name'),
            'url' => $request->input('url'),
            'events' => $request->input('events'),
            'is_active' => $request->input('is_active'),
        ], fn ($v) => $v !== null);

        // If re-enabling, reset failure count
        if (isset($updateData['is_active']) && $updateData['is_active'] === true) {
            $updateData['failure_count'] = 0;
            $updateData['last_error'] = null;
        }

        $webhook->update($updateData);

        // Log the update
        ApiAuditLog::logAction(
            $user->id,
            null,
            'webhook.updated',
            'webhook',
            (string) $webhook->id,
            ['updated_fields' => array_keys($updateData)]
        );

        return response()->json([
            'data' => [
                'id' => $webhook->id,
                'name' => $webhook->name,
                'url' => $webhook->url,
                'events' => $webhook->events,
                'is_active' => $webhook->is_active,
            ],
            'message' => 'Webhook updated successfully',
        ]);
    }

    /**
     * Delete a webhook.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->reseller || ! $user->reseller->api_enabled) {
            return response()->json([
                'error' => true,
                'message' => 'API access is not enabled for your account',
            ], 403);
        }

        $webhook = ApiWebhook::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (! $webhook) {
            return response()->json([
                'error' => true,
                'message' => 'Webhook not found',
            ], 404);
        }

        // Log before deletion
        ApiAuditLog::logAction(
            $user->id,
            null,
            'webhook.deleted',
            'webhook',
            (string) $webhook->id,
            ['name' => $webhook->name]
        );

        $webhook->delete();

        return response()->json([
            'message' => 'Webhook deleted successfully',
        ]);
    }

    /**
     * Regenerate webhook secret.
     */
    public function regenerateSecret(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->reseller || ! $user->reseller->api_enabled) {
            return response()->json([
                'error' => true,
                'message' => 'API access is not enabled for your account',
            ], 403);
        }

        $webhook = ApiWebhook::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (! $webhook) {
            return response()->json([
                'error' => true,
                'message' => 'Webhook not found',
            ], 404);
        }

        $newSecret = ApiWebhook::generateSecret();
        $webhook->update(['secret' => $newSecret]);

        // Log the regeneration
        ApiAuditLog::logAction(
            $user->id,
            null,
            'webhook.secret_regenerated',
            'webhook',
            (string) $webhook->id
        );

        return response()->json([
            'data' => [
                'id' => $webhook->id,
                'secret' => $newSecret,
            ],
            'message' => 'Webhook secret regenerated. Save it securely - it will not be shown again.',
        ]);
    }

    /**
     * Test a webhook by sending a test event.
     */
    public function test(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->reseller || ! $user->reseller->api_enabled) {
            return response()->json([
                'error' => true,
                'message' => 'API access is not enabled for your account',
            ], 403);
        }

        $webhook = ApiWebhook::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (! $webhook) {
            return response()->json([
                'error' => true,
                'message' => 'Webhook not found',
            ], 404);
        }

        // Temporarily make webhook active for the test
        $wasActive = $webhook->is_active;
        $webhook->is_active = true;

        $success = $webhook->trigger('test', [
            'message' => 'This is a test webhook event',
            'timestamp' => now()->toIso8601String(),
        ]);

        // Restore original active state
        if (! $wasActive) {
            $webhook->is_active = false;
            $webhook->save();
        }

        if ($success) {
            return response()->json([
                'message' => 'Test webhook sent successfully',
            ]);
        }

        return response()->json([
            'error' => true,
            'message' => 'Webhook test failed',
            'details' => $webhook->last_error,
        ], 500);
    }

    /**
     * List available webhook events.
     */
    public function events(): JsonResponse
    {
        return response()->json([
            'data' => array_map(fn ($event) => [
                'name' => $event,
                'description' => $this->getEventDescription($event),
            ], ApiWebhook::ALL_EVENTS),
        ]);
    }

    /**
     * Get description for a webhook event.
     */
    protected function getEventDescription(string $event): string
    {
        return match ($event) {
            ApiWebhook::EVENT_CONFIG_CREATED => 'Triggered when a new config/user is created',
            ApiWebhook::EVENT_CONFIG_UPDATED => 'Triggered when a config/user is updated',
            ApiWebhook::EVENT_CONFIG_DELETED => 'Triggered when a config/user is deleted',
            ApiWebhook::EVENT_USER_CREATED => 'Triggered when a user is created via API',
            ApiWebhook::EVENT_USER_UPDATED => 'Triggered when a user is updated via API',
            ApiWebhook::EVENT_USER_DELETED => 'Triggered when a user is deleted via API',
            ApiWebhook::EVENT_PANEL_STATUS_CHANGED => 'Triggered when a panel\'s status changes',
            ApiWebhook::EVENT_API_KEY_USAGE_SPIKE => 'Triggered when API key usage spikes abnormally',
            ApiWebhook::EVENT_API_KEY_ERROR_SPIKE => 'Triggered when API key errors spike abnormally',
            ApiWebhook::EVENT_RATE_LIMIT_HIT => 'Triggered when rate limit is exceeded',
            default => 'No description available',
        };
    }
}
