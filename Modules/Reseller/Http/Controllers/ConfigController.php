<?php

namespace Modules\Reseller\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Panel;
use App\Models\Plan;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use App\Services\ConfigNameGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Modules\Reseller\Services\ResellerProvisioner;

class ConfigController extends Controller
{
    /**
     * Check if panel type is Eylandoo (case-insensitive, typo-safe)
     */
    private function isEylandooPanel(?string $panelType): bool
    {
        return $panelType && strtolower(trim($panelType)) === 'eylandoo';
    }

    public function index(Request $request)
    {
        $reseller = $request->user()->reseller;

        // Null safety: check if reseller exists
        if (! $reseller) {
            return redirect()->route('reseller.dashboard')
                ->with('error', 'Reseller account not found.');
        }

        if (! $reseller->supportsConfigManagement()) {
            return redirect()->route('reseller.dashboard')
                ->with('error', 'This feature is only available for traffic-based and wallet-based resellers.');
        }

        $configs = $reseller->configs()->latest()->paginate(20);

        return view('reseller::configs.index', [
            'reseller' => $reseller,
            'configs' => $configs,
        ]);
    }

    public function create(Request $request)
    {
        $reseller = $request->user()->reseller;

        // Null safety: check if reseller exists
        if (! $reseller) {
            return redirect()->route('reseller.dashboard')
                ->with('error', 'Reseller account not found.');
        }

        if (! $reseller->supportsConfigManagement()) {
            return redirect()->route('reseller.dashboard')
                ->with('error', 'This feature is only available for traffic-based and wallet-based resellers.');
        }

        // Wallet resellers must have a panel assigned
        if ($reseller->isWalletBased() && ! $reseller->panel_id) {
            return redirect()->route('reseller.dashboard')
                ->with('error', 'Your account does not have a panel assigned. Please contact support.');
        }

        // If reseller has a specific panel assigned, use only that panel
        if ($reseller->panel_id) {
            $panels = Panel::where('id', $reseller->panel_id)->where('is_active', true)->get();
        } else {
            $panels = Panel::where('is_active', true)->get();
        }

        $marzneshinServices = [];
        $nodesOptions = [];  // Renamed for clarity per requirements
        $showNodesSelector = false;

        // If reseller has Marzneshin service whitelist, fetch available services
        if ($reseller->marzneshin_allowed_service_ids) {
            // For simplicity, we'll pass the IDs and let the view handle it
            $marzneshinServices = $reseller->marzneshin_allowed_service_ids;
        }

        // Fetch Eylandoo nodes for each Eylandoo panel, filtered by reseller's allowed nodes
        // Feature flag allows disabling this in emergencies
        $eylandooFeaturesEnabled = config('app.eylandoo_features_enabled', env('EYLANDOO_FEATURES_ENABLED', true));

        foreach ($panels as $panel) {
            if ($eylandooFeaturesEnabled && $this->isEylandooPanel($panel->panel_type)) {
                $showNodesSelector = true;

                // Use cached method (5 minute cache) with null safety
                $allNodes = [];
                try {
                    $allNodes = $panel->getCachedEylandooNodes() ?? [];
                } catch (\Exception $e) {
                    Log::warning('Failed to fetch Eylandoo nodes for panel', [
                        'panel_id' => $panel->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                // Ensure allNodes is an array
                if (! is_array($allNodes)) {
                    $allNodes = [];
                }

                // If reseller has node whitelist, filter nodes
                if ($reseller->eylandoo_allowed_node_ids && ! empty($reseller->eylandoo_allowed_node_ids)) {
                    // Normalize allowed node IDs to integers with null safety
                    $allowedNodeIds = array_map('intval', (array) $reseller->eylandoo_allowed_node_ids);
                    $nodes = array_filter($allNodes, function ($node) use ($allowedNodeIds) {
                        // Ensure node is array and has id key
                        if (! is_array($node) || ! isset($node['id'])) {
                            return false;
                        }

                        // Strict comparison - both IDs are now integers
                        return in_array((int) $node['id'], $allowedNodeIds, true);
                    });
                } else {
                    $nodes = $allNodes;
                }

                // Always set nodes array for Eylandoo panels
                // If no nodes available, provide default nodes [1, 2] as fallback
                if (! empty($nodes)) {
                    $nodesOptions[$panel->id] = array_values($nodes);
                } else {
                    // No nodes found - use default IDs 1 and 2
                    // These can be customized via config if needed
                    $defaultNodeIds = config('panels.eylandoo.default_node_ids', [1, 2]);
                    $nodesOptions[$panel->id] = array_map(function ($id) {
                        return [
                            'id' => (int) $id, // Integer ID for consistency
                            'name' => "Node {$id} (default)",
                            'is_default' => true,
                        ];
                    }, (array) $defaultNodeIds);
                }

                // Log node selection data for debugging (only if app.debug is true)
                if (config('app.debug')) {
                    Log::debug('Eylandoo nodes loaded for config creation', [
                        'reseller_id' => $reseller->id,
                        'panel_id' => $panel->id,
                        'panel_type' => $panel->panel_type,
                        'all_nodes_count' => count($allNodes),
                        'filtered_nodes_count' => count($nodesOptions[$panel->id] ?? []),
                        'has_node_whitelist' => ! empty($reseller->eylandoo_allowed_node_ids),
                        'allowed_node_ids' => $reseller->eylandoo_allowed_node_ids ?? [],
                        'showNodesSelector' => $showNodesSelector,
                        'using_defaults' => empty($nodes),
                    ]);
                }
            }
        }

        return view('reseller::configs.create', [
            'reseller' => $reseller,
            'panels' => $panels,
            'marzneshin_services' => $marzneshinServices,
            'nodesOptions' => $nodesOptions,
            'showNodesSelector' => $showNodesSelector,
        ]);
    }

    public function store(Request $request)
    {
        $reseller = $request->user()->reseller;

        // Null safety: check if reseller exists
        if (! $reseller) {
            return back()->with('error', 'Reseller account not found.');
        }

        if (! $reseller->supportsConfigManagement()) {
            return back()->with('error', 'This feature is only available for traffic-based and wallet-based resellers.');
        }

        // Check config_limit enforcement
        if ($reseller->config_limit !== null && $reseller->config_limit > 0) {
            $totalConfigsCount = $reseller->configs()->count();
            if ($totalConfigsCount >= $reseller->config_limit) {
                return back()->with('error', "Config creation limit reached. Maximum allowed: {$reseller->config_limit}");
            }
        }

        $validator = Validator::make($request->all(), [
            'panel_id' => 'required|exists:panels,id',
            'traffic_limit_gb' => 'required|numeric|min:0.1',
            'expires_days' => 'required|integer|min:1',
            'connections' => 'nullable|integer|min:1|max:10',
            'comment' => 'nullable|string|max:200',
            'prefix' => 'nullable|string|max:50|regex:/^[a-zA-Z0-9_-]+$/',
            'custom_name' => 'nullable|string|max:100|regex:/^[a-zA-Z0-9_-]+$/',
            'service_ids' => 'nullable|array',
            'service_ids.*' => 'integer',
            'node_ids' => 'nullable|array',
            'node_ids.*' => 'integer',
            'max_clients' => 'nullable|integer|min:1|max:100', // Max 100 as reasonable safety limit to prevent abuse
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        // Validate reseller can use the selected panel
        if ($reseller->panel_id && $request->panel_id != $reseller->panel_id) {
            return back()->with('error', 'You can only use the panel assigned to your account.');
        }

        // Additional validation for wallet resellers - they must use their assigned panel
        if ($reseller->isWalletBased()) {
            if (! $reseller->panel_id) {
                return back()->with('error', 'Your account does not have a panel assigned. Please contact support.');
            }
            if ($request->panel_id != $reseller->panel_id) {
                return back()->with('error', 'You can only use the panel assigned to your account.');
            }
        }

        $panel = Panel::findOrFail($request->panel_id);
        $expiresDays = $request->integer('expires_days');
        $trafficLimitBytes = (float) $request->input('traffic_limit_gb') * 1024 * 1024 * 1024;
        // Normalize to start of day for calendar-day boundaries
        $expiresAt = now()->addDays($expiresDays)->startOfDay();

        // Validate Marzneshin service whitelist
        if ($panel->panel_type === 'marzneshin' && $reseller->marzneshin_allowed_service_ids) {
            $serviceIds = $request->service_ids ?? [];
            $allowedServiceIds = $reseller->marzneshin_allowed_service_ids;

            foreach ($serviceIds as $serviceId) {
                if (! in_array($serviceId, $allowedServiceIds)) {
                    return back()->with('error', 'One or more selected services are not allowed for your account.');
                }
            }
        }

        // Validate Eylandoo node whitelist
        $nodeIds = array_map('intval', (array) ($request->node_ids ?? []));
        $filteredOutCount = 0;

        if ($this->isEylandooPanel($panel->panel_type) && $reseller->eylandoo_allowed_node_ids) {
            // Normalize allowed node IDs to integers
            $allowedNodeIds = array_map('intval', (array) $reseller->eylandoo_allowed_node_ids);

            foreach ($nodeIds as $nodeId) {
                // Strict comparison - both IDs are now integers
                if (! in_array($nodeId, $allowedNodeIds, true)) {
                    $filteredOutCount++;
                    Log::warning('Node selection rejected - not in whitelist', [
                        'reseller_id' => $reseller->id,
                        'panel_id' => $panel->id,
                        'rejected_node_id' => $nodeId,
                        'allowed_node_ids' => $allowedNodeIds,
                    ]);
                }
            }

            if ($filteredOutCount > 0) {
                return back()->with('error', 'One or more selected nodes are not allowed for your account.');
            }
        }

        // Log node selection for Eylandoo configs
        if ($this->isEylandooPanel($panel->panel_type)) {
            Log::info('Config creation with Eylandoo nodes', [
                'reseller_id' => $reseller->id,
                'panel_id' => $panel->id,
                'selected_nodes_count' => count($nodeIds),
                'selected_node_ids' => $nodeIds,
                'filtered_out_count' => $filteredOutCount,
                'has_whitelist' => ! empty($reseller->eylandoo_allowed_node_ids),
            ]);
        }

        DB::transaction(function () use ($request, $reseller, $panel, $trafficLimitBytes, $expiresAt, $expiresDays, $nodeIds) {
            $provisioner = new ResellerProvisioner;

            // Get prefix and custom_name from request (with permission checks)
            $prefix = null;
            $customName = null;

            // Only allow prefix if user has permission
            if ($request->user()->can('configs.set_prefix') && $request->filled('prefix')) {
                $prefix = $request->input('prefix');
            }

            // Only allow custom_name if user has permission
            if ($request->user()->can('configs.set_custom_name') && $request->filled('custom_name')) {
                $customName = $request->input('custom_name');
            }

            // Get max_clients value, default to 1 if not provided
            $maxClients = (int) ($request->input('max_clients', 1));

            // Log max_clients for debugging (only if app.debug is true)
            if (config('app.debug') && $this->isEylandooPanel($panel->panel_type)) {
                Log::debug('Config creation with max_clients', [
                    'reseller_id' => $reseller->id,
                    'panel_id' => $panel->id,
                    'max_clients' => $maxClients,
                ]);
            }

            // Generate username using ConfigNameGenerator (V2) or legacy method
            $username = '';
            $nameVersion = null;

            if ($customName) {
                // Custom name overrides everything - use it directly
                $username = $customName;
                $nameVersion = null; // Custom names don't have a version
            } else {
                // Use ConfigNameGenerator to generate name
                $generator = new ConfigNameGenerator();
                
                // Build options array for generator
                $generatorOptions = [];
                if ($prefix) {
                    $generatorOptions['prefix'] = $prefix;
                }
                
                $nameData = $generator->generate($reseller, $panel, $reseller->type, $generatorOptions);
                $username = $nameData['name'];
                $nameVersion = $nameData['version'];
            }

            // Create config record first
            $config = ResellerConfig::create([
                'reseller_id' => $reseller->id,
                'external_username' => $username,
                'name_version' => $nameVersion,
                'comment' => $request->input('comment'),
                'prefix' => $prefix,
                'custom_name' => $customName,
                'traffic_limit_bytes' => $trafficLimitBytes,
                'connections' => $request->input('connections'),
                'usage_bytes' => 0,
                'expires_at' => $expiresAt,
                'status' => 'active',
                'panel_type' => $panel->panel_type,
                'panel_id' => $panel->id,
                'created_by' => $request->user()->id,
                'meta' => [
                    'node_ids' => $nodeIds, // Already normalized to integers
                    'max_clients' => $maxClients, // Store max_clients in meta for traceability
                ],
            ]);

            // Provision on panel - use a non-persisted Plan model instance
            $plan = new Plan;
            $plan->volume_gb = (float) $request->input('traffic_limit_gb');
            $plan->duration_days = $expiresDays;
            $plan->marzneshin_service_ids = $request->input('service_ids', []);

            $result = $provisioner->provisionUser($panel, $plan, $username, [
                'traffic_limit_bytes' => $trafficLimitBytes,
                'expires_at' => $expiresAt,
                'service_ids' => $plan->marzneshin_service_ids,
                'connections' => $request->input('connections', 1),
                'max_clients' => $maxClients, // Pass max_clients to provisioner
                'nodes' => $nodeIds, // Already normalized to integers
            ]);

            if ($result) {
                $config->update([
                    'panel_user_id' => $result['panel_user_id'],
                    'subscription_url' => $result['subscription_url'] ?? null,
                ]);

                ResellerConfigEvent::create([
                    'reseller_config_id' => $config->id,
                    'type' => 'created',
                    'meta' => $result,
                ]);

                session()->flash('success', 'Config created successfully.');
                session()->flash('subscription_url', $result['subscription_url'] ?? null);
            } else {
                $config->delete();
                session()->flash('error', 'Failed to provision config on the panel.');
            }
        });

        return redirect()->route('reseller.configs.index');
    }

    public function disable(Request $request, ResellerConfig $config)
    {
        $reseller = $request->user()->reseller;

        // Null safety: check if reseller exists
        if (! $reseller) {
            return back()->with('error', 'Reseller account not found.');
        }

        if ($config->reseller_id !== $reseller->id) {
            abort(403);
        }

        if (! $config->isActive()) {
            return back()->with('error', 'Config is not active.');
        }

        // Try to disable on remote panel first
        $remoteResult = ['success' => false, 'attempts' => 0, 'last_error' => 'No panel configured'];

        if ($config->panel_id) {
            try {
                $panel = Panel::findOrFail($config->panel_id);
                $provisioner = new ResellerProvisioner;

                // Use panel->panel_type instead of config->panel_type
                $remoteResult = $provisioner->disableUser(
                    $panel->panel_type,
                    $panel->getCredentials(),
                    $config->panel_user_id
                );

                if (! $remoteResult['success']) {
                    Log::warning("Failed to disable config {$config->id} on remote panel {$panel->id} after {$remoteResult['attempts']} attempts: {$remoteResult['last_error']}");
                }
            } catch (\Exception $e) {
                Log::error("Exception disabling config {$config->id} on panel: ".$e->getMessage());
                $remoteResult['last_error'] = $e->getMessage();
            }
        }

        // Update local state after remote attempt
        $config->update([
            'status' => 'disabled',
            'disabled_at' => now(),
        ]);

        ResellerConfigEvent::create([
            'reseller_config_id' => $config->id,
            'type' => 'manual_disabled',
            'meta' => [
                'user_id' => $request->user()->id,
                'remote_success' => $remoteResult['success'],
                'attempts' => $remoteResult['attempts'],
                'last_error' => $remoteResult['last_error'],
                'panel_id' => $config->panel_id,
                'panel_type_used' => $config->panel_id ? Panel::find($config->panel_id)?->panel_type : null,
            ],
        ]);

        // Create audit log entry
        AuditLog::log(
            action: 'config_manual_disabled',
            targetType: 'config',
            targetId: $config->id,
            reason: 'admin_action',
            meta: [
                'remote_success' => $remoteResult['success'],
                'attempts' => $remoteResult['attempts'],
                'last_error' => $remoteResult['last_error'],
                'panel_id' => $config->panel_id,
                'panel_type_used' => $config->panel_id ? Panel::find($config->panel_id)?->panel_type : null,
            ]
        );

        if (! $remoteResult['success']) {
            return back()->with('warning', 'Config disabled locally, but remote panel update failed after '.$remoteResult['attempts'].' attempts.');
        }

        return back()->with('success', 'Config disabled successfully.');
    }

    public function enable(Request $request, ResellerConfig $config)
    {
        $reseller = $request->user()->reseller;

        // Null safety: check if reseller exists
        if (! $reseller) {
            return back()->with('error', 'Reseller account not found.');
        }

        if ($config->reseller_id !== $reseller->id) {
            abort(403);
        }

        if (! $config->isDisabled()) {
            return back()->with('error', 'Config is not disabled.');
        }

        // Validate reseller can enable configs (only for traffic-based resellers)
        // Wallet-based resellers are managed via wallet balance, not quota/window
        if ($reseller->isTrafficBased()) {
            if (! $reseller->hasTrafficRemaining() || ! $reseller->isWindowValid()) {
                return back()->with('error', 'Cannot enable config: reseller quota exceeded or window expired.');
            }
        }

        // Try to enable on remote panel first
        $remoteResult = ['success' => false, 'attempts' => 0, 'last_error' => 'No panel configured'];

        if ($config->panel_id) {
            try {
                $panel = Panel::findOrFail($config->panel_id);
                $provisioner = new ResellerProvisioner;

                // Use panel->panel_type instead of config->panel_type
                $remoteResult = $provisioner->enableUser(
                    $panel->panel_type,
                    $panel->getCredentials(),
                    $config->panel_user_id
                );

                if (! $remoteResult['success']) {
                    Log::warning("Failed to enable config {$config->id} on remote panel {$panel->id} after {$remoteResult['attempts']} attempts: {$remoteResult['last_error']}");
                }
            } catch (\Exception $e) {
                Log::error("Exception enabling config {$config->id} on panel: ".$e->getMessage());
                $remoteResult['last_error'] = $e->getMessage();
            }
        }

        // Update local state after remote attempt
        $config->update([
            'status' => 'active',
            'disabled_at' => null,
        ]);

        ResellerConfigEvent::create([
            'reseller_config_id' => $config->id,
            'type' => 'manual_enabled',
            'meta' => [
                'user_id' => $request->user()->id,
                'remote_success' => $remoteResult['success'],
                'attempts' => $remoteResult['attempts'],
                'last_error' => $remoteResult['last_error'],
                'panel_id' => $config->panel_id,
                'panel_type_used' => $config->panel_id ? Panel::find($config->panel_id)?->panel_type : null,
            ],
        ]);

        // Create audit log entry
        AuditLog::log(
            action: 'config_manual_enabled',
            targetType: 'config',
            targetId: $config->id,
            reason: 'admin_action',
            meta: [
                'remote_success' => $remoteResult['success'],
                'attempts' => $remoteResult['attempts'],
                'last_error' => $remoteResult['last_error'],
                'panel_id' => $config->panel_id,
                'panel_type_used' => $config->panel_id ? Panel::find($config->panel_id)?->panel_type : null,
            ]
        );

        if (! $remoteResult['success']) {
            return back()->with('warning', 'Config enabled locally, but remote panel update failed after '.$remoteResult['attempts'].' attempts.');
        }

        return back()->with('success', 'Config enabled successfully.');
    }

    public function destroy(Request $request, ResellerConfig $config)
    {
        $reseller = $request->user()->reseller;

        // Null safety: check if reseller exists
        if (! $reseller) {
            return back()->with('error', 'Reseller account not found.');
        }

        if ($config->reseller_id !== $reseller->id) {
            abort(403);
        }

        // Try to delete on remote panel
        $remoteFailed = false;
        if ($config->panel_id) {
            try {
                $panel = Panel::findOrFail($config->panel_id);
                $provisioner = new ResellerProvisioner;
                $success = $provisioner->deleteUser($config->panel_type, $panel->getCredentials(), $config->panel_user_id);

                if (! $success) {
                    $remoteFailed = true;
                    Log::warning("Failed to delete config {$config->id} on remote panel {$panel->id}");
                }
            } catch (\Exception $e) {
                $remoteFailed = true;
                Log::error("Exception deleting config {$config->id} on panel: ".$e->getMessage());
            }
        }

        // Update local state regardless of remote result
        $config->update(['status' => 'deleted']);
        $config->delete();

        ResellerConfigEvent::create([
            'reseller_config_id' => $config->id,
            'type' => 'deleted',
            'meta' => [
                'user_id' => $request->user()->id,
                'remote_failed' => $remoteFailed,
            ],
        ]);

        // Create audit log entry
        AuditLog::log(
            action: 'config_deleted',
            targetType: 'config',
            targetId: $config->id,
            reason: 'admin_action',
            meta: [
                'remote_failed' => $remoteFailed,
                'panel_id' => $config->panel_id,
            ]
        );

        if ($remoteFailed) {
            return back()->with('warning', 'Config deleted locally, but remote panel deletion failed.');
        }

        return back()->with('success', 'Config deleted successfully.');
    }

    public function edit(Request $request, ResellerConfig $config)
    {
        // Use policy authorization
        $this->authorize('update', $config);

        $reseller = $request->user()->reseller;

        // Null safety: check if reseller exists
        if (! $reseller) {
            return redirect()->route('reseller.dashboard')
                ->with('error', 'Reseller account not found.');
        }

        if ($config->reseller_id !== $reseller->id) {
            abort(403);
        }

        return view('reseller::configs.edit', [
            'reseller' => $reseller,
            'config' => $config,
        ]);
    }

    public function update(Request $request, ResellerConfig $config)
    {
        // Use policy authorization
        $this->authorize('update', $config);

        $reseller = $request->user()->reseller;

        // Null safety: check if reseller exists
        if (! $reseller) {
            return back()->with('error', 'Reseller account not found.');
        }

        if ($config->reseller_id !== $reseller->id) {
            abort(403);
        }

        $validator = Validator::make($request->all(), [
            'traffic_limit_gb' => 'required|numeric|min:0.1',
            'expires_at' => 'required|date|after_or_equal:today',
            'max_clients' => 'nullable|integer|min:1|max:100', // Max 100 as reasonable safety limit to prevent abuse
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $trafficLimitBytes = (float) $request->input('traffic_limit_gb') * 1024 * 1024 * 1024;
        $expiresAt = \Carbon\Carbon::parse($request->input('expires_at'))->startOfDay();

        // Get max_clients value, default to existing value or 1
        $maxClients = (int) ($request->input('max_clients', $config->meta['max_clients'] ?? 1));

        // Validation: traffic limit cannot be below current usage
        if ($trafficLimitBytes < $config->usage_bytes) {
            return back()->with('error', 'Traffic limit cannot be set below current usage ('.round($config->usage_bytes / (1024 * 1024 * 1024), 2).' GB).');
        }

        // Store old values for audit
        $oldTrafficLimit = $config->traffic_limit_bytes;
        $oldExpiresAt = $config->expires_at;

        $remoteResultFinal = null;

        DB::transaction(function () use ($config, $trafficLimitBytes, $expiresAt, $oldTrafficLimit, $oldExpiresAt, $request, $maxClients, &$remoteResultFinal) {
            // Update local config - also update meta with max_clients
            $meta = $config->meta ?? [];
            $oldMaxClients = $meta['max_clients'] ?? 1;
            $meta['max_clients'] = $maxClients;

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

                    // For Eylandoo panels, use updateUser to support max_clients updates
                    if ($this->isEylandooPanel($panel->panel_type)) {
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

                        if ($maxClients !== $oldMaxClients) {
                            Log::info('Updated Eylandoo user with max_clients', [
                                'config_id' => $config->id,
                                'old_max_clients' => $oldMaxClients,
                                'new_max_clients' => $maxClients,
                            ]);
                        }
                    } else {
                        // For other panels, use standard updateUserLimits
                        $remoteResult = $provisioner->updateUserLimits(
                            $panel->panel_type,
                            $panel->getCredentials(),
                            $config->panel_user_id,
                            $trafficLimitBytes,
                            $expiresAt
                        );
                    }

                    if (! $remoteResult['success']) {
                        Log::warning("Failed to update config {$config->id} on remote panel {$panel->id} after {$remoteResult['attempts']} attempts: {$remoteResult['last_error']}");
                    }
                } catch (\Exception $e) {
                    Log::error("Exception updating config {$config->id} on panel: ".$e->getMessage());
                    $remoteResult['last_error'] = $e->getMessage();
                }
            }

            $remoteResultFinal = $remoteResult;

            ResellerConfigEvent::create([
                'reseller_config_id' => $config->id,
                'type' => 'edited',
                'meta' => [
                    'user_id' => $request->user()->id,
                    'old_traffic_limit_bytes' => $oldTrafficLimit,
                    'new_traffic_limit_bytes' => $trafficLimitBytes,
                    'old_expires_at' => $oldExpiresAt?->toDateTimeString(),
                    'new_expires_at' => $expiresAt->toDateTimeString(),
                    'old_max_clients' => $oldMaxClients,
                    'new_max_clients' => $maxClients,
                    'remote_success' => $remoteResult['success'],
                    'attempts' => $remoteResult['attempts'],
                    'last_error' => $remoteResult['last_error'],
                ],
            ]);

            // Create audit log entry
            AuditLog::log(
                action: 'reseller_config_edited',
                targetType: 'config',
                targetId: $config->id,
                reason: 'reseller_action',
                meta: [
                    'old_traffic_limit_gb' => round($oldTrafficLimit / (1024 * 1024 * 1024), 2),
                    'new_traffic_limit_gb' => round($trafficLimitBytes / (1024 * 1024 * 1024), 2),
                    'old_expires_at' => $oldExpiresAt?->format('Y-m-d'),
                    'new_expires_at' => $expiresAt->format('Y-m-d'),
                    'old_max_clients' => $oldMaxClients,
                    'new_max_clients' => $maxClients,
                    'remote_success' => $remoteResult['success'],
                    'reseller_id' => $config->reseller_id,
                ]
            );
        });

        if ($remoteResultFinal && ! $remoteResultFinal['success']) {
            return redirect()->route('reseller.configs.index')
                ->with('warning', 'Config updated locally, but remote panel update failed after '.$remoteResultFinal['attempts'].' attempts.');
        }

        return redirect()->route('reseller.configs.index')
            ->with('success', 'Config updated successfully.');
    }

    public function resetUsage(Request $request, ResellerConfig $config)
    {
        // Use policy authorization
        $this->authorize('resetUsage', $config);

        $reseller = $request->user()->reseller;

        // Null safety: check if reseller exists
        if (! $reseller) {
            return back()->with('error', 'Reseller account not found.');
        }

        if ($config->reseller_id !== $reseller->id) {
            abort(403);
        }

        $toSettle = $config->usage_bytes;

        $remoteResultFinal = null;
        $toSettleFinal = $toSettle;

        DB::transaction(function () use ($config, $toSettle, $request, &$remoteResultFinal) {
            // Settle current usage
            $meta = $config->meta ?? [];
            $currentSettled = (int) data_get($meta, 'settled_usage_bytes', 0);
            $newSettled = $currentSettled + $toSettle;

            $meta['settled_usage_bytes'] = $newSettled;
            $meta['last_reset_at'] = now()->toDateTimeString();

            // For Eylandoo configs, also zero the meta usage fields
            if ($this->isEylandooPanel($config->panel_type)) {
                $meta['used_traffic'] = 0;
                $meta['data_used'] = 0;
            }

            // Reset local usage
            $config->update([
                'usage_bytes' => 0,
                'meta' => $meta,
            ]);

            // Try to reset on remote panel
            $remoteResult = ['success' => false, 'attempts' => 0, 'last_error' => 'No panel configured'];

            if ($config->panel_id) {
                try {
                    $panel = Panel::findOrFail($config->panel_id);
                    $provisioner = new ResellerProvisioner;

                    $remoteResult = $provisioner->resetUserUsage(
                        $panel->panel_type,
                        $panel->getCredentials(),
                        $config->panel_user_id
                    );

                    if (! $remoteResult['success']) {
                        Log::warning("Failed to reset usage for config {$config->id} on remote panel {$panel->id} after {$remoteResult['attempts']} attempts: {$remoteResult['last_error']}");
                    }
                } catch (\Exception $e) {
                    Log::error("Exception resetting usage for config {$config->id} on panel: ".$e->getMessage());
                    $remoteResult['last_error'] = $e->getMessage();
                }
            }

            $remoteResultFinal = $remoteResult;

            // Recalculate and persist reseller aggregate after reset
            // Include settled_usage_bytes to prevent abuse
            // Subtract admin_forgiven_bytes to honor admin quota forgiveness
            $reseller = $config->reseller;
            $totalUsageBytesFromDB = $reseller->configs()
                ->get()
                ->sum(function ($c) {
                    return $c->usage_bytes + (int) data_get($c->meta, 'settled_usage_bytes', 0);
                });

            // Subtract admin_forgiven_bytes to honor admin quota forgiveness
            $adminForgivenBytes = $reseller->admin_forgiven_bytes ?? 0;
            $effectiveUsageBytes = max(0, $totalUsageBytesFromDB - $adminForgivenBytes);

            $reseller->update(['traffic_used_bytes' => $effectiveUsageBytes]);

            Log::info('Config reset updated reseller aggregate', [
                'reseller_id' => $reseller->id,
                'config_id' => $config->id,
                'total_from_configs' => $totalUsageBytesFromDB,
                'admin_forgiven_bytes' => $adminForgivenBytes,
                'new_reseller_usage_bytes' => $effectiveUsageBytes,
            ]);

            ResellerConfigEvent::create([
                'reseller_config_id' => $config->id,
                'type' => 'usage_reset',
                'meta' => [
                    'user_id' => $request->user()->id,
                    'bytes_settled' => $toSettle,
                    'new_settled_total' => $newSettled,
                    'last_reset_at' => $meta['last_reset_at'],
                    'remote_success' => $remoteResult['success'],
                    'attempts' => $remoteResult['attempts'],
                    'last_error' => $remoteResult['last_error'],
                ],
            ]);

            // Create audit log entry
            AuditLog::log(
                action: 'config_usage_reset',
                targetType: 'config',
                targetId: $config->id,
                reason: 'reseller_action',
                meta: [
                    'bytes_settled' => $toSettle,
                    'bytes_settled_gb' => round($toSettle / (1024 * 1024 * 1024), 2),
                    'new_settled_total' => $newSettled,
                    'last_reset_at' => $meta['last_reset_at'],
                    'remote_success' => $remoteResult['success'],
                    'reseller_id' => $config->reseller_id,
                ]
            );
        });

        if ($remoteResultFinal && ! $remoteResultFinal['success']) {
            return back()->with('warning', 'Usage reset locally (settled '.round($toSettleFinal / (1024 * 1024 * 1024), 2).' GB), but remote panel reset failed after '.$remoteResultFinal['attempts'].' attempts.');
        }

        return back()->with('success', 'Usage reset successfully. Settled '.round($toSettleFinal / (1024 * 1024 * 1024), 2).' GB to your account.');
    }
}
