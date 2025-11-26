<?php

namespace App\Services\Api;

use App\Models\ApiKey;
use App\Models\Panel;
use App\Models\ResellerConfig;

/**
 * Maps API responses to different styles (Falco native vs Marzneshin compatible)
 */
class ApiResponseMapper
{
    protected string $style;
    protected ?Panel $panel;

    public function __construct(string $style = ApiKey::STYLE_FALCO, ?Panel $panel = null)
    {
        $this->style = $style;
        $this->panel = $panel;
    }

    /**
     * Map a config/user object to the appropriate style
     */
    public function mapConfig(ResellerConfig $config): array
    {
        if ($this->style === ApiKey::STYLE_MARZNESHIN) {
            return $this->mapConfigToMarzneshin($config);
        }

        return $this->mapConfigToFalco($config);
    }

    /**
     * Map config to Falco (native) style
     */
    protected function mapConfigToFalco(ResellerConfig $config): array
    {
        return [
            'id' => $config->id,
            'name' => $config->external_username,
            'comment' => $config->comment,
            'traffic_limit_bytes' => $config->traffic_limit_bytes,
            'traffic_limit_gb' => round($config->traffic_limit_bytes / (1024 * 1024 * 1024), 2),
            'usage_bytes' => $config->usage_bytes,
            'usage_gb' => round($config->usage_bytes / (1024 * 1024 * 1024), 2),
            'expires_at' => $config->expires_at?->toIso8601String(),
            'status' => $config->status,
            'panel_id' => $config->panel_id,
            'panel_type' => $config->panel_type,
            'subscription_url' => $config->subscription_url,
            'connections' => $config->connections,
            'created_at' => $config->created_at->toIso8601String(),
            'updated_at' => $config->updated_at->toIso8601String(),
        ];
    }

    /**
     * Map config to Marzneshin-compatible format
     * 
     * Marzneshin user format:
     * {
     *   "username": "string",
     *   "status": "active|disabled|limited|expired",
     *   "used_traffic": 0,
     *   "data_limit": 0,
     *   "data_limit_reset_strategy": "no_reset|day|week|month|year",
     *   "expire_date": "2024-01-01T00:00:00Z",
     *   "expire_strategy": "fixed_date|start_on_first_use|never",
     *   "lifetime_used_traffic": 0,
     *   "sub_updated_at": "2024-01-01T00:00:00Z",
     *   "sub_last_user_agent": "string",
     *   "subscription_url": "string",
     *   "service_ids": [1, 2],
     *   "note": "string",
     *   "created_at": "2024-01-01T00:00:00Z"
     * }
     */
    protected function mapConfigToMarzneshin(ResellerConfig $config): array
    {
        // Map our status to Marzneshin status
        $status = $this->mapStatusToMarzneshin($config->status, $config);

        return [
            'username' => $config->external_username,
            'status' => $status,
            'used_traffic' => $config->usage_bytes,
            'data_limit' => $config->traffic_limit_bytes,
            'data_limit_reset_strategy' => 'no_reset',
            'expire_date' => $config->expires_at?->toIso8601String(),
            'expire_strategy' => 'fixed_date',
            'lifetime_used_traffic' => $config->usage_bytes,
            'sub_updated_at' => $config->updated_at->toIso8601String(),
            'sub_last_user_agent' => null,
            'subscription_url' => $config->subscription_url,
            'service_ids' => $this->extractServiceIds($config),
            'note' => $config->comment,
            'created_at' => $config->created_at->toIso8601String(),
            // Additional fields for convenience
            'id' => $config->id,
        ];
    }

    /**
     * Map our status to Marzneshin status format
     */
    protected function mapStatusToMarzneshin(string $status, ResellerConfig $config): string
    {
        // Check if traffic limit exceeded
        if ($config->traffic_limit_bytes > 0 && $config->usage_bytes >= $config->traffic_limit_bytes) {
            return 'limited';
        }

        // Check if expired
        if ($config->expires_at && $config->expires_at->isPast()) {
            return 'expired';
        }

        return match($status) {
            'active' => 'active',
            'disabled', 'suspended' => 'disabled',
            'deleted' => 'disabled',
            default => 'active',
        };
    }

    /**
     * Extract service IDs from config meta
     */
    protected function extractServiceIds(ResellerConfig $config): array
    {
        $meta = $config->meta ?? [];
        return $meta['service_ids'] ?? [];
    }

    /**
     * Map a list of configs to the appropriate style
     */
    public function mapConfigList(iterable $configs, array $paginationMeta = []): array
    {
        $items = [];
        foreach ($configs as $config) {
            $items[] = $this->mapConfig($config);
        }

        if ($this->style === ApiKey::STYLE_MARZNESHIN) {
            // Marzneshin returns items array directly with total
            return [
                'items' => $items,
                'total' => $paginationMeta['total'] ?? count($items),
            ];
        }

        // Falco style with full pagination meta
        return [
            'data' => $items,
            'meta' => $paginationMeta,
        ];
    }

    /**
     * Map an Eylandoo node to Marzneshin service format
     * 
     * Eylandoo node: {"id": 1, "name": "Node Name"}
     * Marzneshin service: {"id": 1, "name": "Service Name"}
     */
    public function mapNodeToService(array $node): array
    {
        return [
            'id' => $node['id'] ?? 0,
            'name' => $node['name'] ?? 'Unknown Node',
        ];
    }

    /**
     * Map Eylandoo nodes to Marzneshin services
     */
    public function mapNodesToServices(array $nodes): array
    {
        $services = [];
        foreach ($nodes as $node) {
            $services[] = $this->mapNodeToService($node);
        }

        return [
            'items' => $services,
            'total' => count($services),
        ];
    }

    /**
     * Map a panel to API response format
     */
    public function mapPanel(Panel $panel): array
    {
        if ($this->style === ApiKey::STYLE_MARZNESHIN) {
            return [
                'id' => $panel->id,
                'name' => $panel->name,
                'type' => $this->mapPanelTypeToMarzneshin($panel->panel_type),
            ];
        }

        return [
            'id' => $panel->id,
            'name' => $panel->name,
            'panel_type' => $panel->panel_type,
            'is_active' => $panel->is_active,
        ];
    }

    /**
     * Map panel type to Marzneshin-compatible type
     */
    protected function mapPanelTypeToMarzneshin(string $panelType): string
    {
        return match(strtolower($panelType)) {
            'marzneshin' => 'marzneshin',
            'eylandoo' => 'marzneshin', // Map Eylandoo as Marzneshin-compatible
            default => 'custom',
        };
    }

    /**
     * Map error response to appropriate style
     */
    public function mapError(string $message, int $status, array $details = []): array
    {
        if ($this->style === ApiKey::STYLE_MARZNESHIN) {
            return $this->mapErrorToMarzneshin($message, $status, $details);
        }

        return $this->mapErrorToFalco($message, $status, $details);
    }

    /**
     * Map error to Falco (native) format
     */
    protected function mapErrorToFalco(string $message, int $status, array $details): array
    {
        $error = [
            'error' => true,
            'message' => $message,
        ];

        if (!empty($details)) {
            $error['errors'] = $details;
        }

        return $error;
    }

    /**
     * Map error to Marzneshin format
     * 
     * Marzneshin error format:
     * {"detail": "Error message"} for simple errors
     * {"detail": [{"loc": ["body", "field"], "msg": "message", "type": "error_type"}]} for validation
     */
    protected function mapErrorToMarzneshin(string $message, int $status, array $details): array
    {
        if (!empty($details)) {
            // Validation error format
            $formattedDetails = [];
            foreach ($details as $field => $messages) {
                $msgs = is_array($messages) ? $messages : [$messages];
                foreach ($msgs as $msg) {
                    $formattedDetails[] = [
                        'loc' => ['body', $field],
                        'msg' => $msg,
                        'type' => 'value_error',
                    ];
                }
            }
            return ['detail' => $formattedDetails];
        }

        return ['detail' => $message];
    }

    /**
     * Get the mapping table for Eylandoo nodes to Marzneshin services
     * This documents the field mappings for reference
     */
    public static function getNodeToServiceMappingTable(): array
    {
        return [
            'mapping' => [
                'Eylandoo' => [
                    'entity' => 'Node',
                    'fields' => [
                        'id' => 'Node ID (integer)',
                        'name' => 'Node name/hostname',
                        'host' => 'Node host address',
                        'status' => 'Node status (active/inactive)',
                    ],
                ],
                'Marzneshin' => [
                    'entity' => 'Service',
                    'fields' => [
                        'id' => 'Service ID (integer)',
                        'name' => 'Service name',
                        'inbounds' => 'List of inbound connections',
                    ],
                ],
            ],
            'field_mapping' => [
                'id' => 'id (direct)',
                'name' => 'name (direct)',
                'host' => 'Not mapped (Marzneshin uses inbounds)',
                'status' => 'Not mapped (Marzneshin services are always active)',
            ],
            'caveats' => [
                'Eylandoo nodes represent physical servers, Marzneshin services are logical groupings',
                'When using Eylandoo panel with Marzneshin API style, nodes are exposed as services',
                'Some Marzneshin service features (inbounds, protocols) are not available for Eylandoo',
            ],
        ];
    }

    /**
     * Get current style
     */
    public function getStyle(): string
    {
        return $this->style;
    }

    /**
     * Set style
     */
    public function setStyle(string $style): self
    {
        $this->style = $style;
        return $this;
    }

    /**
     * Set panel
     */
    public function setPanel(?Panel $panel): self
    {
        $this->panel = $panel;
        return $this;
    }
}
