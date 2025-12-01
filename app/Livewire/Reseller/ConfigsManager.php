<?php

namespace App\Livewire\Reseller;

use App\Models\Panel;
use App\Models\Plan;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use App\Services\ConfigNameGenerator;
use App\Services\PanelDataService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Reseller\Services\ResellerProvisioner;

class ConfigsManager extends Component
{
    use WithPagination;

    // Search and filters
    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = 'all';

    #[Url]
    public int $perPage = 20;

    // Modal state
    public bool $showCreateModal = false;
    public bool $showEditModal = false;
    public ?int $editingConfigId = null;

    // Create/Edit form fields
    public $usernamePrefix = '';  // New: Username field (prefix) shown to users
    public $selectedPanelId = '';
    public $trafficLimitGb = '';
    public $expiresDays = '';
    public $maxClients = 1;
    public $comment = '';
    public $prefix = '';
    public $customName = '';
    public $selectedNodeIds = [];
    public $selectedServiceIds = [];

    // Edit-specific fields
    public $editTrafficLimitGb = '';
    public $editExpiresAt = '';
    public $editMaxClients = 1;

    // Stats
    public $reseller;
    public $stats = [];

    protected $listeners = ['refreshConfigs' => '$refresh'];

    protected function rules()
    {
        return [
            'usernamePrefix' => 'nullable|string|min:2|max:32|regex:/^[a-zA-Z0-9]+$/',
            'selectedPanelId' => 'required|exists:panels,id',
            'trafficLimitGb' => 'required|numeric|min:0.1',
            'expiresDays' => 'required|integer|min:1',
            'maxClients' => 'nullable|integer|min:1|max:100',
            'comment' => 'nullable|string|max:200',
            'prefix' => 'nullable|string|max:50|regex:/^[a-zA-Z0-9_-]+$/',
            'customName' => 'nullable|string|max:100|regex:/^[a-zA-Z0-9_-]+$/',
        ];
    }

    public function mount()
    {
        $this->loadReseller();
    }

    protected function loadReseller()
    {
        $this->reseller = Auth::user()->reseller;
        if ($this->reseller) {
            $this->loadStats();
        }
    }

    protected function loadStats()
    {
        $reseller = $this->reseller;

        if ($reseller->isWalletBased()) {
            $totalConfigs = $reseller->configs()->count();
            $configLimit = $reseller->config_limit;
            $isUnlimitedLimit = is_null($configLimit) || $configLimit === 0;

            $configs = $reseller->configs()->get();
            $trafficConsumedBytes = $configs->sum(function ($config) {
                return $config->usage_bytes + (int) data_get($config->meta, 'settled_usage_bytes', 0);
            });

            $this->stats = [
                'wallet_balance' => $reseller->wallet_balance,
                'wallet_price_per_gb' => $reseller->getWalletPricePerGb(),
                'traffic_consumed_bytes' => $trafficConsumedBytes,
                'traffic_consumed_gb' => round($trafficConsumedBytes / (1024 * 1024 * 1024), 2),
                'active_configs' => $reseller->configs()->where('status', 'active')->count(),
                'total_configs' => $totalConfigs,
                'config_limit' => $configLimit,
                'is_unlimited_limit' => $isUnlimitedLimit,
            ];
        } else {
            // Traffic-based
            $totalConfigs = $reseller->configs()->count();
            $configLimit = $reseller->config_limit;
            $isUnlimitedLimit = is_null($configLimit) || $configLimit === 0;

            $configs = $reseller->configs()->get();
            $trafficCurrentBytes = $configs->sum('usage_bytes');
            $trafficSettledBytes = $configs->sum(function ($config) {
                return (int) data_get($config->meta, 'settled_usage_bytes', 0);
            });
            $trafficConsumedBytes = $trafficCurrentBytes + $trafficSettledBytes;

            $this->stats = [
                'traffic_total_gb' => $reseller->traffic_total_bytes ? round($reseller->traffic_total_bytes / (1024 * 1024 * 1024), 2) : 0,
                'traffic_consumed_bytes' => $trafficConsumedBytes,
                'traffic_consumed_gb' => round($trafficConsumedBytes / (1024 * 1024 * 1024), 2),
                'traffic_remaining_gb' => $reseller->traffic_total_bytes ? round(($reseller->traffic_total_bytes - $reseller->traffic_used_bytes) / (1024 * 1024 * 1024), 2) : 0,
                'active_configs' => $reseller->configs()->where('status', 'active')->count(),
                'total_configs' => $totalConfigs,
                'config_limit' => $configLimit,
                'is_unlimited_limit' => $isUnlimitedLimit,
                'days_remaining' => $reseller->window_ends_at ? now()->diffInDays($reseller->window_ends_at, false) : null,
            ];
        }
    }

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingStatusFilter()
    {
        $this->resetPage();
    }

    public function updatingPerPage()
    {
        $this->resetPage();
    }

    public function setStatusFilter($status)
    {
        $this->statusFilter = $status;
        $this->resetPage();
    }

    public function openCreateModal()
    {
        $this->resetCreateForm();
        $this->showCreateModal = true;
    }

    public function closeCreateModal()
    {
        $this->showCreateModal = false;
        $this->resetCreateForm();
    }

    public function openEditModal($configId)
    {
        $config = ResellerConfig::find($configId);
        if (!$config || $config->reseller_id !== $this->reseller->id) {
            session()->flash('error', 'کانفیگ یافت نشد.');
            return;
        }

        $this->editingConfigId = $configId;
        $this->editTrafficLimitGb = round($config->traffic_limit_bytes / (1024 * 1024 * 1024), 2);
        $this->editExpiresAt = $config->expires_at->format('Y-m-d');
        $this->editMaxClients = $config->meta['max_clients'] ?? 1;
        $this->showEditModal = true;
    }

    public function closeEditModal()
    {
        $this->showEditModal = false;
        $this->editingConfigId = null;
        $this->editTrafficLimitGb = '';
        $this->editExpiresAt = '';
        $this->editMaxClients = 1;
    }

    protected function resetCreateForm()
    {
        $this->usernamePrefix = '';
        $this->selectedPanelId = '';
        $this->trafficLimitGb = '';
        $this->expiresDays = '';
        $this->maxClients = 1;
        $this->comment = '';
        $this->prefix = '';
        $this->customName = '';
        $this->selectedNodeIds = [];
        $this->selectedServiceIds = [];
    }

    public function createConfig()
    {
        // Debug logging for input values to aid diagnostics
        Log::debug('ConfigsManager::createConfig - Input values', [
            'expiresDays' => $this->expiresDays,
            'expiresDays_type' => gettype($this->expiresDays),
            'trafficLimitGb' => $this->trafficLimitGb,
            'selectedPanelId' => $this->selectedPanelId,
        ]);

        // Validate all required fields plus usernamePrefix with custom error messages
        $this->validate([
            'selectedPanelId' => 'required|exists:panels,id',
            'trafficLimitGb' => 'required|numeric|min:0.1',
            'expiresDays' => 'required|integer|min:1',
            'usernamePrefix' => 'nullable|string|min:2|max:32|regex:/^[a-zA-Z0-9]+$/',
        ], [
            'usernamePrefix.min' => 'نام کاربری باید حداقل ۲ کاراکتر باشد.',
            'usernamePrefix.max' => 'نام کاربری نمی‌تواند بیش از ۳۲ کاراکتر باشد.',
            'usernamePrefix.regex' => 'نام کاربری فقط می‌تواند شامل حروف و اعداد انگلیسی باشد.',
            'expiresDays.required' => 'مدت زمان انقضا الزامی است.',
            'expiresDays.integer' => 'مدت زمان انقضا باید یک عدد صحیح باشد.',
            'expiresDays.min' => 'مدت زمان انقضا باید حداقل ۱ روز باشد.',
        ]);

        $reseller = $this->reseller;

        // Check config_limit enforcement
        if ($reseller->config_limit !== null && $reseller->config_limit > 0) {
            $totalConfigsCount = $reseller->configs()->count();
            if ($totalConfigsCount >= $reseller->config_limit) {
                session()->flash('error', "Config creation limit reached. Maximum allowed: {$reseller->config_limit}");
                return;
            }
        }

        // Validate panel access
        $hasAccess = $reseller->hasPanelAccess($this->selectedPanelId)
            || $reseller->panel_id == $this->selectedPanelId
            || $reseller->primary_panel_id == $this->selectedPanelId;

        if (!$hasAccess) {
            session()->flash('error', 'You do not have access to the selected panel.');
            return;
        }

        $panel = Panel::findOrFail($this->selectedPanelId);
        $trafficLimitBytes = (float) $this->trafficLimitGb * 1024 * 1024 * 1024;

        // Cast expiresDays to int to prevent TypeError in Carbon::addDays()
        // Livewire form inputs are strings even with 'integer' validation
        // Use is_numeric guard for additional safety before casting
        if (!is_numeric($this->expiresDays)) {
            Log::warning('ConfigsManager::createConfig - expiresDays is not numeric', [
                'expiresDays' => $this->expiresDays,
                'type' => gettype($this->expiresDays),
            ]);
            session()->flash('error', 'مدت زمان انقضا باید یک عدد معتبر باشد.');
            return;
        }
        $expiresDaysInt = (int) $this->expiresDays;
        if ($expiresDaysInt < 1) {
            Log::warning('ConfigsManager::createConfig - expiresDaysInt is less than 1', [
                'expiresDaysInt' => $expiresDaysInt,
            ]);
            session()->flash('error', 'مدت زمان انقضا باید حداقل ۱ روز باشد.');
            return;
        }

        // Log the computed expiry path (using days)
        Log::debug('ConfigsManager::createConfig - Using days-based expiry', [
            'expiresDaysInt' => $expiresDaysInt,
        ]);

        $expiresAt = now()->addDays($expiresDaysInt)->startOfDay();
        $nodeIds = array_map('intval', (array) $this->selectedNodeIds);
        $maxClients = (int) ($this->maxClients ?: 1);

        try {
            DB::transaction(function () use ($reseller, $panel, $trafficLimitBytes, $expiresAt, $nodeIds, $maxClients, $expiresDaysInt) {
                $provisioner = new ResellerProvisioner;
                $user = Auth::user();

                $prefix = null;
                $customName = null;
                $storedUsernamePrefix = null;
                $panelUsername = null;

                if ($user->can('configs.set_prefix') && $this->prefix) {
                    $prefix = $this->prefix;
                }

                if ($user->can('configs.set_custom_name') && $this->customName) {
                    $customName = $this->customName;
                }

                // Use usernamePrefix to generate panel username if provided
                if ($this->usernamePrefix) {
                    $usernameGenerator = new \App\Services\UsernameGenerator();
                    $sanitizedPrefix = $usernameGenerator->sanitizePrefix($this->usernamePrefix);
                    $generatedData = $usernameGenerator->generatePanelUsername($sanitizedPrefix);
                    $panelUsername = $generatedData['panel_username'];
                    $storedUsernamePrefix = $generatedData['username_prefix'];
                }

                $username = '';
                $nameVersion = null;

                if ($customName) {
                    $username = $customName;
                    // If custom name is used, derive display prefix from it
                    if (!$storedUsernamePrefix) {
                        $usernameGenerator = new \App\Services\UsernameGenerator();
                        $storedUsernamePrefix = $usernameGenerator->extractDisplayPrefix($customName);
                    }
                } elseif ($panelUsername) {
                    // Use the generated panel username from usernamePrefix
                    $username = $panelUsername;
                } else {
                    $generator = new ConfigNameGenerator;
                    $generatorOptions = [];
                    if ($prefix) {
                        $generatorOptions['prefix'] = $prefix;
                    }
                    $nameData = $generator->generate($reseller, $panel, $reseller->type, $generatorOptions);
                    $username = $nameData['name'];
                    $nameVersion = $nameData['version'];
                    
                    // Extract display prefix from generated name if not already set
                    if (!$storedUsernamePrefix) {
                        $usernameGenerator = new \App\Services\UsernameGenerator();
                        $storedUsernamePrefix = $usernameGenerator->extractDisplayPrefix($username);
                    }
                }

                $config = ResellerConfig::create([
                    'reseller_id' => $reseller->id,
                    'external_username' => $username,
                    'username_prefix' => $storedUsernamePrefix,
                    'panel_username' => $panelUsername ?: $username,
                    'name_version' => $nameVersion,
                    'comment' => $this->comment ?: null,
                    'prefix' => $prefix,
                    'custom_name' => $customName,
                    'traffic_limit_bytes' => $trafficLimitBytes,
                    'usage_bytes' => 0,
                    'expires_at' => $expiresAt,
                    'status' => 'active',
                    'panel_type' => $panel->panel_type,
                    'panel_id' => $panel->id,
                    'created_by' => Auth::id(),
                    'meta' => [
                        'node_ids' => $nodeIds,
                        'max_clients' => $maxClients,
                    ],
                ]);

                $plan = new Plan;
                $plan->volume_gb = (float) $this->trafficLimitGb;
                $plan->duration_days = $expiresDaysInt;
                $plan->marzneshin_service_ids = (array) $this->selectedServiceIds;

                $result = $provisioner->provisionUser($panel, $plan, $username, [
                    'traffic_limit_bytes' => $trafficLimitBytes,
                    'expires_at' => $expiresAt,
                    'service_ids' => $plan->marzneshin_service_ids,
                    'connections' => 1,
                    'max_clients' => $maxClients,
                    'nodes' => $nodeIds,
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

                    session()->flash('success', 'کانفیگ با موفقیت ایجاد شد.');
                } else {
                    $config->delete();
                    throw new \Exception('Failed to provision config on the panel.');
                }
            });

            $this->closeCreateModal();
            $this->loadStats();
        } catch (\Exception $e) {
            Log::error('Config creation failed: ' . $e->getMessage());
            session()->flash('error', 'خطا در ایجاد کانفیگ: ' . $e->getMessage());
        }
    }

    public function updateConfig()
    {
        $this->validate([
            'editTrafficLimitGb' => 'required|numeric|min:0.1',
            'editExpiresAt' => 'required|date|after_or_equal:today',
        ]);

        $config = ResellerConfig::find($this->editingConfigId);
        if (!$config || $config->reseller_id !== $this->reseller->id) {
            session()->flash('error', 'کانفیگ یافت نشد.');
            return;
        }

        $trafficLimitBytes = (float) $this->editTrafficLimitGb * 1024 * 1024 * 1024;
        $expiresAt = \Carbon\Carbon::parse($this->editExpiresAt)->startOfDay();
        $maxClients = (int) ($this->editMaxClients ?: 1);

        if ($trafficLimitBytes < $config->usage_bytes) {
            session()->flash('error', 'Traffic limit cannot be set below current usage.');
            return;
        }

        try {
            DB::transaction(function () use ($config, $trafficLimitBytes, $expiresAt, $maxClients) {
                $oldTrafficLimit = $config->traffic_limit_bytes;
                $oldExpiresAt = $config->expires_at;

                $meta = $config->meta ?? [];
                $oldMaxClients = $meta['max_clients'] ?? 1;
                $meta['max_clients'] = $maxClients;

                $config->update([
                    'traffic_limit_bytes' => $trafficLimitBytes,
                    'expires_at' => $expiresAt,
                    'meta' => $meta,
                ]);

                // Update on remote panel
                if ($config->panel_id) {
                    $panel = Panel::find($config->panel_id);
                    if ($panel) {
                        $provisioner = new ResellerProvisioner;
                        $panelType = strtolower(trim($panel->panel_type ?? ''));

                        if ($panelType === 'eylandoo') {
                            $provisioner->updateUser(
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
                            $provisioner->updateUserLimits(
                                $panel->panel_type,
                                $panel->getCredentials(),
                                $config->panel_user_id,
                                $trafficLimitBytes,
                                $expiresAt
                            );
                        }
                    }
                }

                ResellerConfigEvent::create([
                    'reseller_config_id' => $config->id,
                    'type' => 'edited',
                    'meta' => [
                        'user_id' => Auth::id(),
                        'old_traffic_limit_bytes' => $oldTrafficLimit,
                        'new_traffic_limit_bytes' => $trafficLimitBytes,
                        'old_expires_at' => $oldExpiresAt?->toDateTimeString(),
                        'new_expires_at' => $expiresAt->toDateTimeString(),
                        'old_max_clients' => $oldMaxClients,
                        'new_max_clients' => $maxClients,
                    ],
                ]);

                session()->flash('success', 'کانفیگ با موفقیت بروزرسانی شد.');
            });

            $this->closeEditModal();
            $this->loadStats();
        } catch (\Exception $e) {
            Log::error('Config update failed: ' . $e->getMessage());
            session()->flash('error', 'خطا در بروزرسانی کانفیگ: ' . $e->getMessage());
        }
    }

    public function resetTraffic($configId)
    {
        $config = ResellerConfig::find($configId);
        if (!$config || $config->reseller_id !== $this->reseller->id) {
            session()->flash('error', 'کانفیگ یافت نشد.');
            return;
        }

        try {
            DB::transaction(function () use ($config) {
                $oldUsageBytes = $config->usage_bytes;

                // Try to reset on remote panel first
                $panelResetSuccess = false;
                if ($config->panel_id) {
                    $panel = Panel::find($config->panel_id);
                    if ($panel) {
                        $provisioner = new ResellerProvisioner;
                        $result = $provisioner->resetUserUsage(
                            $panel->panel_type,
                            $panel->getCredentials(),
                            $config->panel_user_id
                        );
                        $panelResetSuccess = $result['success'] ?? false;
                    }
                }

                // Reset local usage counter
                $config->update([
                    'usage_bytes' => 0,
                ]);

                // Create audit log entry
                ResellerConfigEvent::create([
                    'reseller_config_id' => $config->id,
                    'type' => 'traffic_reset',
                    'meta' => [
                        'user_id' => Auth::id(),
                        'old_usage_bytes' => $oldUsageBytes,
                        'panel_reset_success' => $panelResetSuccess,
                        'reset_at' => now()->toDateTimeString(),
                    ],
                ]);

                session()->flash('success', 'ترافیک کاربر با موفقیت صفر شد.');
            });

            $this->loadStats();
        } catch (\Exception $e) {
            Log::error('Traffic reset failed: ' . $e->getMessage());
            session()->flash('error', 'خطا در ریست کردن ترافیک: ' . $e->getMessage());
        }
    }

    public function toggleStatus($configId)
    {
        $config = ResellerConfig::find($configId);
        if (!$config || $config->reseller_id !== $this->reseller->id) {
            session()->flash('error', 'کانفیگ یافت نشد.');
            return;
        }

        try {
            $provisioner = new ResellerProvisioner;
            $panel = $config->panel_id ? Panel::find($config->panel_id) : null;

            if ($config->isActive()) {
                if ($panel) {
                    $provisioner->disableUser(
                        $panel->panel_type,
                        $panel->getCredentials(),
                        $config->panel_user_id
                    );
                }
                $config->update([
                    'status' => 'disabled',
                    'disabled_at' => now(),
                ]);
                session()->flash('success', 'کانفیگ غیرفعال شد.');
            } else {
                if ($panel) {
                    $provisioner->enableUser(
                        $panel->panel_type,
                        $panel->getCredentials(),
                        $config->panel_user_id
                    );
                }
                $config->update([
                    'status' => 'active',
                    'disabled_at' => null,
                ]);
                session()->flash('success', 'کانفیگ فعال شد.');
            }

            $this->loadStats();
        } catch (\Exception $e) {
            Log::error('Config toggle failed: ' . $e->getMessage());
            session()->flash('error', 'خطا در تغییر وضعیت کانفیگ.');
        }
    }

    public function deleteConfig($configId)
    {
        $config = ResellerConfig::find($configId);
        if (!$config || $config->reseller_id !== $this->reseller->id) {
            session()->flash('error', 'کانفیگ یافت نشد.');
            return;
        }

        try {
            $provisioner = new ResellerProvisioner;
            $panel = $config->panel_id ? Panel::find($config->panel_id) : null;

            if ($panel) {
                $provisioner->deleteUser($config->panel_type, $panel->getCredentials(), $config->panel_user_id);
            }

            $config->update(['status' => 'deleted']);
            $config->delete();

            ResellerConfigEvent::create([
                'reseller_config_id' => $config->id,
                'type' => 'deleted',
                'meta' => ['user_id' => Auth::id()],
            ]);

            session()->flash('success', 'کانفیگ با موفقیت حذف شد.');
            $this->loadStats();
        } catch (\Exception $e) {
            Log::error('Config deletion failed: ' . $e->getMessage());
            session()->flash('error', 'خطا در حذف کانفیگ.');
        }
    }

    public function syncStats()
    {
        $this->loadStats();
        session()->flash('success', 'آمار بروزرسانی شد.');
    }

    public function getPanelsProperty()
    {
        if (!$this->reseller) {
            return collect();
        }
        return $this->reseller->panels()->where('is_active', true)->get();
    }

    public function getPanelsForJsProperty()
    {
        if (!$this->reseller) {
            return [];
        }
        $panelDataService = new PanelDataService;
        return $panelDataService->getPanelsForReseller($this->reseller);
    }

    public function render()
    {
        if (!$this->reseller || !$this->reseller->supportsConfigManagement()) {
            return view('livewire.reseller.configs-manager', [
                'configs' => collect(),
                'unsupported' => true,
            ]);
        }

        $query = $this->reseller->configs()
            ->when($this->search, function ($q) {
                $q->where(function ($query) {
                    $query->where('external_username', 'like', '%' . $this->search . '%')
                        ->orWhere('username_prefix', 'like', '%' . $this->search . '%')
                        ->orWhere('comment', 'like', '%' . $this->search . '%');
                });
            })
            ->when($this->statusFilter !== 'all', function ($q) {
                if ($this->statusFilter === 'expiring') {
                    $q->where('status', 'active')
                      ->where('expires_at', '<=', now()->addDays(7));
                } else {
                    $q->where('status', $this->statusFilter);
                }
            })
            ->orderByDesc('created_at');

        $configs = $query->paginate($this->perPage);

        return view('livewire.reseller.configs-manager', [
            'configs' => $configs,
            'unsupported' => false,
        ]);
    }
}
