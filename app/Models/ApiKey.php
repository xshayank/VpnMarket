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
     * API Style constants
     */
    public const STYLE_FALCO = 'falco';
    public const STYLE_MARZNESHIN = 'marzneshin';

    public const ALL_STYLES = [
        self::STYLE_FALCO,
        self::STYLE_MARZNESHIN,
    ];

    /**
     * Available API scopes
     */
    public const SCOPE_CONFIGS_CREATE = 'configs:create';
    public const SCOPE_CONFIGS_READ = 'configs:read';
    public const SCOPE_CONFIGS_UPDATE = 'configs:update';
    public const SCOPE_CONFIGS_DELETE = 'configs:delete';
    public const SCOPE_PANELS_LIST = 'panels:list';
    public const SCOPE_SERVICES_LIST = 'services:list';
    public const SCOPE_USERS_CREATE = 'users:create';
    public const SCOPE_USERS_READ = 'users:read';
    public const SCOPE_USERS_UPDATE = 'users:update';
    public const SCOPE_USERS_DELETE = 'users:delete';
    public const SCOPE_SUBSCRIPTION_READ = 'subscription:read';
    public const SCOPE_NODES_LIST = 'nodes:list';
    public const SCOPE_WEBHOOKS_MANAGE = 'webhooks:manage';

    public const ALL_SCOPES = [
        self::SCOPE_CONFIGS_CREATE,
        self::SCOPE_CONFIGS_READ,
        self::SCOPE_CONFIGS_UPDATE,
        self::SCOPE_CONFIGS_DELETE,
        self::SCOPE_PANELS_LIST,
        self::SCOPE_SERVICES_LIST,
        self::SCOPE_USERS_CREATE,
        self::SCOPE_USERS_READ,
        self::SCOPE_USERS_UPDATE,
        self::SCOPE_USERS_DELETE,
        self::SCOPE_SUBSCRIPTION_READ,
        self::SCOPE_NODES_LIST,
        self::SCOPE_WEBHOOKS_MANAGE,
    ];

    /**
     * Marzneshin-compatible scopes (maps to internal scopes)
     */
    public const MARZNESHIN_SCOPES = [
        'services:list' => self::SCOPE_SERVICES_LIST,
        'users:create' => self::SCOPE_USERS_CREATE,
        'users:read' => self::SCOPE_USERS_READ,
        'users:update' => self::SCOPE_USERS_UPDATE,
        'users:delete' => self::SCOPE_USERS_DELETE,
        'subscription:read' => self::SCOPE_SUBSCRIPTION_READ,
        'nodes:list' => self::SCOPE_NODES_LIST,
    ];

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'name',
        'key_hash',
        'scopes',
        'api_style',
        'default_panel_id',
        'rate_limit_per_minute',
        'requests_this_minute',
        'rate_limit_reset_at',
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
        'rate_limit_reset_at' => 'datetime',
        'revoked' => 'boolean',
        'rate_limit_per_minute' => 'integer',
        'requests_this_minute' => 'integer',
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

    /**
     * Get the default panel for this API key
     */
    public function defaultPanel(): BelongsTo
    {
        return $this->belongsTo(Panel::class, 'default_panel_id');
    }

    /**
     * Get associated webhooks
     */
    public function webhooks(): HasMany
    {
        return $this->hasMany(ApiWebhook::class);
    }

    /**
     * Check if this is a Marzneshin-style API key
     */
    public function isMarzneshinStyle(): bool
    {
        return $this->api_style === self::STYLE_MARZNESHIN;
    }

    /**
     * Check if this is a Falco-style (native) API key
     */
    public function isFalcoStyle(): bool
    {
        return $this->api_style === self::STYLE_FALCO;
    }

    /**
     * Check if rate limit has been exceeded
     */
    public function isRateLimited(): bool
    {
        // Reset counter if the minute has passed
        if ($this->rate_limit_reset_at && $this->rate_limit_reset_at->isPast()) {
            return false;
        }

        return $this->requests_this_minute >= $this->rate_limit_per_minute;
    }

    /**
     * Increment the request counter for rate limiting
     */
    public function incrementRequestCount(): void
    {
        // Reset counter if the minute has passed
        if (!$this->rate_limit_reset_at || $this->rate_limit_reset_at->isPast()) {
            $this->update([
                'requests_this_minute' => 1,
                'rate_limit_reset_at' => now()->addMinute(),
            ]);
            return;
        }

        $this->increment('requests_this_minute');
    }

    /**
     * Get remaining requests for this minute
     */
    public function getRemainingRequests(): int
    {
        if (!$this->rate_limit_reset_at || $this->rate_limit_reset_at->isPast()) {
            return $this->rate_limit_per_minute;
        }

        return max(0, $this->rate_limit_per_minute - $this->requests_this_minute);
    }

    /**
     * Get the style label for display
     */
    public function getStyleLabelAttribute(): string
    {
        return match($this->api_style) {
            self::STYLE_MARZNESHIN => 'Marzneshin',
            self::STYLE_FALCO => 'Falco (Native)',
            default => ucfirst($this->api_style ?? 'Unknown'),
        };
    }

    /**
     * Get style-specific scopes
     * 
     * Note: For Marzneshin style, returns the Marzneshin scope names.
     * For Falco style, returns all available scopes.
     * Both return an array of scope strings.
     * 
     * @param string $style The API style (falco or marzneshin)
     * @return array Array of scope name strings
     */
    public static function getScopesForStyle(string $style): array
    {
        if ($style === self::STYLE_MARZNESHIN) {
            // Return Marzneshin-compatible scope names
            return array_keys(self::MARZNESHIN_SCOPES);
        }

        // Return all available scopes for Falco style
        return self::ALL_SCOPES;
    }

    /**
     * Default rate limit per minute
     */
    public const DEFAULT_RATE_LIMIT_PER_MINUTE = 60;
}
