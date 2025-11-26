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
}
