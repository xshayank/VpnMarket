<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiAuditLog;
use App\Models\AuditLog;
use App\Models\Panel;
use App\Models\Plan;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use App\Services\ConfigNameGenerator;
use App\Services\UsernameGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Modules\Reseller\Services\ResellerProvisioner;

class ConfigController extends Controller
{
    /**
     * List configs for the API key's user.
     */
    public function index(Request $request): JsonResponse
    {
        $reseller = $request->attributes->get('api_reseller');
        $apiKey = $request->attributes->get('api_key');

        $configs = $reseller->configs()
            ->select([
                'id',
                'external_username',
                'username_prefix',
                'panel_username',
                'comment',
                'traffic_limit_bytes',
                'usage_bytes',
                'expires_at',
                'status',
                'panel_type',
                'panel_id',
                'subscription_url',
                'created_at',
                'updated_at',
            ])
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

        // Transform configs to use display username
        $transformedConfigs = collect($configs->items())->map(function ($config) {
            return [
                'id' => $config->id,
                'username' => $config->display_username, // Show prefix to clients
                'comment' => $config->comment,
                'traffic_limit_bytes' => $config->traffic_limit_bytes,
                'usage_bytes' => $config->usage_bytes,
                'expires_at' => $config->expires_at?->toIso8601String(),
                'status' => $config->status,
                'panel_type' => $config->panel_type,
                'panel_id' => $config->panel_id,
                'subscription_url' => $config->subscription_url,
                'created_at' => $config->created_at->toIso8601String(),
                'updated_at' => $config->updated_at->toIso8601String(),
            ];
        });

        // Log the action
        ApiAuditLog::logAction(
            $reseller->user_id,
            $apiKey->id,
            'configs.list',
            'config',
            null,
            ['count' => $configs->total()]
        );

        return response()->json([
            'data' => $transformedConfigs,
            'meta' => [
                'current_page' => $configs->currentPage(),
                'last_page' => $configs->lastPage(),
                'per_page' => $configs->perPage(),
                'total' => $configs->total(),
            ],
        ]);
    }

    /**
     * Get a specific config by name.
     * Accepts both username prefix and full panel username for backward compatibility.
     */
    public function show(Request $request, string $name): JsonResponse
    {
        $reseller = $request->attributes->get('api_reseller');
        $apiKey = $request->attributes->get('api_key');

        // Search by multiple fields for flexibility
        $config = $reseller->configs()
            ->where(function ($query) use ($name) {
                $query->where('external_username', $name)
                    ->orWhere('panel_username', $name)
                    ->orWhere('username_prefix', $name);
            })
            ->first();

        if (! $config) {
            return response()->json([
                'error' => true,
                'message' => 'Config not found',
            ], 404);
        }

        // Log the action
        ApiAuditLog::logAction(
            $reseller->user_id,
            $apiKey->id,
            'configs.read',
            'config',
            $config->external_username
        );

        return response()->json([
            'data' => [
                'id' => $config->id,
                'username' => $config->display_username, // Show prefix to clients
                'name' => $config->display_username, // Backward compatibility alias
                'comment' => $config->comment,
                'traffic_limit_bytes' => $config->traffic_limit_bytes,
                'traffic_limit_gb' => round($config->traffic_limit_bytes / (1024 * 1024 * 1024), 2),
                'usage_bytes' => $config->usage_bytes,
                'usage_gb' => round($config->usage_bytes / (1024 * 1024 * 1024), 2),
                'expires_at' => $config->expires_at?->toIso8601String(),
                'status' => $config->status,
                'panel_id' => $config->panel_id,
                'panel_type' => $config->panel_type,
                'subscription_url' => $config->subscription_url,
                'connections' => $config->connections,
                'created_at' => $config->created_at->toIso8601String(),
                'updated_at' => $config->updated_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Create a new config.
     */
    public function store(Request $request): JsonResponse
    {
        $reseller = $request->attributes->get('api_reseller');
        $user = $request->attributes->get('api_user');
        $apiKey = $request->attributes->get('api_key');

        // Validate reseller can create configs
        if (! $reseller->supportsConfigManagement()) {
            return response()->json([
                'error' => true,
                'message' => 'This feature is only available for traffic-based and wallet-based resellers',
            ], 403);
        }

        // Check config_limit enforcement
        if ($reseller->config_limit !== null && $reseller->config_limit > 0) {
            $totalConfigsCount = $reseller->configs()->count();
            if ($totalConfigsCount >= $reseller->config_limit) {
                return response()->json([
                    'error' => true,
                    'message' => "Config creation limit reached. Maximum allowed: {$reseller->config_limit}",
                ], 429);
            }
        }

        $validator = Validator::make($request->all(), [
            'panel_id' => 'required|exists:panels,id',
            'traffic_limit_gb' => 'required|numeric|min:0.1',
            'expires_days' => 'required|integer|min:1',
            'connections' => 'nullable|integer|min:1|max:10',
            'comment' => 'nullable|string|max:200',
            'custom_name' => 'nullable|string|max:100|regex:/^[a-zA-Z0-9_-]+$/',
            'service_ids' => 'nullable|array',
            'service_ids.*' => 'integer',
            'node_ids' => 'nullable|array',
            'node_ids.*' => 'integer',
            'max_clients' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Validate reseller has access to the selected panel
        $hasAccess = $reseller->hasPanelAccess($request->panel_id)
            || $reseller->panel_id == $request->panel_id
            || $reseller->primary_panel_id == $request->panel_id;

        if (! $hasAccess) {
            return response()->json([
                'error' => true,
                'message' => 'You do not have access to the selected panel',
            ], 403);
        }

        $panel = Panel::findOrFail($request->panel_id);
        $panelType = strtolower(trim($panel->panel_type ?? ''));

        // Validate panel-specific fields
        if ($panelType !== 'eylandoo' && $request->filled('node_ids')) {
            return response()->json([
                'error' => true,
                'message' => 'Node selection is only available for Eylandoo panels',
            ], 422);
        }

        if ($panelType !== 'marzneshin' && $request->filled('service_ids')) {
            return response()->json([
                'error' => true,
                'message' => 'Service selection is only available for Marzneshin panels',
            ], 422);
        }

        $expiresDays = $request->integer('expires_days');
        $trafficLimitBytes = (float) $request->input('traffic_limit_gb') * 1024 * 1024 * 1024;
        $expiresAt = now()->addDays($expiresDays)->startOfDay();

        // Validate nodes/services based on pivot whitelist
        $panelAccess = $reseller->panelAccess($panel->id);
        $nodeIds = array_map('intval', (array) ($request->node_ids ?? []));

        // Validate node whitelist for Eylandoo
        if ($panelType === 'eylandoo') {
            $allowedNodeIds = null;

            if ($panelAccess && $panelAccess->allowed_node_ids) {
                $allowedNodeIds = json_decode($panelAccess->allowed_node_ids, true) ?: [];
                $allowedNodeIds = array_map('intval', (array) $allowedNodeIds);
            } elseif ($reseller->eylandoo_allowed_node_ids) {
                $allowedNodeIds = is_array($reseller->eylandoo_allowed_node_ids)
                    ? array_map('intval', $reseller->eylandoo_allowed_node_ids)
                    : [];
            }

            if ($allowedNodeIds) {
                foreach ($nodeIds as $nodeId) {
                    if (! in_array($nodeId, $allowedNodeIds, true)) {
                        return response()->json([
                            'error' => true,
                            'message' => 'One or more selected nodes are not allowed for your account',
                        ], 403);
                    }
                }
            }
        }

        // Validate service whitelist for Marzneshin
        if ($panelType === 'marzneshin' && $panelAccess && $panelAccess->allowed_service_ids) {
            $serviceIds = $request->service_ids ?? [];
            $allowedServiceIds = json_decode($panelAccess->allowed_service_ids, true) ?: [];

            foreach ($serviceIds as $serviceId) {
                if (! in_array($serviceId, $allowedServiceIds)) {
                    return response()->json([
                        'error' => true,
                        'message' => 'One or more selected services are not allowed for your account',
                    ], 403);
                }
            }
        }

        $result = null;
        $config = null;

        try {
            DB::transaction(function () use ($request, $reseller, $user, $panel, $trafficLimitBytes, $expiresAt, $expiresDays, $nodeIds, $apiKey, &$result, &$config) {
                $provisioner = new ResellerProvisioner;

                $customName = $request->input('custom_name');
                $maxClients = (int) ($request->input('max_clients', 1));

                // Generate username using enhanced username generator
                $panelUsername = '';
                $usernamePrefix = '';
                $nameVersion = null;

                if ($customName) {
                    // Use enhanced username generator even for custom names
                    // This ensures the username sent to panel is properly sanitized and unique
                    $usernameGenerator = new UsernameGenerator();
                    $usernameData = $usernameGenerator->generatePanelUsername(
                        $customName,
                        $usernameGenerator->createDatabaseExistsChecker()
                    );
                    $panelUsername = $usernameData['panel_username'];
                    $usernamePrefix = $usernameData['username_prefix'];
                    $nameVersion = null;
                } else {
                    $generator = new ConfigNameGenerator;
                    $nameData = $generator->generate($reseller, $panel, $reseller->type, []);
                    $panelUsername = $nameData['name'];
                    // Extract prefix from generated name
                    $usernameGenerator = new UsernameGenerator();
                    $usernamePrefix = $usernameGenerator->extractPrefix($panelUsername);
                    $nameVersion = $nameData['version'];
                }

                // Create config record with both username_prefix and panel_username
                $config = ResellerConfig::create([
                    'reseller_id' => $reseller->id,
                    'external_username' => $panelUsername, // Keep for backward compatibility
                    'username_prefix' => $usernamePrefix, // Display username (original/sanitized)
                    'panel_username' => $panelUsername, // Actual panel username
                    'name_version' => $nameVersion,
                    'comment' => $request->input('comment'),
                    'custom_name' => $customName,
                    'traffic_limit_bytes' => $trafficLimitBytes,
                    'connections' => $request->input('connections'),
                    'usage_bytes' => 0,
                    'expires_at' => $expiresAt,
                    'status' => 'active',
                    'panel_type' => $panel->panel_type,
                    'panel_id' => $panel->id,
                    'created_by' => $user->id,
                    'created_by_api_key_id' => $apiKey->id,
                    'meta' => [
                        'node_ids' => $nodeIds,
                        'max_clients' => $maxClients,
                        'created_via_api' => true,
                        'original_custom_name' => $customName, // Store original for reference
                    ],
                ]);

                // Provision on panel using the panel_username (the sanitized unique one)
                $plan = new Plan;
                $plan->volume_gb = (float) $request->input('traffic_limit_gb');
                $plan->duration_days = $expiresDays;
                $plan->marzneshin_service_ids = $request->input('service_ids', []);

                $provisionResult = $provisioner->provisionUser($panel, $plan, $panelUsername, [
                    'traffic_limit_bytes' => $trafficLimitBytes,
                    'expires_at' => $expiresAt,
                    'service_ids' => $plan->marzneshin_service_ids,
                    'connections' => $request->input('connections', 1),
                    'max_clients' => $maxClients,
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
                        'meta' => array_merge($provisionResult, ['via_api' => true, 'api_key_id' => $apiKey->id]),
                    ]);

                    $result = $provisionResult;
                } else {
                    $config->delete();
                    throw new \Exception('Failed to provision config on the panel');
                }
            });
        } catch (\Exception $e) {
            Log::error('API config creation failed', [
                'reseller_id' => $reseller->id,
                'api_key_id' => $apiKey->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => true,
                'message' => $e->getMessage(),
            ], 500);
        }

        // Log the action
        ApiAuditLog::logAction(
            $reseller->user_id,
            $apiKey->id,
            'configs.create',
            'config',
            $config->external_username,
            [
                'panel_id' => $panel->id,
                'traffic_limit_gb' => $request->input('traffic_limit_gb'),
                'expires_days' => $expiresDays,
            ]
        );

        return response()->json([
            'data' => [
                'id' => $config->id,
                'username' => $config->display_username, // Show display username to clients
                'name' => $config->display_username, // Backward compatibility alias
                'subscription_url' => $config->subscription_url,
                'traffic_limit_bytes' => $config->traffic_limit_bytes,
                'traffic_limit_gb' => round($config->traffic_limit_bytes / (1024 * 1024 * 1024), 2),
                'expires_at' => $config->expires_at?->toIso8601String(),
                'status' => $config->status,
                'panel_id' => $config->panel_id,
                'panel_type' => $config->panel_type,
                'created_at' => $config->created_at->toIso8601String(),
            ],
            'message' => 'Config created successfully',
        ], 201);
    }

    /**
     * Update a config by name.
     * Accepts both username prefix and full panel username for backward compatibility.
     */
    public function update(Request $request, string $name): JsonResponse
    {
        $reseller = $request->attributes->get('api_reseller');
        $apiKey = $request->attributes->get('api_key');

        // Search by multiple fields for flexibility
        $config = $reseller->configs()
            ->where(function ($query) use ($name) {
                $query->where('external_username', $name)
                    ->orWhere('panel_username', $name)
                    ->orWhere('username_prefix', $name);
            })
            ->first();

        if (! $config) {
            return response()->json([
                'error' => true,
                'message' => 'Config not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'traffic_limit_gb' => 'nullable|numeric|min:0.1',
            'expires_at' => 'nullable|date|after_or_equal:today',
            'max_clients' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $trafficLimitBytes = $request->has('traffic_limit_gb')
            ? (float) $request->input('traffic_limit_gb') * 1024 * 1024 * 1024
            : $config->traffic_limit_bytes;

        $expiresAt = $request->has('expires_at')
            ? \Carbon\Carbon::parse($request->input('expires_at'))->startOfDay()
            : $config->expires_at;

        // Validation: traffic limit cannot be below current usage
        if ($trafficLimitBytes < $config->usage_bytes) {
            return response()->json([
                'error' => true,
                'message' => 'Traffic limit cannot be set below current usage ('.round($config->usage_bytes / (1024 * 1024 * 1024), 2).' GB)',
            ], 422);
        }

        $oldTrafficLimit = $config->traffic_limit_bytes;
        $oldExpiresAt = $config->expires_at;
        $meta = $config->meta ?? [];
        $oldMaxClients = $meta['max_clients'] ?? 1;
        $maxClients = (int) ($request->input('max_clients', $oldMaxClients));
        $meta['max_clients'] = $maxClients;

        $remoteResult = null;

        try {
            DB::transaction(function () use ($config, $trafficLimitBytes, $expiresAt, $meta, $maxClients, $apiKey, &$remoteResult) {
                $config->update([
                    'traffic_limit_bytes' => $trafficLimitBytes,
                    'expires_at' => $expiresAt,
                    'meta' => $meta,
                ]);

                // Try to update on remote panel
                $remoteResult = ['success' => false, 'attempts' => 0, 'last_error' => 'No panel configured'];

                if ($config->panel_id) {
                    try {
                        $panel = Panel::findOrFail($config->panel_id);
                        $provisioner = new ResellerProvisioner;
                        $panelType = strtolower(trim($panel->panel_type ?? ''));

                        if ($panelType === 'eylandoo') {
                            $remoteResult = $provisioner->updateUser(
                                $panel->panel_type,
                                $panel->getCredentials(),
                                $config->panel_user_id,
                                [
                                    'data_limit' => $trafficLimitBytes,
                                    'expire' => $expiresAt->timestamp,
                                    'max_clients' => $maxClients,
                                ]
                            );
                        } else {
                            $remoteResult = $provisioner->updateUserLimits(
                                $panel->panel_type,
                                $panel->getCredentials(),
                                $config->panel_user_id,
                                $trafficLimitBytes,
                                $expiresAt
                            );
                        }
                    } catch (\Exception $e) {
                        Log::error('API config update exception: '.$e->getMessage());
                        $remoteResult['last_error'] = $e->getMessage();
                    }
                }

                ResellerConfigEvent::create([
                    'reseller_config_id' => $config->id,
                    'type' => 'edited',
                    'meta' => [
                        'via_api' => true,
                        'api_key_id' => $apiKey->id,
                        'remote_success' => $remoteResult['success'],
                    ],
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'error' => true,
                'message' => $e->getMessage(),
            ], 500);
        }

        // Log the action
        ApiAuditLog::logAction(
            $reseller->user_id,
            $apiKey->id,
            'configs.update',
            'config',
            $config->external_username,
            [
                'old_traffic_limit_gb' => round($oldTrafficLimit / (1024 * 1024 * 1024), 2),
                'new_traffic_limit_gb' => round($trafficLimitBytes / (1024 * 1024 * 1024), 2),
                'old_expires_at' => $oldExpiresAt?->format('Y-m-d'),
                'new_expires_at' => $expiresAt?->format('Y-m-d'),
            ]
        );

        $config->refresh();

        return response()->json([
            'data' => [
                'id' => $config->id,
                'username' => $config->display_username, // Show display username to clients
                'name' => $config->display_username, // Backward compatibility alias
                'traffic_limit_bytes' => $config->traffic_limit_bytes,
                'traffic_limit_gb' => round($config->traffic_limit_bytes / (1024 * 1024 * 1024), 2),
                'expires_at' => $config->expires_at?->toIso8601String(),
                'status' => $config->status,
                'updated_at' => $config->updated_at->toIso8601String(),
            ],
            'message' => 'Config updated successfully',
            'remote_sync' => $remoteResult['success'] ?? false,
        ]);
    }

    /**
     * Delete a config by name.
     * Accepts both username prefix and full panel username for backward compatibility.
     */
    public function destroy(Request $request, string $name): JsonResponse
    {
        $reseller = $request->attributes->get('api_reseller');
        $apiKey = $request->attributes->get('api_key');

        // Search by multiple fields for flexibility
        $config = $reseller->configs()
            ->where(function ($query) use ($name) {
                $query->where('external_username', $name)
                    ->orWhere('panel_username', $name)
                    ->orWhere('username_prefix', $name);
            })
            ->first();

        if (! $config) {
            return response()->json([
                'error' => true,
                'message' => 'Config not found',
            ], 404);
        }

        $remoteFailed = false;

        if ($config->panel_id) {
            try {
                $panel = Panel::findOrFail($config->panel_id);
                $provisioner = new ResellerProvisioner;
                $success = $provisioner->deleteUser($config->panel_type, $panel->getCredentials(), $config->panel_user_id);

                if (! $success) {
                    $remoteFailed = true;
                    Log::warning("API: Failed to delete config {$config->id} on remote panel");
                }
            } catch (\Exception $e) {
                $remoteFailed = true;
                Log::error('API: Exception deleting config: '.$e->getMessage());
            }
        }

        $config->update(['status' => 'deleted']);
        $config->delete();

        ResellerConfigEvent::create([
            'reseller_config_id' => $config->id,
            'type' => 'deleted',
            'meta' => [
                'via_api' => true,
                'api_key_id' => $apiKey->id,
                'remote_failed' => $remoteFailed,
            ],
        ]);

        // Log the action
        ApiAuditLog::logAction(
            $reseller->user_id,
            $apiKey->id,
            'configs.delete',
            'config',
            $name,
            ['remote_failed' => $remoteFailed]
        );

        AuditLog::log(
            action: 'config_deleted_via_api',
            targetType: 'config',
            targetId: $config->id,
            reason: 'api_action',
            meta: [
                'remote_failed' => $remoteFailed,
                'panel_id' => $config->panel_id,
                'api_key_id' => $apiKey->id,
            ]
        );

        return response()->json([
            'message' => 'Config deleted successfully',
            'remote_sync' => ! $remoteFailed,
        ]);
    }
}
