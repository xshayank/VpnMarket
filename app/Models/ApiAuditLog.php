<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiAuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'api_key_id',
        'action',
        'api_style',
        'endpoint',
        'http_method',
        'response_status',
        'response_time_ms',
        'rate_limited',
        'error_details',
        'target_type',
        'target_id_or_name',
        'request_metadata',
    ];

    protected $casts = [
        'request_metadata' => 'array',
        'error_details' => 'array',
        'rate_limited' => 'boolean',
        'response_status' => 'integer',
        'response_time_ms' => 'integer',
    ];

    /**
     * Log an API action with enhanced analytics
     */
    public static function logAction(
        int $userId,
        ?string $apiKeyId,
        string $action,
        ?string $targetType = null,
        ?string $targetIdOrName = null,
        array $metadata = []
    ): self {
        $request = request();
        
        $requestMetadata = array_merge([
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ], $metadata);

        return self::create([
            'user_id' => $userId,
            'api_key_id' => $apiKeyId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id_or_name' => $targetIdOrName,
            'request_metadata' => $requestMetadata,
        ]);
    }

    /**
     * Log an API request with full analytics
     */
    public static function logRequest(
        int $userId,
        ?string $apiKeyId,
        string $action,
        array $options = []
    ): self {
        $request = request();
        
        $requestMetadata = array_merge([
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ], $options['metadata'] ?? []);

        return self::create([
            'user_id' => $userId,
            'api_key_id' => $apiKeyId,
            'action' => $action,
            'api_style' => $options['api_style'] ?? null,
            'endpoint' => $options['endpoint'] ?? $request?->path(),
            'http_method' => $options['http_method'] ?? $request?->method(),
            'response_status' => $options['response_status'] ?? null,
            'response_time_ms' => $options['response_time_ms'] ?? null,
            'rate_limited' => $options['rate_limited'] ?? false,
            'error_details' => $options['error_details'] ?? null,
            'target_type' => $options['target_type'] ?? null,
            'target_id_or_name' => $options['target_id_or_name'] ?? null,
            'request_metadata' => $requestMetadata,
        ]);
    }

    /**
     * Get usage analytics for a specific API key
     */
    public static function getKeyAnalytics(string $apiKeyId, int $hours = 24): array
    {
        $since = now()->subHours($hours);

        $logs = self::where('api_key_id', $apiKeyId)
            ->where('created_at', '>=', $since)
            ->get();

        return [
            'total_requests' => $logs->count(),
            'successful_requests' => $logs->where('response_status', '>=', 200)->where('response_status', '<', 300)->count(),
            'error_requests' => $logs->where('response_status', '>=', 400)->count(),
            'rate_limited_requests' => $logs->where('rate_limited', true)->count(),
            'avg_response_time_ms' => $logs->whereNotNull('response_time_ms')->avg('response_time_ms'),
            'endpoints' => $logs->groupBy('endpoint')->map->count()->toArray(),
            'period_hours' => $hours,
        ];
    }

    /**
     * Get popular endpoints for analytics
     */
    public static function getPopularEndpoints(int $hours = 24, int $limit = 10): array
    {
        $since = now()->subHours($hours);

        return self::where('created_at', '>=', $since)
            ->whereNotNull('endpoint')
            ->selectRaw('endpoint, COUNT(*) as count')
            ->groupBy('endpoint')
            ->orderByDesc('count')
            ->limit($limit)
            ->pluck('count', 'endpoint')
            ->toArray();
    }

    /**
     * Get error rate by API style
     */
    public static function getErrorRateByStyle(int $hours = 24): array
    {
        $since = now()->subHours($hours);

        $logs = self::where('created_at', '>=', $since)
            ->whereNotNull('api_style')
            ->get();

        $result = [];
        foreach ($logs->groupBy('api_style') as $style => $styleLogs) {
            $total = $styleLogs->count();
            $errors = $styleLogs->where('response_status', '>=', 400)->count();
            $result[$style] = [
                'total' => $total,
                'errors' => $errors,
                'error_rate' => $total > 0 ? round(($errors / $total) * 100, 2) : 0,
            ];
        }

        return $result;
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
