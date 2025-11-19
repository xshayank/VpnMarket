<?php

namespace App\Filament\Pages;

use App\Helpers\OwnerExtraction;
use App\Models\AuditLog;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use App\Services\MarzbanService;
use App\Services\MarzneshinService;
use Filament\Forms\Components\MultiSelect;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AttachPanelConfigsToReseller extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static string $view = 'filament.pages.attach-panel-configs-to-reseller';

    protected static ?string $navigationLabel = 'اتصال کانفیگ‌های پنل به ریسلر';

    protected static ?string $title = 'اتصال کانفیگ‌های پنل به ریسلر';

    protected static ?string $navigationGroup = 'مدیریت فروشندگان';

    protected static ?int $navigationSort = 50;

    public ?array $data = [];

    /**
     * Get the page slug for routing.
     */
    public static function getSlug(): string
    {
        return 'attach-panel-configs-to-reseller';
    }

    /**
     * Determine if the page should be registered in navigation.
     * Only show if user has access.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /**
     * Determine if the current user can access this page.
     *
     * Supports multiple authorization methods:
     * 1. Spatie permission 'manage.panel-config-imports' (if installed)
     * 2. Shield-generated page permission (if installed)
     * 3. Common admin roles: 'super-admin', 'admin' (if Spatie roles installed)
     * 4. User::is_admin boolean field (fallback)
     *
     * Note: Using method_exists() for feature detection is intentional and efficient.
     * This method is only called once per request by Filament, and the checks are
     * lightweight. This approach ensures forward compatibility without requiring
     * package installation or interface implementation.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        // Check if Spatie Permission package is available
        if (method_exists($user, 'hasPermissionTo')) {
            try {
                // If user has the specific permission, grant access
                if ($user->hasPermissionTo('manage.panel-config-imports')) {
                    return true;
                }
            } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist $e) {
                // Permission doesn't exist, continue to other checks
            }

            try {
                // Check Shield-generated page permission
                if ($user->hasPermissionTo('page_AttachPanelConfigsToReseller')) {
                    return true;
                }
            } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist $e) {
                // Permission doesn't exist, continue to other checks
            }
        }

        // Check if Spatie Roles are available
        if (method_exists($user, 'hasRole')) {
            // If user has admin or super-admin role, grant access
            if ($user->hasRole(['super-admin', 'admin'])) {
                return true;
            }
        }

        // Fallback to is_admin boolean field
        return $user->is_admin === true;
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('انتخاب ریسلر و ادمین پنل')
                ->description('ابتدا ریسلر را انتخاب کنید، سپس ادمین پنل (بدون دسترسی سوپر) را انتخاب کنید. کانفیگ‌های متعلق به آن ادمین نمایش داده می‌شوند.')
                ->schema([
                    Select::make('reseller_id')
                        ->label('ریسلر')
                        ->options(function () {
                            return Reseller::whereHas('panel', function ($query) {
                                $query->whereIn('panel_type', ['marzban', 'marzneshin']);
                            })
                                ->with('panel', 'user')
                                ->get()
                                ->mapWithKeys(function ($reseller) {
                                    $panelName = $reseller->panel->name ?? 'N/A';
                                    $userName = $reseller->user->name ?? $reseller->user->username ?? 'N/A';

                                    return [
                                        $reseller->id => "{$userName} - {$panelName} ({$reseller->panel->panel_type})",
                                    ];
                                });
                        })
                        ->required()
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function (callable $set) {
                            $set('panel_admin', null);
                            $set('remote_configs', []);
                        }),

                    Select::make('panel_admin')
                        ->label('ادمین پنل (بدون دسترسی سوپر)')
                        ->options(function (Get $get) {
                            $resellerId = $get('reseller_id');
                            if (! $resellerId) {
                                return [];
                            }

                            $reseller = Reseller::with('panel')->find($resellerId);
                            if (! $reseller || ! $reseller->panel) {
                                return [];
                            }

                            $admins = $this->fetchPanelAdmins($reseller->panel);

                            return collect($admins)->mapWithKeys(function ($admin) {
                                return [$admin['username'] => $admin['username']];
                            });
                        })
                        ->required()
                        ->searchable()
                        ->live()
                        ->visible(fn (Get $get) => $get('reseller_id') !== null)
                        ->afterStateUpdated(function (callable $set) {
                            $set('remote_configs', []);
                        }),

                    MultiSelect::make('remote_configs')
                        ->label('کانفیگ‌های پنل')
                        ->options(function (Get $get) {
                            $resellerId = $get('reseller_id');
                            $adminUsername = $get('panel_admin');

                            if (! $resellerId || ! $adminUsername) {
                                return [];
                            }

                            $reseller = Reseller::with('panel')->find($resellerId);
                            if (! $reseller || ! $reseller->panel) {
                                return [];
                            }

                            $configs = $this->fetchConfigsByAdmin($reseller->panel, $adminUsername);

                            return collect($configs)->mapWithKeys(function ($config) {
                                $status = $config['status'] ?? 'unknown';

                                return [
                                    $config['username'] => "{$config['username']} ({$status})",
                                ];
                            });
                        })
                        ->required()
                        ->searchable()
                        ->disabled(fn (Get $get) => $get('panel_admin') === null)
                        ->visible(fn (Get $get) => $get('reseller_id') !== null)
                        ->helperText(function (Get $get) {
                            if ($get('panel_admin') === null) {
                                return 'ابتدا مدیر (بدون دسترسی سوپر) را انتخاب کنید';
                            }

                            return 'کانفیگ‌هایی که قبلاً وارد شده‌اند، به صورت خودکار نادیده گرفته می‌شوند';
                        }),
                ])
                ->columns(1),
        ])->statePath('data');
    }

    protected function fetchPanelAdmins(Panel $panel): array
    {
        // Cache for 60 seconds to avoid repeated API calls during form interactions
        return cache()->remember(
            "panel_admins_{$panel->id}",
            60,
            function () use ($panel) {
                $credentials = $panel->getCredentials();

                if ($panel->panel_type === 'marzban') {
                    $service = new MarzbanService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password'],
                        $credentials['extra']['node_hostname'] ?? ''
                    );

                    return $service->listAdmins();
                } elseif ($panel->panel_type === 'marzneshin') {
                    $service = new MarzneshinService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password'],
                        $credentials['extra']['node_hostname'] ?? ''
                    );

                    return $service->listAdmins();
                }

                return [];
            }
        );
    }

    protected function fetchConfigsByAdmin(Panel $panel, string $adminUsername): array
    {
        // Cache for 30 seconds to avoid repeated API calls during config selection
        return cache()->remember(
            "panel_configs_{$panel->id}_{$adminUsername}",
            30,
            function () use ($panel, $adminUsername) {
                $credentials = $panel->getCredentials();

                if ($panel->panel_type === 'marzban') {
                    $service = new MarzbanService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password'],
                        $credentials['extra']['node_hostname'] ?? ''
                    );

                    return $service->listConfigsByAdmin($adminUsername);
                } elseif ($panel->panel_type === 'marzneshin') {
                    $service = new MarzneshinService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password'],
                        $credentials['extra']['node_hostname'] ?? ''
                    );

                    return $service->listConfigsByAdmin($adminUsername);
                }

                return [];
            }
        );
    }

    public function importConfigs(): void
    {
        $this->form->validate();

        $formData = $this->form->getState();
        $resellerId = $formData['reseller_id'];
        $adminUsername = $formData['panel_admin'];
        $selectedConfigUsernames = $formData['remote_configs'];

        $reseller = Reseller::with('panel')->findOrFail($resellerId);
        $panel = $reseller->panel;

        // Validate panel type
        if (! in_array($panel->panel_type, ['marzban', 'marzneshin'])) {
            Notification::make()
                ->title('خطا')
                ->body('این عملیات فقط برای پنل‌های Marzban و Marzneshin پشتیبانی می‌شود')
                ->danger()
                ->send();

            return;
        }

        // Fetch all configs by admin to get full details and validate ownership
        $allConfigs = $this->fetchConfigsByAdmin($panel, $adminUsername);
        $configsToImport = collect($allConfigs)->whereIn('username', $selectedConfigUsernames);

        // Server-side validation: ensure all selected configs belong to the specified admin
        $invalidConfigs = $configsToImport->filter(function ($config) use ($adminUsername) {
            $owner = OwnerExtraction::ownerUsername($config);

            return $owner !== $adminUsername;
        });

        if ($invalidConfigs->isNotEmpty()) {
            $invalidUsernames = $invalidConfigs->pluck('username')->join(', ');
            Notification::make()
                ->title('خطای اعتبارسنجی')
                ->body("کانفیگ‌های زیر متعلق به ادمین انتخاب شده نیستند: {$invalidUsernames}")
                ->danger()
                ->send();

            return;
        }

        // Verify admin exists in the non-sudo admin list
        $admins = $this->fetchPanelAdmins($panel);
        $adminExists = collect($admins)->contains('username', $adminUsername);

        if (! $adminExists) {
            Notification::make()
                ->title('خطا')
                ->body('ادمین انتخاب شده یافت نشد یا دسترسی سوپر دارد')
                ->danger()
                ->send();

            return;
        }

        $imported = 0;
        $skipped = 0;
        $configIds = [];

        DB::transaction(function () use ($reseller, $panel, $adminUsername, $configsToImport, &$imported, &$skipped, &$configIds) {
            foreach ($configsToImport as $remoteConfig) {
                $remoteUserId = $remoteConfig['id'];
                $remoteUsername = $remoteConfig['username'];

                // Check if already exists
                $exists = ResellerConfig::where('panel_id', $panel->id)
                    ->where(function ($query) use ($remoteUserId, $remoteUsername) {
                        $query->where('panel_user_id', $remoteUserId)
                            ->orWhere('external_username', $remoteUsername);
                    })
                    ->exists();

                if ($exists) {
                    $skipped++;

                    continue;
                }

                // Map status
                $status = 'active';
                $disabledAt = null;
                if (isset($remoteConfig['status'])) {
                    $remoteStatus = strtolower($remoteConfig['status']);
                    if (in_array($remoteStatus, ['disabled', 'inactive', 'limited'])) {
                        $status = 'disabled';
                        $disabledAt = now();
                    } elseif (in_array($remoteStatus, ['expired'])) {
                        $status = 'expired';
                    }
                }

                // Build meta with usage tracking fields when available
                $meta = [];
                if (isset($remoteConfig['used_traffic'])) {
                    $meta['used_traffic'] = (int) $remoteConfig['used_traffic'];
                }
                if (isset($remoteConfig['data_used'])) {
                    $meta['data_used'] = (int) $remoteConfig['data_used'];
                }

                // Create ResellerConfig
                $config = ResellerConfig::create([
                    'reseller_id' => $reseller->id,
                    'panel_id' => $panel->id,
                    'panel_type' => $panel->panel_type,
                    'panel_user_id' => $remoteUserId,
                    'external_username' => $remoteUsername,
                    'name_version' => null, // Imported configs are legacy (not generated by V2 system)
                    'status' => $status,
                    // Prefer used_traffic, fallback to data_used (both should be equal for Eylandoo)
                    'usage_bytes' => $remoteConfig['used_traffic'] ?? $remoteConfig['data_used'] ?? 0,
                    'traffic_limit_bytes' => $remoteConfig['data_limit'] ?? 0,
                    'disabled_at' => $disabledAt,
                    'expires_at' => now()->addDays(30), // Default expiry
                    'created_by' => auth()->id(),
                    'meta' => ! empty($meta) ? $meta : null,
                ]);

                $configIds[] = $config->id;

                // Create event with admin metadata
                ResellerConfigEvent::create([
                    'reseller_config_id' => $config->id,
                    'type' => 'imported_from_panel',
                    'meta' => [
                        'panel_id' => $panel->id,
                        'panel_type' => $panel->panel_type,
                        'panel_admin_username' => $adminUsername,
                        'owner_admin' => OwnerExtraction::ownerUsername($remoteConfig),
                    ],
                ]);

                // Create audit log with admin information
                AuditLog::log(
                    action: 'panel_config_attached',
                    targetType: 'reseller_config',
                    targetId: $config->id,
                    reason: 'manual_attach',
                    meta: [
                        'reseller_id' => $reseller->id,
                        'panel_id' => $panel->id,
                        'panel_type' => $panel->panel_type,
                        'selected_admin_username' => $adminUsername,
                        'config_username' => $remoteUsername,
                        'config_id' => $config->id,
                    ]
                );

                $imported++;
            }

            // Create a summary audit log for the entire attachment operation
            if ($imported > 0) {
                AuditLog::log(
                    action: 'panel_config_attached',
                    targetType: 'reseller',
                    targetId: $reseller->id,
                    reason: 'bulk_attach',
                    meta: [
                        'reseller_id' => $reseller->id,
                        'panel_id' => $panel->id,
                        'selected_admin_id' => $adminUsername,
                        'config_ids' => $configIds,
                        'total_attached' => $imported,
                        'total_skipped' => $skipped,
                    ]
                );
            }
        });

        Notification::make()
            ->title('عملیات موفق')
            ->body("تعداد {$imported} کانفیگ وارد شد و {$skipped} کانفیگ تکراری نادیده گرفته شد")
            ->success()
            ->send();

        // Reset form
        $this->form->fill();
    }
}
