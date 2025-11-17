<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reseller extends Model
{
    use HasFactory;

    // Reseller type constants
    public const TYPE_PLAN = 'plan';

    public const TYPE_TRAFFIC = 'traffic';

    public const TYPE_WALLET = 'wallet';

    protected $fillable = [
        'user_id',
        'type',
        'status',
        'username_prefix',
        'panel_id',
        'primary_panel_id',
        'config_limit',
        'max_configs',
        'traffic_total_bytes',
        'traffic_used_bytes',
        'admin_forgiven_bytes',
        'window_starts_at',
        'window_ends_at',
        'marzneshin_allowed_service_ids',
        'eylandoo_allowed_node_ids',
        'settings',
        'meta',
        'wallet_balance',
        'wallet_price_per_gb',
    ];

    protected $casts = [
        'config_limit' => 'integer',
        'max_configs' => 'integer',
        'traffic_total_bytes' => 'integer',
        'traffic_used_bytes' => 'integer',
        'admin_forgiven_bytes' => 'integer',
        'window_starts_at' => 'datetime',
        'window_ends_at' => 'datetime',
        'marzneshin_allowed_service_ids' => 'array',
        'eylandoo_allowed_node_ids' => 'array',
        'settings' => 'array',
        'meta' => 'array',
        'wallet_balance' => 'integer',
        'wallet_price_per_gb' => 'integer',
    ];

    /**
     * Normalize an ID array field to integers
     *
     * @param  mixed  $value  The value to normalize
     * @return array|null Array of integers or null
     */
    private function normalizeIdArray($value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true) ?? [];

            return array_map('intval', $decoded);
        }

        if (is_array($value)) {
            return array_map('intval', $value);
        }

        return [];
    }

    /**
     * Normalize eylandoo_allowed_node_ids to array of integers
     */
    protected function eylandooAllowedNodeIds(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn ($value) => $this->normalizeIdArray($value),
            set: fn ($value) => $value === null ? null : (is_array($value) ? json_encode(array_map('intval', $value)) : $value),
        );
    }

    /**
     * Normalize marzneshin_allowed_service_ids to array of integers
     */
    protected function marzneshinAllowedServiceIds(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn ($value) => $this->normalizeIdArray($value),
            set: fn ($value) => $value === null ? null : (is_array($value) ? json_encode(array_map('intval', $value)) : $value),
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Legacy relationship for backward compatibility
     * Maps to primary_panel_id
     * 
     * @deprecated Use primaryPanel() instead
     */
    public function panel(): BelongsTo
    {
        return $this->belongsTo(Panel::class, 'primary_panel_id');
    }

    public function allowedPlans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'reseller_allowed_plans')
            ->withPivot('override_type', 'override_value', 'active')
            ->withTimestamps();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ResellerOrder::class);
    }

    public function configs(): HasMany
    {
        return $this->hasMany(ResellerConfig::class);
    }

    public function usageSnapshots(): HasMany
    {
        return $this->hasMany(ResellerUsageSnapshot::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isPlanBased(): bool
    {
        return $this->type === 'plan';
    }

    public function isTrafficBased(): bool
    {
        return $this->type === self::TYPE_TRAFFIC;
    }

    public function isWalletBased(): bool
    {
        return $this->type === self::TYPE_WALLET;
    }

    /**
     * Alias for backward compatibility during transition
     *
     * @deprecated Use isWalletBased() instead
     */
    public function isWalletType(): bool
    {
        return $this->isWalletBased();
    }

    /**
     * Check if reseller supports config management (CRUD operations)
     * Both traffic-based and wallet-based resellers can manage configs
     * Only plan-based resellers cannot
     *
     * @return bool
     */
    public function supportsConfigManagement(): bool
    {
        return $this->type === self::TYPE_TRAFFIC || $this->type === self::TYPE_WALLET;
    }

    public function getWalletPricePerGb(): int
    {
        // Use per-reseller override if set, otherwise use global default
        return $this->wallet_price_per_gb ?? config('billing.wallet.price_per_gb', 780);
    }

    public function isSuspendedWallet(): bool
    {
        return $this->status === 'suspended_wallet';
    }

    public function isSuspendedTraffic(): bool
    {
        return $this->status === 'suspended_traffic';
    }

    public function isSuspendedOther(): bool
    {
        return $this->status === 'suspended_other';
    }

    public function isDisabled(): bool
    {
        return $this->status === 'disabled';
    }

    /**
     * Check if reseller is suspended for any reason
     */
    public function isAnySuspended(): bool
    {
        return in_array($this->status, [
            'suspended',
            'suspended_wallet',
            'suspended_traffic',
            'suspended_other'
        ], true);
    }

    /**
     * Get the primary panel for this reseller
     */
    public function primaryPanel(): BelongsTo
    {
        return $this->belongsTo(Panel::class, 'primary_panel_id');
    }

    /**
     * Backward-compatible accessor for panel_id
     * Maps to primary_panel_id
     */
    protected function panelId(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn ($value, $attributes) => $attributes['primary_panel_id'] ?? $value,
            set: function ($value) {
                // When panel_id is set, sync both fields
                return [
                    'primary_panel_id' => $value,
                    'panel_id' => $value,
                ];
            }
        );
    }

    /**
     * Check if reseller has a primary panel assigned
     * 
     * @return bool
     */
    public function hasPrimaryPanel(): bool
    {
        return (bool) $this->primary_panel_id;
    }

    /**
     * Get the effective config limit based on reseller type
     */
    public function getEffectiveConfigLimit(): ?int
    {
        // Use max_configs if set, otherwise fall back to type-based defaults
        if ($this->max_configs !== null) {
            return $this->max_configs;
        }

        if ($this->isWalletBased()) {
            return config('billing.reseller.config_limits.wallet', 1000);
        }

        if ($this->isTrafficBased()) {
            return config('billing.reseller.config_limits.traffic'); // null = unlimited
        }

        return $this->config_limit;
    }

    public function hasTrafficRemaining(): bool
    {
        if (! $this->isTrafficBased()) {
            return false;
        }

        return $this->traffic_used_bytes < $this->traffic_total_bytes;
    }

    /**
     * Get current traffic usage (excluding settled usage from resets)
     * This is used for display purposes to show resellers their current cycle usage
     *
     * @return int Current usage in bytes
     */
    public function getCurrentTrafficUsedBytes(): int
    {
        return $this->configs()
            ->get()
            ->sum(function ($config) {
                return $config->usage_bytes;
            });
    }

    public function isWindowValid(): bool
    {
        if (! $this->isTrafficBased()) {
            return false;
        }

        // If window_ends_at is null, treat as unlimited (always valid)
        if (! $this->window_ends_at) {
            return true;
        }

        // Ensure window_starts_at is set
        if (! $this->window_starts_at) {
            return false;
        }

        $now = now();

        // Window is valid while now < window_ends_at (start of day)
        // i.e., a window ending on 2025-11-03 becomes invalid at 2025-11-03 00:00
        return $this->window_starts_at <= $now && $now < $this->window_ends_at->copy()->startOfDay();
    }

    /**
     * Get the base date for extending the window.
     * Returns the later of: current window_ends_at or now()
     */
    public function getExtendWindowBaseDate(): \Illuminate\Support\Carbon
    {
        $now = now();

        return $this->window_ends_at && $this->window_ends_at->gt($now)
            ? $this->window_ends_at
            : $now;
    }

    /**
     * Get a timezone-aware now instance
     */
    private function getAppTimezoneNow(): \Illuminate\Support\Carbon
    {
        return now()->timezone(config('app.timezone', 'Asia/Tehran'));
    }

    /**
     * Convert window_ends_at to app timezone
     */
    private function getWindowEndInAppTimezone(): ?\Illuminate\Support\Carbon
    {
        if (! $this->window_ends_at) {
            return null;
        }

        return $this->window_ends_at->copy()->timezone(config('app.timezone', 'Asia/Tehran'));
    }

    /**
     * Get time remaining in seconds, clamped to 0 when expired
     */
    public function getTimeRemainingSeconds(): int
    {
        $windowEnd = $this->getWindowEndInAppTimezone();
        if (! $windowEnd) {
            return 0;
        }

        $now = $this->getAppTimezoneNow();

        // If window_ends_at is in the past, return 0
        if ($windowEnd->lte($now)) {
            return 0;
        }

        return $now->diffInSeconds($windowEnd);
    }

    /**
     * Get time remaining in days, clamped to 0 when expired
     */
    public function getTimeRemainingDays(): int
    {
        $windowEnd = $this->getWindowEndInAppTimezone();
        if (! $windowEnd) {
            return 0;
        }

        $now = $this->getAppTimezoneNow();

        // If window_ends_at is in the past, return 0
        if ($windowEnd->lte($now)) {
            return 0;
        }

        return $now->diffInDays($windowEnd);
    }
}
