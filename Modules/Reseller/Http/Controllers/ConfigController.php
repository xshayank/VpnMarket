<?php

namespace Modules\Reseller\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Panel;
use App\Models\Plan;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use App\Services\ConfigNameGenerator;
use App\Services\PanelDataService;
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

        // Get panels the reseller has access to via the pivot table
        $panels = $reseller->panels()->where('is_active', true)->get();

        // If no panels available, show error
        if ($panels->isEmpty()) {
            return redirect()->route('reseller.dashboard')
                ->with('error', 'No panels assigned to your account. Please contact support.');
        }

        // Use PanelDataService to get JS-friendly panels array with nodes/services
        $panelDataService = new PanelDataService;
        $panelsForJs = $panelDataService->getPanelsForReseller($reseller);

        // Determine prefill panel ID from old input or query param
        $prefillPanelId = old('panel_id') ?? $request->query('panel_id') ?? null;

        // Log panel data fetch for debugging
        if ($prefillPanelId) {
            $selectedPanel = collect($panelsForJs)->firstWhere('id', (int) $prefillPanelId);
            if ($selectedPanel) {
                Log::info('config_create_panel_prefilled', [
                    'reseller_id' => $reseller->id,
                    'panel_id' => $prefillPanelId,
                    'panel_type' => $selectedPanel['panel_type'],
                    'nodes_count' => count($selectedPanel['nodes'] ?? []),
                    'services_count' => count($selectedPanel['services'] ?? []),
                ]);
            }
        }

        // Legacy: Keep marzneshin_services for backward compatibility (if needed)
        // This can be removed if no other parts of the view depend on it
        $marzneshin_services = [];
        if ($reseller->marzneshin_allowed_service_ids) {
            $marzneshin_services = $reseller->marzneshin_allowed_service_ids;
        }

        return view('reseller::configs.create', [
            'reseller' => $reseller,
            'panels' => $panels,
            'panelsForJs' => $panelsForJs,
            'prefillPanelId' => $prefillPanelId,
            'marzneshin_services' => $marzneshin_services, // Legacy support
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
            'max_clients' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        // Validate reseller has access to the selected panel
        // Support both new pivot table approach and legacy panel_id field
        $hasAccess = $reseller->hasPanelAccess($request->panel_id)
            || $reseller->panel_id == $request->panel_id
            || $reseller->primary_panel_id == $request->panel_id;

        if (! $hasAccess) {
            return back()->with('error', 'You do not have access to the selected panel.');
        }

        $panel = Panel::findOrFail($request->panel_id);

        // Panel-specific validation
        $panelType = strtolower(trim($panel->panel_type ?? ''));

        // Log config creation with panel selection
        Log::info('config_create_panel_selected', [
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => $panelType,
            'node_ids_count' => count($request->node_ids ?? []),
            'service_ids_count' => count($request->service_ids ?? []),
        ]);

        // Validate that Eylandoo-specific fields are only sent for Eylandoo panels
        if ($panelType !== 'eylandoo' && $request->filled('node_ids')) {
            return back()->with('error', 'Node selection is only available for Eylandoo panels.');
        }

        // Validate that Marzneshin-specific fields are only sent for Marzneshin panels
        if ($panelType !== 'marzneshin' && $request->filled('service_ids')) {
            return back()->with('error', 'Service selection is only available for Marzneshin panels.');
        }

        $expiresDays = $request->integer('expires_days');
        $trafficLimitBytes = (float) $request->input('traffic_limit_gb') * 1024 * 1024 * 1024;
        // Normalize to start of day for calendar-day boundaries
        $expiresAt = now()->addDays($expiresDays)->startOfDay();

        // Validate nodes/services based on pivot whitelist
        $panelAccess = $reseller->panelAccess($panel->id);

        // Validate Marzneshin service whitelist from pivot
        if ($panel->panel_type === 'marzneshin' && $panelAccess && $panelAccess->allowed_service_ids) {
            $serviceIds = $request->service_ids ?? [];
            $allowedServiceIds = json_decode($panelAccess->allowed_service_ids, true) ?: [];

            foreach ($serviceIds as $serviceId) {
                if (! in_array($serviceId, $allowedServiceIds)) {
                    return back()->with('error', 'One or more selected services are not allowed for your account.');
                }
            }
        }

        // Validate Eylandoo node whitelist from pivot or legacy field
        $nodeIds = array_map('intval', (array) ($request->node_ids ?? []));
        $filteredOutCount = 0;

        if ($this->isEylandooPanel($panel->panel_type)) {
            $allowedNodeIds = null;

            // Try to get whitelist from pivot table first
            if ($panelAccess && $panelAccess->allowed_node_ids) {
                $allowedNodeIds = json_decode($panelAccess->allowed_node_ids, true) ?: [];
                $allowedNodeIds = array_map('intval', (array) $allowedNodeIds);
            }
            // Fallback to legacy reseller field
            elseif ($reseller->eylandoo_allowed_node_ids) {
                $allowedNodeIds = is_array($reseller->eylandoo_allowed_node_ids)
                    ? array_map('intval', $reseller->eylandoo_allowed_node_ids)
                    : [];
            }

            // Validate if whitelist exists
            if ($allowedNodeIds) {
                foreach ($nodeIds as $nodeId) {
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
                $generator = new ConfigNameGenerator;

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
