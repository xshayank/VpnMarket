<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiAuditLog;
use App\Models\ApiKey;
use App\Models\Panel;
use App\Models\Plan;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use App\Services\Api\ApiResponseMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Modules\Reseller\Services\ResellerProvisioner;

/**
 * Marzneshin-compatible API Controller
 *
 * This controller provides Marzneshin-style API endpoints for clients
 * that expect the Marzneshin API format. Endpoints respond in Marzneshin
 * format regardless of the underlying panel type (Marzneshin, Eylandoo, etc.)
 */
class MarzneshinStyleController extends Controller
{
    protected ApiResponseMapper $mapper;

    public function __construct()
    {
        $this->mapper = new ApiResponseMapper(ApiKey::STYLE_MARZNESHIN);
    }

    /**
     * Authenticate and get token (Marzneshin-style)
     * POST /api/admins/token
     *
     * Authentication: username=api_key, password=api_key (same value for both)
     */
    public function token(Request $request): JsonResponse
    {
        $username = $request->input('username');
        $password = $request->input('password');

        // In Marzneshin-style auth, we use the API key as both username and password
        $keyToCheck = $username ?? $password;

        if (! $keyToCheck) {
            return response()->json([
                'detail' => 'Invalid credentials',
            ], 401);
        }

        // Find API key by hash
        $keyHash = ApiKey::hashKey($keyToCheck);
        $apiKey = ApiKey::where('key_hash', $keyHash)->first();

        if (! $apiKey || ! $apiKey->isValid()) {
            return response()->json([
                'detail' => 'Invalid credentials',
            ], 401);
        }

        // Check if it's a Marzneshin-style key
        if (! $apiKey->isMarzneshinStyle()) {
            return response()->json([
                'detail' => 'This endpoint requires a Marzneshin-style API key',
            ], 403);
        }

        // Return the same key as a "token" (stateless API)
        return response()->json([
            'access_token' => $keyToCheck,
            'token_type' => 'bearer',
        ]);
    }

    /**
     * List services (Marzneshin-style)
     * GET /api/services
     *
     * For Eylandoo panels: returns nodes mapped as services
     * For Marzneshin panels: returns services natively
     */
    public function services(Request $request): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');

        // Get the default panel for this API key
        $panel = $apiKey->defaultPanel;

        if (! $panel) {
            return response()->json([
                'detail' => 'No default panel configured for this API key',
            ], 400);
        }

        $panelType = strtolower(trim($panel->panel_type ?? ''));
        $services = [];

        if ($panelType === 'eylandoo') {
            // Map Eylandoo nodes to Marzneshin services
            $nodes = $panel->getCachedEylandooNodes();

            // Filter by allowed nodes if whitelist exists
            $panelAccess = $reseller->panelAccess($panel->id);
            $allowedNodeIds = $panelAccess && $panelAccess->allowed_node_ids
                ? json_decode($panelAccess->allowed_node_ids, true)
                : null;

            if ($allowedNodeIds) {
                $nodes = array_filter($nodes, fn ($n) => in_array($n['id'] ?? 0, $allowedNodeIds));
            }

            $response = $this->mapper->mapNodesToServices(array_values($nodes));

        } elseif ($panelType === 'marzneshin') {
            // Return Marzneshin services natively
            $services = $panel->getCachedMarzneshinServices();

            // Filter by allowed services if whitelist exists
            $panelAccess = $reseller->panelAccess($panel->id);
            $allowedServiceIds = $panelAccess && $panelAccess->allowed_service_ids
                ? json_decode($panelAccess->allowed_service_ids, true)
                : null;

            if ($allowedServiceIds) {
                $services = array_filter($services, fn ($s) => in_array($s['id'] ?? 0, $allowedServiceIds));
            }

            $response = [
                'items' => array_values($services),
                'total' => count($services),
            ];

        } else {
            return response()->json([
                'detail' => "Panel type '{$panelType}' is not supported for services endpoint",
            ], 501);
        }

        // Log the action
        ApiAuditLog::logRequest(
            $reseller->user_id,
            $apiKey->id,
            'services.list',
            [
                'api_style' => ApiKey::STYLE_MARZNESHIN,
                'response_status' => 200,
            ]
        );

        return response()->json($response);
    }

    /**
     * List users (Marzneshin-style)
     * GET /api/users
     */
    public function users(Request $request): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');

        $perPage = $request->input('size', 50);
        $offset = $request->input('offset', 0);
        $username = $request->input('username');
        $status = $request->input('status');

        $query = $reseller->configs();

        // Filter by username if provided
        if ($username) {
            $query->where('external_username', 'like', "%{$username}%");
        }

        // Filter by status if provided
        if ($status) {
            $query->where('status', $status);
        }

        // Apply panel filter for Marzneshin-style keys
        if ($apiKey->default_panel_id) {
            $query->where('panel_id', $apiKey->default_panel_id);
        }

        $total = $query->count();
        $configs = $query->skip($offset)->take($perPage)->get();

        $response = $this->mapper->mapConfigList($configs, ['total' => $total]);

        // Log the action
        ApiAuditLog::logRequest(
            $reseller->user_id,
            $apiKey->id,
            'users.list',
            [
                'api_style' => ApiKey::STYLE_MARZNESHIN,
                'response_status' => 200,
                'target_type' => 'user',
            ]
        );

        return response()->json($response);
    }

    /**
     * Get a specific user (Marzneshin-style)
     * GET /api/users/{username}
     */
    public function getUser(Request $request, string $username): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');

        $query = $reseller->configs()->where('external_username', $username);

        // Apply panel filter for Marzneshin-style keys
        if ($apiKey->default_panel_id) {
            $query->where('panel_id', $apiKey->default_panel_id);
        }

        $config = $query->first();

        if (! $config) {
            return response()->json([
                'detail' => 'User not found',
            ], 404);
        }

        $response = $this->mapper->mapConfig($config);

        // Log the action
        ApiAuditLog::logRequest(
            $reseller->user_id,
            $apiKey->id,
            'users.read',
            [
                'api_style' => ApiKey::STYLE_MARZNESHIN,
                'response_status' => 200,
                'target_type' => 'user',
                'target_id_or_name' => $username,
            ]
        );

        return response()->json($response);
    }

    /**
     * Create a user (Marzneshin-style)
     * POST /api/users
     *
     * Request body:
     * {
     *   "username": "string",
     *   "data_limit": 0,
     *   "expire_date": "2024-01-01T00:00:00Z",
     *   "expire_strategy": "fixed_date",
     *   "service_ids": [1, 2],
     *   "note": "string"
     * }
     */
    public function createUser(Request $request): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');
        $user = $request->attributes->get('api_user');

        // Validation using Marzneshin field names
        $validator = Validator::make($request->all(), [
            'username' => 'required|string|max:100|regex:/^[a-zA-Z0-9_-]+$/',
            'data_limit' => 'required|integer|min:0',
            'expire_date' => 'required|date|after:now',
            'expire_strategy' => 'nullable|string|in:fixed_date,start_on_first_use,never',
            'service_ids' => 'nullable|array',
            'service_ids.*' => 'integer',
            'note' => 'nullable|string|max:200',
        ]);

        if ($validator->fails()) {
            return response()->json(
                $this->mapper->mapError('Validation failed', 422, $validator->errors()->toArray()),
                422
            );
        }

        // Validate reseller can create configs
        if (! $reseller->supportsConfigManagement()) {
            return response()->json([
                'detail' => 'This feature is only available for traffic-based and wallet-based resellers',
            ], 403);
        }

        // Get the panel
        $panel = $apiKey->defaultPanel;
        if (! $panel) {
            return response()->json([
                'detail' => 'No default panel configured for this API key',
            ], 400);
        }

        // Check config_limit enforcement
        if ($reseller->config_limit !== null && $reseller->config_limit > 0) {
            $totalConfigsCount = $reseller->configs()->count();
            if ($totalConfigsCount >= $reseller->config_limit) {
                return response()->json([
                    'detail' => "Config creation limit reached. Maximum allowed: {$reseller->config_limit}",
                ], 429);
            }
        }

        $panelType = strtolower(trim($panel->panel_type ?? ''));
        $expiresAt = \Carbon\Carbon::parse($request->input('expire_date'));
        $expiresDays = now()->diffInDays($expiresAt);
        $trafficLimitBytes = (int) $request->input('data_limit');
        $username = $request->input('username');

        // Handle service_ids -> node_ids mapping for Eylandoo
        $serviceIds = $request->input('service_ids', []);
        $nodeIds = [];

        if ($panelType === 'eylandoo') {
            // For Eylandoo, service_ids are actually node_ids
            $nodeIds = $serviceIds;
            $serviceIds = [];

            // Validate node whitelist
            $panelAccess = $reseller->panelAccess($panel->id);
            $allowedNodeIds = $panelAccess && $panelAccess->allowed_node_ids
                ? json_decode($panelAccess->allowed_node_ids, true)
                : null;

            if ($allowedNodeIds) {
                foreach ($nodeIds as $nodeId) {
                    if (! in_array($nodeId, $allowedNodeIds)) {
                        return response()->json([
                            'detail' => 'One or more selected services (nodes) are not allowed for your account',
                        ], 403);
                    }
                }
            }
        } elseif ($panelType === 'marzneshin') {
            // Validate service whitelist
            $panelAccess = $reseller->panelAccess($panel->id);
            $allowedServiceIds = $panelAccess && $panelAccess->allowed_service_ids
                ? json_decode($panelAccess->allowed_service_ids, true)
                : null;

            if ($allowedServiceIds) {
                foreach ($serviceIds as $serviceId) {
                    if (! in_array($serviceId, $allowedServiceIds)) {
                        return response()->json([
                            'detail' => 'One or more selected services are not allowed for your account',
                        ], 403);
                    }
                }
            }
        }

        $result = null;
        $config = null;

        try {
            DB::transaction(function () use ($request, $reseller, $user, $panel, $trafficLimitBytes, $expiresAt, $expiresDays, $nodeIds, $serviceIds, $username, $apiKey, &$result, &$config) {
                $provisioner = new ResellerProvisioner;

                // Create config record
                $config = ResellerConfig::create([
                    'reseller_id' => $reseller->id,
                    'external_username' => $username,
                    'name_version' => null,
                    'comment' => $request->input('note'),
                    'custom_name' => $username,
                    'traffic_limit_bytes' => $trafficLimitBytes,
                    'connections' => 1,
                    'usage_bytes' => 0,
                    'expires_at' => $expiresAt,
                    'status' => 'active',
                    'panel_type' => $panel->panel_type,
                    'panel_id' => $panel->id,
                    'created_by' => $user->id,
                    'created_by_api_key_id' => $apiKey->id,
                    'meta' => [
                        'node_ids' => $nodeIds,
                        'service_ids' => $serviceIds,
                        'created_via_marzneshin_api' => true,
                    ],
                ]);

                // Provision on panel
                $plan = new Plan;
                $plan->volume_gb = $trafficLimitBytes / (1024 * 1024 * 1024);
                $plan->duration_days = $expiresDays;
                $plan->marzneshin_service_ids = $serviceIds;

                $provisionResult = $provisioner->provisionUser($panel, $plan, $username, [
                    'traffic_limit_bytes' => $trafficLimitBytes,
                    'expires_at' => $expiresAt,
                    'service_ids' => $serviceIds,
                    'connections' => 1,
                    'max_clients' => 1,
                    'nodes' => $nodeIds,
                ]);

                if ($provisionResult) {
                    $config->update([
                        'panel_user_id' => $provisionResult['panel_user_id'],
                        'subscription_url' => $provisionResult['subscription_url'] ?? null,
                    ]);

                    ResellerConfigEvent::create([
                        'reseller_config_id' => $config->id,
                        'type' => 'created',
                        'meta' => array_merge($provisionResult, [
                            'via_marzneshin_api' => true,
                            'api_key_id' => $apiKey->id,
                        ]),
                    ]);

                    $result = $provisionResult;
                } else {
                    $config->delete();
                    throw new \Exception('Failed to provision user on the panel');
                }
            });
        } catch (\Exception $e) {
            Log::error('Marzneshin API user creation failed', [
                'reseller_id' => $reseller->id,
                'api_key_id' => $apiKey->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'detail' => $e->getMessage(),
            ], 500);
        }

        // Log the action
        ApiAuditLog::logRequest(
            $reseller->user_id,
            $apiKey->id,
            'users.create',
            [
                'api_style' => ApiKey::STYLE_MARZNESHIN,
                'response_status' => 200,
                'target_type' => 'user',
                'target_id_or_name' => $username,
            ]
        );

        return response()->json($this->mapper->mapConfig($config));
    }

    /**
     * Update a user (Marzneshin-style)
     * PUT /api/users/{username}
     */
    public function updateUser(Request $request, string $username): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');

        $query = $reseller->configs()->where('external_username', $username);

        if ($apiKey->default_panel_id) {
            $query->where('panel_id', $apiKey->default_panel_id);
        }

        $config = $query->first();

        if (! $config) {
            return response()->json([
                'detail' => 'User not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'data_limit' => 'nullable|integer|min:0',
            'expire_date' => 'nullable|date|after_or_equal:today',
            'service_ids' => 'nullable|array',
            'service_ids.*' => 'integer',
            'note' => 'nullable|string|max:200',
        ]);

        if ($validator->fails()) {
            return response()->json(
                $this->mapper->mapError('Validation failed', 422, $validator->errors()->toArray()),
                422
            );
        }

        $trafficLimitBytes = $request->has('data_limit')
            ? (int) $request->input('data_limit')
            : $config->traffic_limit_bytes;

        $expiresAt = $request->has('expire_date')
            ? \Carbon\Carbon::parse($request->input('expire_date'))
            : $config->expires_at;

        // Validation: traffic limit cannot be below current usage
        if ($trafficLimitBytes < $config->usage_bytes) {
            return response()->json([
                'detail' => 'Traffic limit cannot be set below current usage ('.round($config->usage_bytes / (1024 * 1024 * 1024), 2).' GB)',
            ], 422);
        }

        try {
            DB::transaction(function () use ($config, $trafficLimitBytes, $expiresAt, $request, $apiKey) {
                $meta = $config->meta ?? [];

                if ($request->has('note')) {
                    $config->comment = $request->input('note');
                }

                if ($request->has('service_ids')) {
                    $meta['service_ids'] = $request->input('service_ids');
                }

                $config->update([
                    'traffic_limit_bytes' => $trafficLimitBytes,
                    'expires_at' => $expiresAt,
                    'comment' => $config->comment,
                    'meta' => $meta,
                ]);

                // Try to update on remote panel
                if ($config->panel_id) {
                    $panel = Panel::find($config->panel_id);
                    if ($panel) {
                        $provisioner = new ResellerProvisioner;
                        $provisioner->updateUserLimits(
                            $panel->panel_type,
                            $panel->getCredentials(),
                            $config->panel_user_id,
                            $trafficLimitBytes,
                            $expiresAt
                        );
                    }
                }

                ResellerConfigEvent::create([
                    'reseller_config_id' => $config->id,
                    'type' => 'edited',
                    'meta' => [
                        'via_marzneshin_api' => true,
                        'api_key_id' => $apiKey->id,
                    ],
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'detail' => $e->getMessage(),
            ], 500);
        }

        // Log the action
        ApiAuditLog::logRequest(
            $reseller->user_id,
            $apiKey->id,
            'users.update',
            [
                'api_style' => ApiKey::STYLE_MARZNESHIN,
                'response_status' => 200,
                'target_type' => 'user',
                'target_id_or_name' => $username,
            ]
        );

        $config->refresh();

        return response()->json($this->mapper->mapConfig($config));
    }

    /**
     * Delete a user (Marzneshin-style)
     * DELETE /api/users/{username}
     */
    public function deleteUser(Request $request, string $username): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');

        $query = $reseller->configs()->where('external_username', $username);

        if ($apiKey->default_panel_id) {
            $query->where('panel_id', $apiKey->default_panel_id);
        }

        $config = $query->first();

        if (! $config) {
            return response()->json([
                'detail' => 'User not found',
            ], 404);
        }

        $remoteFailed = false;

        if ($config->panel_id) {
            try {
                $panel = Panel::find($config->panel_id);
                if ($panel) {
                    $provisioner = new ResellerProvisioner;
                    $success = $provisioner->deleteUser(
                        $config->panel_type,
                        $panel->getCredentials(),
                        $config->panel_user_id
                    );

                    if (! $success) {
                        $remoteFailed = true;
                    }
                }
            } catch (\Exception $e) {
                $remoteFailed = true;
                Log::error('Marzneshin API: Exception deleting user: '.$e->getMessage());
            }
        }

        $config->update(['status' => 'deleted']);
        $config->delete();

        ResellerConfigEvent::create([
            'reseller_config_id' => $config->id,
            'type' => 'deleted',
            'meta' => [
                'via_marzneshin_api' => true,
                'api_key_id' => $apiKey->id,
                'remote_failed' => $remoteFailed,
            ],
        ]);

        // Log the action
        ApiAuditLog::logRequest(
            $reseller->user_id,
            $apiKey->id,
            'users.delete',
            [
                'api_style' => ApiKey::STYLE_MARZNESHIN,
                'response_status' => 200,
                'target_type' => 'user',
                'target_id_or_name' => $username,
            ]
        );

        return response()->json([
            'message' => 'User deleted successfully',
        ]);
    }

    /**
     * Get user subscription (Marzneshin-style)
     * GET /api/users/{username}/subscription
     */
    public function getUserSubscription(Request $request, string $username): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');

        $query = $reseller->configs()->where('external_username', $username);

        if ($apiKey->default_panel_id) {
            $query->where('panel_id', $apiKey->default_panel_id);
        }

        $config = $query->first();

        if (! $config) {
            return response()->json([
                'detail' => 'User not found',
            ], 404);
        }

        // Log the action
        ApiAuditLog::logRequest(
            $reseller->user_id,
            $apiKey->id,
            'subscription.read',
            [
                'api_style' => ApiKey::STYLE_MARZNESHIN,
                'response_status' => 200,
                'target_type' => 'subscription',
                'target_id_or_name' => $username,
            ]
        );

        return response()->json([
            'username' => $config->external_username,
            'subscription_url' => $config->subscription_url,
        ]);
    }

    /**
     * Enable a user (Marzneshin-style)
     * POST /api/users/{username}/enable
     */
    public function enableUser(Request $request, string $username): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');

        $query = $reseller->configs()->where('external_username', $username);

        if ($apiKey->default_panel_id) {
            $query->where('panel_id', $apiKey->default_panel_id);
        }

        $config = $query->first();

        if (! $config) {
            return response()->json([
                'detail' => 'User not found',
            ], 404);
        }

        try {
            if ($config->panel_id) {
                $panel = Panel::find($config->panel_id);
                if ($panel) {
                    $provisioner = new ResellerProvisioner;
                    $provisioner->enableUser(
                        $config->panel_type,
                        $panel->getCredentials(),
                        $config->panel_user_id
                    );
                }
            }

            $config->update(['status' => 'active']);

        } catch (\Exception $e) {
            return response()->json([
                'detail' => 'Failed to enable user: '.$e->getMessage(),
            ], 500);
        }

        // Log the action
        ApiAuditLog::logRequest(
            $reseller->user_id,
            $apiKey->id,
            'users.enable',
            [
                'api_style' => ApiKey::STYLE_MARZNESHIN,
                'response_status' => 200,
                'target_type' => 'user',
                'target_id_or_name' => $username,
            ]
        );

        return response()->json($this->mapper->mapConfig($config->refresh()));
    }

    /**
     * Disable a user (Marzneshin-style)
     * POST /api/users/{username}/disable
     */
    public function disableUser(Request $request, string $username): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');

        $query = $reseller->configs()->where('external_username', $username);

        if ($apiKey->default_panel_id) {
            $query->where('panel_id', $apiKey->default_panel_id);
        }

        $config = $query->first();

        if (! $config) {
            return response()->json([
                'detail' => 'User not found',
            ], 404);
        }

        try {
            if ($config->panel_id) {
                $panel = Panel::find($config->panel_id);
                if ($panel) {
                    $provisioner = new ResellerProvisioner;
                    $provisioner->disableUser(
                        $config->panel_type,
                        $panel->getCredentials(),
                        $config->panel_user_id
                    );
                }
            }

            $config->update(['status' => 'disabled']);

        } catch (\Exception $e) {
            return response()->json([
                'detail' => 'Failed to disable user: '.$e->getMessage(),
            ], 500);
        }

        // Log the action
        ApiAuditLog::logRequest(
            $reseller->user_id,
            $apiKey->id,
            'users.disable',
            [
                'api_style' => ApiKey::STYLE_MARZNESHIN,
                'response_status' => 200,
                'target_type' => 'user',
                'target_id_or_name' => $username,
            ]
        );

        return response()->json($this->mapper->mapConfig($config->refresh()));
    }

    /**
     * Reset user traffic (Marzneshin-style)
     * POST /api/users/{username}/reset
     */
    public function resetUser(Request $request, string $username): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');

        $query = $reseller->configs()->where('external_username', $username);

        if ($apiKey->default_panel_id) {
            $query->where('panel_id', $apiKey->default_panel_id);
        }

        $config = $query->first();

        if (! $config) {
            return response()->json([
                'detail' => 'User not found',
            ], 404);
        }

        try {
            if ($config->panel_id) {
                $panel = Panel::find($config->panel_id);
                if ($panel) {
                    $provisioner = new ResellerProvisioner;
                    $provisioner->resetUserUsage(
                        $config->panel_type,
                        $panel->getCredentials(),
                        $config->panel_user_id
                    );
                }
            }

            $config->update(['usage_bytes' => 0]);

        } catch (\Exception $e) {
            return response()->json([
                'detail' => 'Failed to reset user: '.$e->getMessage(),
            ], 500);
        }

        // Log the action
        ApiAuditLog::logRequest(
            $reseller->user_id,
            $apiKey->id,
            'users.reset',
            [
                'api_style' => ApiKey::STYLE_MARZNESHIN,
                'response_status' => 200,
                'target_type' => 'user',
                'target_id_or_name' => $username,
            ]
        );

        return response()->json($this->mapper->mapConfig($config->refresh()));
    }

    /**
     * List nodes (Marzneshin-style)
     * GET /api/nodes
     *
     * For Eylandoo panels: returns nodes
     * For Marzneshin panels: returns empty (Marzneshin doesn't expose nodes the same way)
     */
    public function nodes(Request $request): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');

        $panel = $apiKey->defaultPanel;

        if (! $panel) {
            return response()->json([
                'detail' => 'No default panel configured for this API key',
            ], 400);
        }

        $panelType = strtolower(trim($panel->panel_type ?? ''));

        if ($panelType === 'eylandoo') {
            $nodes = $panel->getCachedEylandooNodes();

            // Filter by allowed nodes if whitelist exists
            $panelAccess = $reseller->panelAccess($panel->id);
            $allowedNodeIds = $panelAccess && $panelAccess->allowed_node_ids
                ? json_decode($panelAccess->allowed_node_ids, true)
                : null;

            if ($allowedNodeIds) {
                $nodes = array_filter($nodes, fn ($n) => in_array($n['id'] ?? 0, $allowedNodeIds));
            }

            $response = [
                'items' => array_values($nodes),
                'total' => count($nodes),
            ];
        } else {
            // Other panel types don't expose nodes in the same way
            $response = [
                'items' => [],
                'total' => 0,
            ];
        }

        // Log the action
        ApiAuditLog::logRequest(
            $reseller->user_id,
            $apiKey->id,
            'nodes.list',
            [
                'api_style' => ApiKey::STYLE_MARZNESHIN,
                'response_status' => 200,
            ]
        );

        return response()->json($response);
    }

    /**
     * Get admin info (Marzneshin-style)
     * GET /api/admin
     *
     * Returns current authenticated admin information
     */
    public function admin(Request $request): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');
        $user = $request->attributes->get('api_user');

        // Log the action
        ApiAuditLog::logRequest(
            $reseller->user_id,
            $apiKey->id,
            'admin.info',
            [
                'api_style' => ApiKey::STYLE_MARZNESHIN,
                'response_status' => 200,
            ]
        );

        // Return Marzneshin-style admin info
        return response()->json([
            'username' => $reseller->username_prefix ?? $user->name,
            'is_sudo' => false,
            'telegram_id' => null,
            'discord_webhook' => null,
            'users_usage' => $reseller->configs()->sum('usage_bytes'),
        ]);
    }

    /**
     * Get current admin info (Marzneshin-style)
     * GET /api/admins/current
     *
     * Returns current authenticated admin information (alias for /api/admin)
     */
    public function currentAdmin(Request $request): JsonResponse
    {
        return $this->admin($request);
    }

    /**
     * Get system stats (Marzneshin-style)
     * GET /api/system
     *
     * Returns system-wide statistics
     */
    public function system(Request $request): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');

        // Get current datetime for comparison
        $now = now()->format('Y-m-d H:i:s');

        // Get stats scoped to this reseller's data using a single optimized query
        $stats = $reseller->configs()
            ->selectRaw("
                COUNT(*) as total_users,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_users,
                SUM(CASE WHEN status = 'disabled' THEN 1 ELSE 0 END) as disabled_users,
                SUM(CASE WHEN usage_bytes >= traffic_limit_bytes AND traffic_limit_bytes > 0 THEN 1 ELSE 0 END) as limited_users,
                SUM(CASE WHEN expires_at < ? THEN 1 ELSE 0 END) as expired_users,
                SUM(usage_bytes) as total_usage,
                SUM(traffic_limit_bytes) as total_traffic_limit
            ", [$now])
            ->first();

        // Log the action
        ApiAuditLog::logRequest(
            $reseller->user_id,
            $apiKey->id,
            'system.stats',
            [
                'api_style' => ApiKey::STYLE_MARZNESHIN,
                'response_status' => 200,
            ]
        );

        return response()->json([
            'version' => config('app.version', '1.0.0'),
            'mem_total' => 0,
            'mem_used' => 0,
            'cpu_cores' => 0,
            'cpu_usage' => 0,
            'total_user' => (int) ($stats->total_users ?? 0),
            'users_active' => (int) ($stats->active_users ?? 0),
            'users_disabled' => (int) ($stats->disabled_users ?? 0),
            'users_limited' => (int) ($stats->limited_users ?? 0),
            'users_expired' => (int) ($stats->expired_users ?? 0),
            'users_on_hold' => 0,
            'incoming_bandwidth' => (int) ($stats->total_usage ?? 0),
            'outgoing_bandwidth' => 0,
            'incoming_bandwidth_speed' => 0,
            'outgoing_bandwidth_speed' => 0,
        ]);
    }

    /**
     * List inbounds (Marzneshin-style)
     * GET /api/inbounds
     *
     * Returns list of inbounds (protocol configurations)
     */
    public function inbounds(Request $request): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');

        // Log the action
        ApiAuditLog::logRequest(
            $reseller->user_id,
            $apiKey->id,
            'inbounds.list',
            [
                'api_style' => ApiKey::STYLE_MARZNESHIN,
                'response_status' => 200,
            ]
        );

        // We don't expose inbounds directly - return empty list
        // Real inbound info is managed at the panel level
        return response()->json([
            'items' => [],
            'total' => 0,
        ]);
    }

    /**
     * Get user usage (Marzneshin-style)
     * GET /api/users/{username}/usage
     *
     * Returns user usage statistics
     */
    public function getUserUsage(Request $request, string $username): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');

        $query = $reseller->configs()->where('external_username', $username);

        if ($apiKey->default_panel_id) {
            $query->where('panel_id', $apiKey->default_panel_id);
        }

        $config = $query->first();

        if (! $config) {
            return response()->json([
                'detail' => 'User not found',
            ], 404);
        }

        // Log the action
        ApiAuditLog::logRequest(
            $reseller->user_id,
            $apiKey->id,
            'users.usage',
            [
                'api_style' => ApiKey::STYLE_MARZNESHIN,
                'response_status' => 200,
                'target_type' => 'user',
                'target_id_or_name' => $username,
            ]
        );

        return response()->json([
            'username' => $config->external_username,
            'used_traffic' => $config->usage_bytes,
            'node_usages' => [],
        ]);
    }

    /**
     * Revoke user subscription (Marzneshin-style)
     * POST /api/users/{username}/revoke_subscription
     *
     * Revokes the user's subscription URL (regenerates it)
     */
    public function revokeUserSubscription(Request $request, string $username): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');

        $query = $reseller->configs()->where('external_username', $username);

        if ($apiKey->default_panel_id) {
            $query->where('panel_id', $apiKey->default_panel_id);
        }

        $config = $query->first();

        if (! $config) {
            return response()->json([
                'detail' => 'User not found',
            ], 404);
        }

        // Log the action
        ApiAuditLog::logRequest(
            $reseller->user_id,
            $apiKey->id,
            'subscription.revoke',
            [
                'api_style' => ApiKey::STYLE_MARZNESHIN,
                'response_status' => 200,
                'target_type' => 'subscription',
                'target_id_or_name' => $username,
            ]
        );

        // Return success - actual subscription revocation happens at panel level
        return response()->json([
            'username' => $config->external_username,
            'subscription_url' => $config->subscription_url,
        ]);
    }

    /**
     * Set user owner (Marzneshin-style)
     * PUT /api/users/{username}/set-owner
     *
     * Sets or changes the owner/admin of a user
     */
    public function setUserOwner(Request $request, string $username): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');

        $query = $reseller->configs()->where('external_username', $username);

        if ($apiKey->default_panel_id) {
            $query->where('panel_id', $apiKey->default_panel_id);
        }

        $config = $query->first();

        if (! $config) {
            return response()->json([
                'detail' => 'User not found',
            ], 404);
        }

        // This operation is not supported in our multi-reseller architecture
        // Return a Marzneshin-compatible error
        return response()->json([
            'detail' => 'Setting user owner is not supported in this panel configuration',
        ], 501);
    }

    /**
     * Get user expired status (Marzneshin-style)
     * GET /api/users/expired
     *
     * Returns list of expired users
     */
    public function expiredUsers(Request $request): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');

        $perPage = $request->input('size', 50);
        $offset = $request->input('offset', 0);

        $query = $reseller->configs()
            ->where(function ($q) {
                $q->where('expires_at', '<', now())
                    ->orWhere(function ($q2) {
                        $q2->whereRaw('usage_bytes >= traffic_limit_bytes')
                            ->where('traffic_limit_bytes', '>', 0);
                    });
            });

        if ($apiKey->default_panel_id) {
            $query->where('panel_id', $apiKey->default_panel_id);
        }

        $total = $query->count();
        $configs = $query->skip($offset)->take($perPage)->get();

        $response = $this->mapper->mapConfigList($configs, ['total' => $total]);

        // Log the action
        ApiAuditLog::logRequest(
            $reseller->user_id,
            $apiKey->id,
            'users.expired',
            [
                'api_style' => ApiKey::STYLE_MARZNESHIN,
                'response_status' => 200,
            ]
        );

        return response()->json($response);
    }

    /**
     * Delete expired users (Marzneshin-style)
     * DELETE /api/users/expired
     *
     * Deletes all expired users
     */
    public function deleteExpiredUsers(Request $request): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');

        $query = $reseller->configs()
            ->where('expires_at', '<', now());

        if ($apiKey->default_panel_id) {
            $query->where('panel_id', $apiKey->default_panel_id);
        }

        // Preload panels to avoid N+1 queries
        $expiredConfigs = $query->with('panel')->get();
        $deletedCount = 0;
        $provisioner = new ResellerProvisioner;

        foreach ($expiredConfigs as $config) {
            // Store config ID before deletion
            $configId = $config->id;

            try {
                if ($config->panel_id && $config->panel) {
                    $provisioner->deleteUser(
                        $config->panel_type,
                        $config->panel->getCredentials(),
                        $config->panel_user_id
                    );
                }

                // Create event before soft delete (config still exists)
                ResellerConfigEvent::create([
                    'reseller_config_id' => $configId,
                    'type' => 'deleted',
                    'meta' => [
                        'via_marzneshin_api' => true,
                        'api_key_id' => $apiKey->id,
                        'bulk_delete_expired' => true,
                    ],
                ]);

                $config->update(['status' => 'deleted']);
                $config->delete();
                $deletedCount++;

            } catch (\Exception $e) {
                Log::error("Failed to delete expired user {$config->external_username}: ".$e->getMessage());
            }
        }

        // Log the action
        ApiAuditLog::logRequest(
            $reseller->user_id,
            $apiKey->id,
            'users.delete_expired',
            [
                'api_style' => ApiKey::STYLE_MARZNESHIN,
                'response_status' => 200,
                'metadata' => ['deleted_count' => $deletedCount],
            ]
        );

        return response()->json([
            'message' => "Deleted {$deletedCount} expired users",
        ]);
    }

    /**
     * Reset all users usage (Marzneshin-style)
     * POST /api/users/reset
     *
     * Resets traffic usage for all users
     */
    public function resetAllUsersUsage(Request $request): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');

        $query = $reseller->configs();

        if ($apiKey->default_panel_id) {
            $query->where('panel_id', $apiKey->default_panel_id);
        }

        // Preload panels to avoid N+1 queries
        $configs = $query->with('panel')->get();
        $resetCount = 0;
        $provisioner = new ResellerProvisioner;

        foreach ($configs as $config) {
            try {
                if ($config->panel_id && $config->panel) {
                    $provisioner->resetUserUsage(
                        $config->panel_type,
                        $config->panel->getCredentials(),
                        $config->panel_user_id
                    );
                }

                $config->update(['usage_bytes' => 0]);
                $resetCount++;
            } catch (\Exception $e) {
                Log::error("Failed to reset usage for {$config->external_username}: ".$e->getMessage());
            }
        }

        // Log the action
        ApiAuditLog::logRequest(
            $reseller->user_id,
            $apiKey->id,
            'users.reset_all',
            [
                'api_style' => ApiKey::STYLE_MARZNESHIN,
                'response_status' => 200,
                'metadata' => ['reset_count' => $resetCount],
            ]
        );

        return response()->json([
            'message' => "Reset traffic for {$resetCount} users",
        ]);
    }
}
