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
}
