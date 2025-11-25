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
        'target_type',
        'target_id_or_name',
        'request_metadata',
    ];

    protected $casts = [
        'request_metadata' => 'array',
    ];

    /**
     * Log an API action
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }
}
