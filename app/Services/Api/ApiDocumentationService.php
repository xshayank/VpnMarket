<?php

namespace App\Services\Api;

use App\Models\ApiKey;
use App\Models\ApiWebhook;

/**
 * Service to generate API documentation and cheat sheets
 */
class ApiDocumentationService
{
    /**
     * Get complete API documentation for a specific style
     */
    public function getDocumentation(string $style = ApiKey::STYLE_FALCO): array
    {
        if ($style === ApiKey::STYLE_MARZNESHIN) {
            return $this->getMarzneshinDocumentation();
        }

        return $this->getFalcoDocumentation();
    }

    /**
     * Get Falco (native) style API documentation
     */
    protected function getFalcoDocumentation(): array
    {
        return [
            'style' => 'Falco',
            'description' => 'VPNMarket\'s native API format. Use this for maximum compatibility with VPNMarket features.',
            'base_url' => '/api/v1',
            'authentication' => [
                'type' => 'Bearer Token',
                'header' => 'Authorization',
                'format' => 'Bearer <api_key>',
                'example' => 'Authorization: Bearer vpnm_abc123...',
            ],
            'endpoints' => $this->getFalcoEndpoints(),
            'error_format' => [
                'description' => 'Errors return JSON with error flag and message',
                'example' => [
                    'error' => true,
                    'message' => 'Error description',
                    'errors' => ['field' => ['Field is required']],
                ],
            ],
            'rate_limiting' => [
                'default_limit' => '60 requests per minute',
                'header' => 'Retry-After',
                'description' => 'When rate limited, response includes Retry-After header',
            ],
            'pagination' => [
                'parameters' => ['page', 'per_page'],
                'default_per_page' => 15,
                'max_per_page' => 100,
            ],
        ];
    }

    /**
     * Get Marzneshin-compatible style API documentation
     */
    protected function getMarzneshinDocumentation(): array
    {
        return [
            'style' => 'Marzneshin',
            'description' => 'Marzneshin-compatible API format. Perfect for drop-in replacement of Marzneshin panels.',
            'base_url' => '/api',
            'authentication' => [
                'type' => 'Token (obtained via /api/admins/token)',
                'methods' => [
                    [
                        'type' => 'Form Auth',
                        'endpoint' => 'POST /api/admins/token',
                        'body' => 'username=<api_key>&password=<api_key>',
                        'description' => 'Use your API key for both username and password',
                    ],
                    [
                        'type' => 'Bearer Token',
                        'header' => 'Authorization',
                        'format' => 'Bearer <api_key>',
                    ],
                ],
                'example' => 'curl -X POST /api/admins/token -d "username=vpnm_abc&password=vpnm_abc"',
            ],
            'endpoints' => $this->getMarzneshinEndpoints(),
            'error_format' => [
                'description' => 'Errors return JSON with detail field (Marzneshin format)',
                'example' => [
                    'detail' => 'Error message',
                ],
                'validation_example' => [
                    'detail' => [
                        ['loc' => ['body', 'username'], 'msg' => 'Username is required', 'type' => 'value_error'],
                    ],
                ],
            ],
            'field_mapping' => $this->getFieldMapping(),
            'panel_compatibility' => $this->getPanelCompatibility(),
        ];
    }

    /**
     * Get Falco-style endpoints documentation
     */
    protected function getFalcoEndpoints(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/api/v1/panels',
                'description' => 'List available panels',
                'scope' => 'panels:list',
                'response_example' => [
                    'data' => [
                        ['id' => 1, 'name' => 'Panel 1', 'panel_type' => 'marzneshin'],
                    ],
                ],
            ],
            [
                'method' => 'GET',
                'path' => '/api/v1/configs',
                'description' => 'List configs (users)',
                'scope' => 'configs:read',
                'parameters' => [
                    'panel_id' => 'Filter by panel ID',
                    'status' => 'Filter by status (active/disabled)',
                    'page' => 'Page number',
                    'per_page' => 'Items per page',
                ],
                'response_example' => [
                    'data' => [
                        [
                            'id' => 1,
                            'name' => 'config_username',
                            'traffic_limit_bytes' => 10737418240,
                            'usage_bytes' => 1073741824,
                            'expires_at' => '2024-12-31T23:59:59Z',
                            'status' => 'active',
                        ],
                    ],
                    'meta' => ['total' => 100, 'page' => 1, 'per_page' => 15],
                ],
            ],
            [
                'method' => 'GET',
                'path' => '/api/v1/configs/{name}',
                'description' => 'Get a specific config',
                'scope' => 'configs:read',
            ],
            [
                'method' => 'POST',
                'path' => '/api/v1/configs',
                'description' => 'Create a new config',
                'scope' => 'configs:create',
                'body' => [
                    'panel_id' => 'required|int',
                    'traffic_limit_gb' => 'required|int',
                    'expires_days' => 'required|int',
                    'comment' => 'optional|string',
                    'service_ids' => 'optional|array (for Marzneshin panels)',
                    'node_ids' => 'optional|array (for Eylandoo panels)',
                ],
            ],
            [
                'method' => 'PUT',
                'path' => '/api/v1/configs/{name}',
                'description' => 'Update a config',
                'scope' => 'configs:update',
            ],
            [
                'method' => 'DELETE',
                'path' => '/api/v1/configs/{name}',
                'description' => 'Delete a config',
                'scope' => 'configs:delete',
            ],
        ];
    }

    /**
     * Get Marzneshin-style endpoints documentation
     */
    protected function getMarzneshinEndpoints(): array
    {
        return [
            [
                'method' => 'POST',
                'path' => '/api/admins/token',
                'description' => 'Authenticate and get access token',
                'auth_required' => false,
                'body' => [
                    'username' => 'Your API key',
                    'password' => 'Your API key (same as username)',
                ],
                'response_example' => [
                    'access_token' => 'vpnm_abc123...',
                    'token_type' => 'bearer',
                ],
            ],
            [
                'method' => 'GET',
                'path' => '/api/services',
                'description' => 'List services (for Eylandoo panels, lists nodes as services)',
                'scope' => 'services:list',
                'response_example' => [
                    'items' => [
                        ['id' => 1, 'name' => 'Service 1'],
                    ],
                    'total' => 1,
                ],
            ],
            [
                'method' => 'GET',
                'path' => '/api/users',
                'description' => 'List users',
                'scope' => 'users:read',
                'parameters' => [
                    'size' => 'Items per page (default 50)',
                    'offset' => 'Offset for pagination',
                    'username' => 'Filter by username',
                    'status' => 'Filter by status',
                ],
                'response_example' => [
                    'items' => [
                        [
                            'username' => 'user123',
                            'status' => 'active',
                            'used_traffic' => 1073741824,
                            'data_limit' => 10737418240,
                            'expire_date' => '2024-12-31T23:59:59Z',
                        ],
                    ],
                    'total' => 100,
                ],
            ],
            [
                'method' => 'GET',
                'path' => '/api/users/{username}',
                'description' => 'Get a specific user',
                'scope' => 'users:read',
            ],
            [
                'method' => 'POST',
                'path' => '/api/users',
                'description' => 'Create a new user',
                'scope' => 'users:create',
                'body' => [
                    'username' => 'required|string',
                    'data_limit' => 'required|int (bytes)',
                    'expire_date' => 'required|datetime (ISO 8601)',
                    'expire_strategy' => 'optional|string (fixed_date|start_on_first_use|never)',
                    'service_ids' => 'optional|array',
                    'note' => 'optional|string',
                ],
            ],
            [
                'method' => 'PUT',
                'path' => '/api/users/{username}',
                'description' => 'Update a user',
                'scope' => 'users:update',
            ],
            [
                'method' => 'DELETE',
                'path' => '/api/users/{username}',
                'description' => 'Delete a user',
                'scope' => 'users:delete',
            ],
            [
                'method' => 'POST',
                'path' => '/api/users/{username}/enable',
                'description' => 'Enable a user',
                'scope' => 'users:update',
            ],
            [
                'method' => 'POST',
                'path' => '/api/users/{username}/disable',
                'description' => 'Disable a user',
                'scope' => 'users:update',
            ],
            [
                'method' => 'POST',
                'path' => '/api/users/{username}/reset',
                'description' => 'Reset user traffic',
                'scope' => 'users:update',
            ],
            [
                'method' => 'GET',
                'path' => '/api/users/{username}/subscription',
                'description' => 'Get user subscription URL',
                'scope' => 'subscription:read',
            ],
            [
                'method' => 'GET',
                'path' => '/api/nodes',
                'description' => 'List nodes (Eylandoo only)',
                'scope' => 'nodes:list',
            ],
        ];
    }

    /**
     * Get field mapping between Eylandoo and Marzneshin
     */
    protected function getFieldMapping(): array
    {
        return [
            'title' => 'Eylandoo ↔ Marzneshin Field Mapping',
            'description' => 'When using Marzneshin-style API with an Eylandoo panel, these mappings apply:',
            'entity_mapping' => [
                'Eylandoo Node' => 'Marzneshin Service',
                'Eylandoo User' => 'Marzneshin User',
            ],
            'field_mappings' => [
                [
                    'eylandoo' => 'node.id',
                    'marzneshin' => 'service.id',
                    'notes' => 'Direct mapping',
                ],
                [
                    'eylandoo' => 'node.name',
                    'marzneshin' => 'service.name',
                    'notes' => 'Direct mapping',
                ],
                [
                    'eylandoo' => 'user.total_traffic_bytes',
                    'marzneshin' => 'user.used_traffic',
                    'notes' => 'Usage in bytes',
                ],
                [
                    'eylandoo' => 'user.data_limit (GB)',
                    'marzneshin' => 'user.data_limit (bytes)',
                    'notes' => 'Converted automatically',
                ],
                [
                    'eylandoo' => 'user.expiry_date_str',
                    'marzneshin' => 'user.expire_date',
                    'notes' => 'ISO 8601 format',
                ],
                [
                    'eylandoo' => 'user.status',
                    'marzneshin' => 'user.status',
                    'notes' => 'active|disabled|limited|expired',
                ],
            ],
            'caveats' => [
                'Eylandoo nodes are exposed as services in Marzneshin-style API',
                'Some Marzneshin service features (inbounds, protocols) are not available for Eylandoo',
                'service_ids in user creation map to node_ids for Eylandoo panels',
            ],
        ];
    }

    /**
     * Get panel compatibility information
     */
    protected function getPanelCompatibility(): array
    {
        return [
            'marzneshin' => [
                'name' => 'Marzneshin',
                'services_endpoint' => 'Full support - returns native services',
                'users_endpoint' => 'Full support',
                'nodes_endpoint' => 'Limited (returns empty)',
                'notes' => 'Native Marzneshin panel, full compatibility',
            ],
            'eylandoo' => [
                'name' => 'Eylandoo',
                'services_endpoint' => 'Returns nodes mapped as services',
                'users_endpoint' => 'Full support',
                'nodes_endpoint' => 'Full support - returns nodes',
                'notes' => 'Nodes are exposed as services for Marzneshin client compatibility',
            ],
        ];
    }

    /**
     * Get available scopes documentation
     */
    public function getScopes(string $style = ApiKey::STYLE_FALCO): array
    {
        $scopes = ApiKey::getScopesForStyle($style);

        return array_map(fn ($scope) => [
            'name' => $scope,
            'description' => $this->getScopeDescription($scope),
        ], $scopes);
    }

    /**
     * Get description for a scope
     */
    protected function getScopeDescription(string $scope): string
    {
        return match ($scope) {
            'configs:create' => 'Create new configs/users',
            'configs:read' => 'Read configs/users',
            'configs:update' => 'Update configs/users',
            'configs:delete' => 'Delete configs/users',
            'panels:list' => 'List available panels',
            'services:list' => 'List services (Marzneshin-style)',
            'users:create' => 'Create users (Marzneshin-style)',
            'users:read' => 'Read users (Marzneshin-style)',
            'users:update' => 'Update users (Marzneshin-style)',
            'users:delete' => 'Delete users (Marzneshin-style)',
            'subscription:read' => 'Read subscription URLs',
            'nodes:list' => 'List nodes (Eylandoo)',
            'webhooks:manage' => 'Manage webhooks',
            default => 'No description available',
        };
    }

    /**
     * Get webhook events documentation
     */
    public function getWebhookEvents(): array
    {
        return array_map(fn ($event) => [
            'name' => $event,
            'description' => $this->getWebhookEventDescription($event),
        ], ApiWebhook::ALL_EVENTS);
    }

    /**
     * Get description for a webhook event
     */
    protected function getWebhookEventDescription(string $event): string
    {
        return match ($event) {
            ApiWebhook::EVENT_CONFIG_CREATED => 'Triggered when a new config/user is created',
            ApiWebhook::EVENT_CONFIG_UPDATED => 'Triggered when a config/user is updated',
            ApiWebhook::EVENT_CONFIG_DELETED => 'Triggered when a config/user is deleted',
            ApiWebhook::EVENT_USER_CREATED => 'Triggered when a user is created via API',
            ApiWebhook::EVENT_USER_UPDATED => 'Triggered when a user is updated via API',
            ApiWebhook::EVENT_USER_DELETED => 'Triggered when a user is deleted via API',
            ApiWebhook::EVENT_PANEL_STATUS_CHANGED => 'Triggered when a panel\'s status changes',
            ApiWebhook::EVENT_API_KEY_USAGE_SPIKE => 'Triggered when API key usage spikes abnormally',
            ApiWebhook::EVENT_API_KEY_ERROR_SPIKE => 'Triggered when API key errors spike abnormally',
            ApiWebhook::EVENT_RATE_LIMIT_HIT => 'Triggered when rate limit is exceeded',
            default => 'No description available',
        };
    }

    /**
     * Export documentation as OpenAPI 3.0 spec
     */
    public function exportOpenApiSpec(string $style = ApiKey::STYLE_FALCO): array
    {
        $baseUrl = $style === ApiKey::STYLE_MARZNESHIN ? '/api' : '/api/v1';
        $title = $style === ApiKey::STYLE_MARZNESHIN
            ? 'VPNMarket Marzneshin-Compatible API'
            : 'VPNMarket Falco API';

        return [
            'openapi' => '3.0.0',
            'info' => [
                'title' => $title,
                'version' => '1.0.0',
                'description' => $this->getDocumentation($style)['description'],
            ],
            'servers' => [
                ['url' => config('app.url').$baseUrl],
            ],
            'security' => [
                ['bearerAuth' => []],
            ],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                    ],
                ],
            ],
            'paths' => $this->getOpenApiPaths($style),
        ];
    }

    /**
     * Get OpenAPI paths for a style
     */
    protected function getOpenApiPaths(string $style): array
    {
        $endpoints = $style === ApiKey::STYLE_MARZNESHIN
            ? $this->getMarzneshinEndpoints()
            : $this->getFalcoEndpoints();

        $paths = [];
        foreach ($endpoints as $endpoint) {
            $path = $endpoint['path'];
            $method = strtolower($endpoint['method']);

            if (! isset($paths[$path])) {
                $paths[$path] = [];
            }

            $paths[$path][$method] = [
                'summary' => $endpoint['description'],
                'responses' => [
                    '200' => ['description' => 'Successful response'],
                    '401' => ['description' => 'Unauthorized'],
                    '403' => ['description' => 'Forbidden'],
                    '429' => ['description' => 'Rate limited'],
                ],
            ];

            if (isset($endpoint['scope'])) {
                $paths[$path][$method]['security'] = [['bearerAuth' => []]];
            }
        }

        return $paths;
    }

    /**
     * Generate markdown documentation
     */
    public function exportMarkdown(string $style = ApiKey::STYLE_FALCO): string
    {
        $doc = $this->getDocumentation($style);
        $md = "# {$doc['style']} API Documentation\n\n";
        $md .= "{$doc['description']}\n\n";

        $md .= "## Base URL\n\n";
        $md .= "`{$doc['base_url']}`\n\n";

        $md .= "## Authentication\n\n";
        $md .= "Type: {$doc['authentication']['type']}\n\n";
        if (isset($doc['authentication']['example'])) {
            $md .= "Example: `{$doc['authentication']['example']}`\n\n";
        }

        $md .= "## Endpoints\n\n";
        foreach ($doc['endpoints'] as $endpoint) {
            $md .= "### {$endpoint['method']} {$endpoint['path']}\n\n";
            $md .= "{$endpoint['description']}\n\n";
            if (isset($endpoint['scope'])) {
                $md .= "**Required Scope:** `{$endpoint['scope']}`\n\n";
            }
        }

        if (isset($doc['field_mapping'])) {
            $md .= "## Field Mapping\n\n";
            $md .= "{$doc['field_mapping']['description']}\n\n";
            $md .= "| Eylandoo | Marzneshin | Notes |\n";
            $md .= "|----------|------------|-------|\n";
            foreach ($doc['field_mapping']['field_mappings'] as $mapping) {
                $md .= "| {$mapping['eylandoo']} | {$mapping['marzneshin']} | {$mapping['notes']} |\n";
            }
        }

        return $md;
    }
}
