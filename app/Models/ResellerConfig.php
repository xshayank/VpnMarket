<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ResellerConfig extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reseller_id',
        'external_username',
        'username_prefix',
        'panel_username',
        'name_version',
        'comment',
        'prefix',
        'custom_name',
        'traffic_limit_bytes',
        'connections',
        'usage_bytes',
        'expires_at',
        'status',
        'panel_type',
        'panel_user_id',
        'subscription_url',
        'panel_id',
        'created_by',
        'disabled_at',
        'meta',
        'created_by_api_key_id',
    ];

    protected $casts = [
        'traffic_limit_bytes' => 'integer',
        'connections' => 'integer',
        'usage_bytes' => 'integer',
        'name_version' => 'integer',
        'expires_at' => 'datetime',
        'disabled_at' => 'datetime',
        'meta' => 'array',
    ];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function panel(): BelongsTo
    {
        return $this->belongsTo(Panel::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function createdByApiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class, 'created_by_api_key_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ResellerConfigEvent::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isDisabled(): bool
    {
        return $this->status === 'disabled';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired';
    }

    public function isDeleted(): bool
    {
        return $this->status === 'deleted';
    }

    public function hasTrafficRemaining(): bool
    {
        return $this->usage_bytes < $this->traffic_limit_bytes;
    }

    public function isExpiredByTime(): bool
    {
        // Config expires when now >= expires_at (start of day)
        // i.e., a config expiring on 2025-11-03 is expired at 2025-11-03 00:00
        return $this->expires_at && now() >= $this->expires_at->copy()->startOfDay();
    }

    public function getSettledUsageBytes(): int
    {
        return (int) data_get($this->meta, 'settled_usage_bytes', 0);
    }

    public function getTotalUsageBytes(): int
    {
        return $this->usage_bytes + $this->getSettledUsageBytes();
    }

    public function getLastResetAt(): ?string
    {
        return data_get($this->meta, 'last_reset_at');
    }

    public function canResetUsage(): bool
    {
        $lastResetAt = $this->getLastResetAt();
        if (!$lastResetAt) {
            return true;
        }

        try {
            $lastReset = \Carbon\Carbon::parse($lastResetAt);
            return $lastReset->diffInHours(now()) >= 24;
        } catch (\Exception $e) {
            return true; // If parsing fails, allow reset
        }
    }

    /**
     * Get the display username (the original prefix requested by user)
     * This is what should be shown to end users, not the full panel username
     *
     * @return string
     */
    public function getDisplayUsernameAttribute(): string
    {
        // Priority: username_prefix > extracted from external_username > external_username
        if ($this->username_prefix !== null && $this->username_prefix !== '') {
            return $this->username_prefix;
        }

        // Fallback: extract prefix from external_username if available
        if ($this->external_username !== null && $this->external_username !== '') {
            // Extract everything before the last underscore
            $lastUnderscorePos = strrpos($this->external_username, '_');
            if ($lastUnderscorePos !== false) {
                return substr($this->external_username, 0, $lastUnderscorePos);
            }
            return $this->external_username;
        }

        return '';
    }

    /**
     * Get the actual panel username that was sent to the VPN panel
     *
     * @return string
     */
    public function getPanelUsernameAttribute(): string
    {
        // Priority: panel_username column > external_username > panel_user_id
        $panelUsername = $this->attributes['panel_username'] ?? null;
        if ($panelUsername !== null && $panelUsername !== '') {
            return $panelUsername;
        }

        if ($this->external_username !== null && $this->external_username !== '') {
            return $this->external_username;
        }

        return $this->panel_user_id ?? '';
    }

    /**
     * Get the effective username that should be used for panel API calls
     * This ensures we always use the correct username for interacting with panels
     *
     * @return string
     */
    public function getEffectivePanelUsername(): string
    {
        return $this->panel_username;
    }

    /**
     * Scope: Find configs by username prefix
     * Searches for configs where the username_prefix matches or starts with the given value
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $prefix
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByUsernamePrefix($query, string $prefix)
    {
        return $query->where('username_prefix', $prefix)
            ->orWhere('username_prefix', 'LIKE', $prefix . '%');
    }

    /**
     * Scope: Find configs by exact panel username
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $panelUsername
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByPanelUsername($query, string $panelUsername)
    {
        return $query->where(function ($q) use ($panelUsername) {
            $q->where('panel_username', $panelUsername)
                ->orWhere('external_username', $panelUsername);
        });
    }
}
