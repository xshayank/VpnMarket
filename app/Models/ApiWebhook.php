<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ApiWebhook extends Model
{
    /**
     * Webhook event types
     */
    public const EVENT_CONFIG_CREATED = 'config.created';
    public const EVENT_CONFIG_UPDATED = 'config.updated';
    public const EVENT_CONFIG_DELETED = 'config.deleted';
    public const EVENT_USER_CREATED = 'user.created';
    public const EVENT_USER_UPDATED = 'user.updated';
    public const EVENT_USER_DELETED = 'user.deleted';
    public const EVENT_PANEL_STATUS_CHANGED = 'panel.status_changed';
    public const EVENT_API_KEY_USAGE_SPIKE = 'api_key.usage_spike';
    public const EVENT_API_KEY_ERROR_SPIKE = 'api_key.error_spike';
    public const EVENT_RATE_LIMIT_HIT = 'rate_limit.hit';

    public const ALL_EVENTS = [
        self::EVENT_CONFIG_CREATED,
        self::EVENT_CONFIG_UPDATED,
        self::EVENT_CONFIG_DELETED,
        self::EVENT_USER_CREATED,
        self::EVENT_USER_UPDATED,
        self::EVENT_USER_DELETED,
        self::EVENT_PANEL_STATUS_CHANGED,
        self::EVENT_API_KEY_USAGE_SPIKE,
        self::EVENT_API_KEY_ERROR_SPIKE,
        self::EVENT_RATE_LIMIT_HIT,
    ];

    protected $fillable = [
        'user_id',
        'api_key_id',
        'name',
        'url',
        'secret',
        'events',
        'is_active',
        'failure_count',
        'last_triggered_at',
        'last_success_at',
        'last_failure_at',
        'last_error',
    ];

    protected $casts = [
        'events' => 'array',
        'is_active' => 'boolean',
        'failure_count' => 'integer',
        'last_triggered_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
    ];

    protected $hidden = [
        'secret',
    ];

    /**
     * Generate a new webhook secret
     */
    public static function generateSecret(): string
    {
        return Str::random(64);
    }

    /**
     * Generate HMAC signature for payload
     */
    public function generateSignature(array $payload): string
    {
        return hash_hmac('sha256', json_encode($payload), $this->secret ?? '');
    }

    /**
     * Check if webhook is subscribed to an event
     */
    public function isSubscribedTo(string $event): bool
    {
        return in_array($event, $this->events ?? [], true);
    }

    /**
     * Trigger the webhook for an event
     */
    public function trigger(string $event, array $data): bool
    {
        if (!$this->is_active || !$this->isSubscribedTo($event)) {
            return false;
        }

        $payload = [
            'event' => $event,
            'timestamp' => now()->toIso8601String(),
            'data' => $data,
        ];

        $signature = $this->generateSignature($payload);

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Webhook-Signature' => $signature,
                    'X-Webhook-Event' => $event,
                ])
                ->post($this->url, $payload);

            $this->update([
                'last_triggered_at' => now(),
            ]);

            if ($response->successful()) {
                $this->update([
                    'last_success_at' => now(),
                    'failure_count' => 0,
                    'last_error' => null,
                ]);
                return true;
            }

            $this->recordFailure("HTTP {$response->status()}: {$response->body()}");
            return false;

        } catch (\Exception $e) {
            Log::error('Webhook trigger failed', [
                'webhook_id' => $this->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
            $this->recordFailure($e->getMessage());
            return false;
        }
    }

    /**
     * Record a webhook failure
     */
    protected function recordFailure(string $error): void
    {
        $this->update([
            'last_failure_at' => now(),
            'failure_count' => $this->failure_count + 1,
            'last_error' => Str::limit($error, 500),
        ]);

        // Disable webhook after 10 consecutive failures
        if ($this->failure_count >= 10) {
            $this->update(['is_active' => false]);
            Log::warning('Webhook disabled due to repeated failures', [
                'webhook_id' => $this->id,
                'name' => $this->name,
            ]);
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }
}
