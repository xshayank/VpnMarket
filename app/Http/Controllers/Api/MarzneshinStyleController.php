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
    /**
     * Session token TTL in minutes (1 hour by default)
     */
    protected const SESSION_TOKEN_TTL_MINUTES = 60;

    /**
     * Default expiry years for "never" expire strategy
     * Since Eylandoo panels do not implement "never" expiry natively,
     * we translate it to a long fixed_date (10 years by default)
     */
    protected const NEVER_EXPIRY_YEARS = 10;

    protected ApiResponseMapper $mapper;

    public function __construct()
    {
        $this->mapper = new ApiResponseMapper(ApiKey::STYLE_MARZNESHIN);
    }

    /**
     * Get the far future expiry date for "never" expire strategy
     *
     * Eylandoo panels do not implement "never" expiry natively, so we translate
     * it to a long fixed_date. This method provides a consistent implementation
     * across all endpoints that need this translation.
     *
     * @return \Carbon\Carbon The far future expiry date
     */
    protected function getNeverExpiryDate(): \Carbon\Carbon
    {
        return now()->addYears(self::NEVER_EXPIRY_YEARS);
    }

    /**
     * Find a config by username or prefix fallback
     *
     * Username behavior: our panel does not support free-form name selection.
     * When the bot requests /api/users/{username}, if the exact external_username
     * does not exist, we search by the stored prefix and return the matching config
     * (or the latest) to the bot.
     *
     * @param \App\Models\Reseller $reseller The reseller to scope configs to
     * @param string $username The username to search for
     * @param int|null $panelId Optional panel ID to filter by
     * @return ResellerConfig|null The matching config or null
     */
    protected function findConfigByUsernameOrPrefix($reseller, string $username, ?int $panelId = null): ?ResellerConfig
    {
        // Build base query scoped to reseller
        $query = $reseller->configs();

        // Apply panel filter if specified
        if ($panelId) {
            $query->where('panel_id', $panelId);
        }

        // First, try exact match on external_username
        $config = (clone $query)->where('external_username', $username)->first();

        if ($config) {
            return $config;
        }

        // Fallback: search by prefix column (where bot-provided username is stored)
        // Return the most recently created config with this prefix
        $config = (clone $query)
            ->where('prefix', $username)
            ->orderBy('created_at', 'desc')
            ->first();

        return $config;
    }

    /**
     * Authenticate and get token (Marzneshin-style)
     * POST /api/admins/token
     *
     * Supports two authentication methods:
     * 1. Legacy: username=api_key, password=api_key (same value for both)
     * 2. Admin credentials: username=admin_username, password=admin_password
     *
     * For admin credentials auth, returns an ephemeral session token stored in cache.
     */
    public function token(Request $request): JsonResponse
    {
        $username = $request->input('username');
        $password = $request->input('password');

        if (! $username || ! $password) {
            return response()->json([
                'detail' => 'Invalid credentials',
            ], 401);
        }

        $apiKey = null;
        $isLegacyFlow = false;

        // Method 1: Legacy API key flow (username === password and matches API key hash)
        if ($username === $password) {
            $keyHash = ApiKey::hashKey($username);
            $apiKey = ApiKey::where('key_hash', $keyHash)->first();
            if ($apiKey) {
                $isLegacyFlow = true;
            }
        }

        // Method 2: Admin credential flow (if legacy flow didn't find a key)
        if (! $apiKey) {
            // Look for Marzneshin API key with matching admin_username
            $apiKey = ApiKey::where('admin_username', $username)
                ->where('api_style', ApiKey::STYLE_MARZNESHIN)
                ->first();

            // Verify password for admin credential flow
            if ($apiKey && ! $apiKey->authenticateAdminCredentials($username, $password)) {
                return response()->json([
                    'detail' => 'Invalid credentials',
                ], 401);
            }
        }

        // No API key found
        if (! $apiKey) {
            return response()->json([
                'detail' => 'Invalid credentials',
            ], 401);
        }

        // Validate API key is Marzneshin style
        if (! $apiKey->isMarzneshinStyle()) {
            return response()->json([
                'detail' => 'This endpoint requires a Marzneshin-style API key',
            ], 403);
        }

        // Check if key is valid (not revoked, not expired)
        if (! $apiKey->isValid()) {
            // Determine the specific reason for invalidity
            if ($apiKey->revoked) {
                $reason = 'API key has been revoked';
            } elseif ($apiKey->expires_at && $apiKey->expires_at->isPast()) {
                $reason = 'API key has expired';
            } else {
                $reason = 'API key is invalid';
            }
            return response()->json([
                'detail' => $reason,
            ], 401);
        }

        // Check if reseller has API enabled
        $user = $apiKey->user;
        if (! $user) {
            return response()->json([
                'detail' => 'API key owner not found',
            ], 403);
        }

        $reseller = $user->reseller;
        if (! $reseller) {
            return response()->json([
                'detail' => 'No reseller account associated with this API key',
            ], 403);
        }

        if (! $reseller->api_enabled) {
            return response()->json([
                'detail' => 'API access is not enabled for this account',
            ], 403);
        }

        // Check if reseller is active
        if (! $reseller->isActive()) {
            return response()->json([
                'detail' => 'Reseller account is not active',
            ], 403);
        }

        // Return appropriate token based on auth flow
        if ($isLegacyFlow) {
            // Return the same key as a "token" (stateless API)
            return response()->json([
                'access_token' => $username,
                'token_type' => 'bearer',
            ]);
        }

        // For admin credential flow, generate an ephemeral session token
        $sessionToken = 'mzsess_' . bin2hex(random_bytes(32));
        $ttlMinutes = self::SESSION_TOKEN_TTL_MINUTES;

        \Illuminate\Support\Facades\Cache::put(
            "api_session:{$sessionToken}",
            $apiKey->id,
            now()->addMinutes($ttlMinutes)
        );

        return response()->json([
            'access_token' => $sessionToken,
            'token_type' => 'bearer',
            'expires_in' => $ttlMinutes * 60, // seconds
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
     *
     * Username prefix behavior: If exact external_username match is not found,
     * searches by stored prefix and returns the matching config.
     */
    public function getUser(Request $request, string $username): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');

        // Use prefix fallback lookup
        $config = $this->findConfigByUsernameOrPrefix(
            $reseller,
            $username,
            $apiKey->default_panel_id
        );

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
     *   "data_limit_reset_strategy": "no_reset",
     *   "expire_date": "2024-01-01T00:00:00Z",
     *   "expire_strategy": "fixed_date",
     *   "usage_duration": 0,
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
            'data_limit_reset_strategy' => 'nullable|string|max:50',
            'expire_date' => 'nullable|date',
            'expire_strategy' => 'nullable|string|in:fixed_date,start_on_first_use,never',
            'usage_duration' => 'nullable|integer|min:0',
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
        $trafficLimitBytes = (int) $request->input('data_limit');
        $username = $request->input('username');
        $expireStrategy = $request->input('expire_strategy', 'fixed_date');
        $usageDuration = $request->input('usage_duration', 0);

        // Calculate expiry based on expire_strategy
        // Special handling for Eylandoo panels: "never" expiry is not implemented in the panel.
        // For expire_strategy "never", we translate to a long fixed_date.
        $expiresAt = null;
        if ($expireStrategy === 'start_on_first_use' && $usageDuration > 0) {
            // For start_on_first_use, use usage_duration (in seconds) from first use
            // For now, set a far future date and store the strategy in meta
            $expiresAt = $this->getNeverExpiryDate();
        } elseif ($expireStrategy === 'never') {
            // Eylandoo panels do not support "never" expiry natively.
            // Translate to a long fixed_date (default: 10 years).
            $expiresAt = $this->getNeverExpiryDate();
        } elseif ($request->has('expire_date')) {
            $expiresAt = \Carbon\Carbon::parse($request->input('expire_date'));
        } else {
            // Default to 30 days if no expire_date provided
            $expiresAt = now()->addDays(30);
        }

        $expiresDays = now()->diffInDays($expiresAt);

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
            DB::transaction(function () use ($request, $reseller, $user, $panel, $trafficLimitBytes, $expiresAt, $expiresDays, $nodeIds, $serviceIds, $username, $apiKey, $expireStrategy, $usageDuration, &$result, &$config) {
                $provisioner = new ResellerProvisioner;

                // Build meta with additional fields
                $meta = [
                    'node_ids' => $nodeIds,
                    'service_ids' => $serviceIds,
                    'created_via_marzneshin_api' => true,
                    'expire_strategy' => $expireStrategy,
                ];

                // Store data_limit_reset_strategy in meta if provided
                if ($request->has('data_limit_reset_strategy')) {
                    $meta['data_limit_reset_strategy'] = $request->input('data_limit_reset_strategy');
                }

                // Store usage_duration for start_on_first_use strategy
                if ($expireStrategy === 'start_on_first_use' && $usageDuration > 0) {
                    $meta['usage_duration'] = $usageDuration;
                    $meta['activation_pending'] = true;
                }

                // Create config record
                // Username behavior: store the API-provided username as prefix,
                // which allows lookup by prefix when exact external_username match fails.
                $config = ResellerConfig::create([
                    'reseller_id' => $reseller->id,
                    'external_username' => $username,
                    'prefix' => $username, // Store API-provided username as prefix for fallback lookup
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
                    'meta' => $meta,
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
     *
     * Username prefix behavior: If exact external_username match is not found,
     * searches by stored prefix and updates the matching config.
     */
    public function updateUser(Request $request, string $username): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');

        // Use prefix fallback lookup
        $config = $this->findConfigByUsernameOrPrefix(
            $reseller,
            $username,
            $apiKey->default_panel_id
        );

        if (! $config) {
            return response()->json([
                'detail' => 'User not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'data_limit' => 'nullable|integer|min:0',
            'data_limit_reset_strategy' => 'nullable|string|max:50',
            'expire_date' => 'nullable|date',
            'expire_strategy' => 'nullable|string|in:fixed_date,start_on_first_use,never',
            'usage_duration' => 'nullable|integer|min:0',
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

        $expiresAt = $config->expires_at;

        // Handle expire_date and expire_strategy
        // Special handling for Eylandoo panels: "never" expiry is not implemented natively.
        if ($request->has('expire_date')) {
            $expiresAt = \Carbon\Carbon::parse($request->input('expire_date'));
        } elseif ($request->has('expire_strategy')) {
            $expireStrategy = $request->input('expire_strategy');
            $usageDuration = $request->input('usage_duration', 0);

            if ($expireStrategy === 'start_on_first_use' && $usageDuration > 0) {
                $expiresAt = $this->getNeverExpiryDate();
            } elseif ($expireStrategy === 'never') {
                // Translate "never" to long fixed_date for Eylandoo compatibility
                $expiresAt = $this->getNeverExpiryDate();
            }
        }

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

                // Store additional fields in meta
                if ($request->has('data_limit_reset_strategy')) {
                    $meta['data_limit_reset_strategy'] = $request->input('data_limit_reset_strategy');
                }

                if ($request->has('expire_strategy')) {
                    $meta['expire_strategy'] = $request->input('expire_strategy');
                }

                if ($request->has('usage_duration')) {
                    $meta['usage_duration'] = $request->input('usage_duration');
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
     *
     * Username prefix behavior: If exact external_username match is not found,
     * searches by stored prefix and deletes the matching config.
     */
    public function deleteUser(Request $request, string $username): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');

        // Use prefix fallback lookup
        $config = $this->findConfigByUsernameOrPrefix(
            $reseller,
            $username,
            $apiKey->default_panel_id
        );

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
     *
     * Username prefix behavior: If exact external_username match is not found,
     * searches by stored prefix and returns the matching config's subscription.
     */
    public function getUserSubscription(Request $request, string $username): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');

        // Use prefix fallback lookup
        $config = $this->findConfigByUsernameOrPrefix(
            $reseller,
            $username,
            $apiKey->default_panel_id
        );

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
     *
     * Username prefix behavior: If exact external_username match is not found,
     * searches by stored prefix and enables the matching config.
     */
    public function enableUser(Request $request, string $username): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');

        // Use prefix fallback lookup
        $config = $this->findConfigByUsernameOrPrefix(
            $reseller,
            $username,
            $apiKey->default_panel_id
        );

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
     *
     * Username prefix behavior: If exact external_username match is not found,
     * searches by stored prefix and disables the matching config.
     */
    public function disableUser(Request $request, string $username): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');

        // Use prefix fallback lookup
        $config = $this->findConfigByUsernameOrPrefix(
            $reseller,
            $username,
            $apiKey->default_panel_id
        );

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
     *
     * Username prefix behavior: If exact external_username match is not found,
     * searches by stored prefix and resets the matching config's usage.
     */
    public function resetUser(Request $request, string $username): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');

        // Use prefix fallback lookup
        $config = $this->findConfigByUsernameOrPrefix(
            $reseller,
            $username,
            $apiKey->default_panel_id
        );

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
     * Returns user usage statistics.
     * Username prefix behavior: If exact external_username match is not found,
     * searches by stored prefix and returns the matching config's usage.
     */
    public function getUserUsage(Request $request, string $username): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');

        // Use prefix fallback lookup
        $config = $this->findConfigByUsernameOrPrefix(
            $reseller,
            $username,
            $apiKey->default_panel_id
        );

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
     * Revokes the user's subscription URL (regenerates it).
     * Username prefix behavior: If exact external_username match is not found,
     * searches by stored prefix and revokes the matching config's subscription.
     */
    public function revokeUserSubscription(Request $request, string $username): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');

        // Use prefix fallback lookup
        $config = $this->findConfigByUsernameOrPrefix(
            $reseller,
            $username,
            $apiKey->default_panel_id
        );

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
     * Sets or changes the owner/admin of a user.
     * Username prefix behavior: If exact external_username match is not found,
     * searches by stored prefix.
     */
    public function setUserOwner(Request $request, string $username): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');

        // Use prefix fallback lookup
        $config = $this->findConfigByUsernameOrPrefix(
            $reseller,
            $username,
            $apiKey->default_panel_id
        );

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

    /**
     * Get user stats (Marzneshin-style)
     * GET /api/system/stats/users
     *
     * Returns aggregate counts (total, active, disabled) and total_used_traffic
     * scoped to the reseller and default panel.
     */
    public function systemUserStats(Request $request): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $reseller = $request->attributes->get('api_reseller');

        // Build query scoped to reseller's configs
        $query = $reseller->configs();

        // Filter by default panel if API key has one configured
        if ($apiKey->default_panel_id) {
            $query->where('panel_id', $apiKey->default_panel_id);
        }

        // Get current datetime for comparison
        $now = now()->format('Y-m-d H:i:s');

        // Get stats using a single optimized query
        $stats = $query->selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status = 'disabled' THEN 1 ELSE 0 END) as disabled,
            SUM(usage_bytes) as total_used_traffic
        ")->first();

        // Log the action
        ApiAuditLog::logRequest(
            $reseller->user_id,
            $apiKey->id,
            'system.stats.users',
            [
                'api_style' => ApiKey::STYLE_MARZNESHIN,
                'response_status' => 200,
            ]
        );

        return response()->json([
            'total' => (int) ($stats->total ?? 0),
            'active' => (int) ($stats->active ?? 0),
            'disabled' => (int) ($stats->disabled ?? 0),
            'total_used_traffic' => (int) ($stats->total_used_traffic ?? 0),
        ]);
    }
}
