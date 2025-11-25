<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;

class ApiKey extends Model
{
    use HasUuids;

    /**
     * Available API scopes
     */
    public const SCOPE_CONFIGS_CREATE = 'configs:create';
    public const SCOPE_CONFIGS_READ = 'configs:read';
    public const SCOPE_CONFIGS_UPDATE = 'configs:update';
    public const SCOPE_CONFIGS_DELETE = 'configs:delete';
    public const SCOPE_PANELS_LIST = 'panels:list';

    public const ALL_SCOPES = [
        self::SCOPE_CONFIGS_CREATE,
        self::SCOPE_CONFIGS_READ,
        self::SCOPE_CONFIGS_UPDATE,
        self::SCOPE_CONFIGS_DELETE,
        self::SCOPE_PANELS_LIST,
    ];

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'name',
        'key_hash',
        'scopes',
        'ip_whitelist',
        'expires_at',
        'last_used_at',
        'revoked',
    ];

    protected $casts = [
        'scopes' => 'array',
        'ip_whitelist' => 'array',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked' => 'boolean',
    ];

    protected $hidden = [
        'key_hash',
    ];

    /**
     * Generate a new API key string
     */
    public static function generateKeyString(): string
    {
        return 'vpnm_' . bin2hex(random_bytes(32));
    }

    /**
     * Hash the API key for storage
     */
    public static function hashKey(string $key): string
    {
        return hash_hmac('sha256', $key, config('app.key'));
    }

    /**
     * Verify if a key matches this API key
     */
    public function verifyKey(string $key): bool
    {
        return hash_equals($this->key_hash, self::hashKey($key));
    }

    /**
     * Check if the API key has a specific scope
     */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    /**
     * Check if the API key has any of the given scopes
     */
    public function hasAnyScope(array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if ($this->hasScope($scope)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if the API key is valid (not revoked, not expired)
     */
    public function isValid(): bool
    {
        if ($this->revoked) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Check if IP is allowed for this key
     */
    public function isIpAllowed(?string $ip): bool
    {
        // If no whitelist, allow all IPs
        if (empty($this->ip_whitelist)) {
            return true;
        }

        if ($ip === null) {
            return false;
        }

        return in_array($ip, $this->ip_whitelist, true);
    }

    /**
     * Update last used timestamp
     */
    public function touchLastUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }

    /**
     * Revoke this API key
     */
    public function revoke(): bool
    {
        return $this->update(['revoked' => true]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(ApiAuditLog::class);
    }

    public function configs(): HasMany
    {
        return $this->hasMany(ResellerConfig::class, 'created_by_api_key_id');
    }
}
