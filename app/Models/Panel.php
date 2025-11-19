<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Panel extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'url',
        'panel_type',
        'username',
        'password',
        'api_token',
        'extra',
        'is_active',
        'auto_assign_to_resellers',
        'registration_default_node_ids',
        'registration_default_service_ids',
    ];

    protected $casts = [
        'extra' => 'array',
        'is_active' => 'boolean',
        'auto_assign_to_resellers' => 'boolean',
        'registration_default_node_ids' => 'array',
        'registration_default_service_ids' => 'array',
    ];

    protected $hidden = [
        'password',
        'api_token',
    ];

    /**
     * Encrypt password before saving
     */
    protected function password(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? Crypt::decryptString($value) : null,
            set: fn ($value) => $value ? Crypt::encryptString($value) : null,
        );
    }

    /**
     * Encrypt API token before saving
     */
    protected function apiToken(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? Crypt::decryptString($value) : null,
            set: fn ($value) => $value ? Crypt::encryptString($value) : null,
        );
    }

    public function plans()
    {
        return $this->hasMany(Plan::class);
    }

    /**
     * Scope to get panels with auto-assign enabled
     */
    public function scopeAutoAssign($query)
    {
        return $query->where('auto_assign_to_resellers', true);
    }

    /**
     * Get panel credentials for API usage
     */
    public function getCredentials(): array
    {
        return [
            'url' => $this->url,
            'username' => $this->username,
            'password' => $this->password,
            'api_token' => $this->api_token,
            'extra' => $this->extra ?? [],
        ];
    }

    /**
     * Get Eylandoo nodes with caching (5 minutes)
     *
     * @return array Array of nodes with id and name
     */
    public function getCachedEylandooNodes(): array
    {
        if (strtolower(trim($this->panel_type ?? '')) !== 'eylandoo') {
            return [];
        }

        $cacheKey = "panel:{$this->id}:eylandoo_nodes";
        
        return \Illuminate\Support\Facades\Cache::remember($cacheKey, 300, function () {
            try {
                $credentials = $this->getCredentials();
                
                // Validate credentials
                if (empty($credentials['url']) || empty($credentials['api_token'])) {
                    \Illuminate\Support\Facades\Log::warning("Eylandoo nodes fetch: Missing credentials for panel {$this->id}", [
                        'panel_id' => $this->id,
                        'panel_name' => $this->name,
                        'has_url' => !empty($credentials['url']),
                        'has_api_token' => !empty($credentials['api_token']),
                    ]);
                    return [];
                }
                
                // Validate URL format
                $baseUrl = trim($credentials['url']);
                if (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
                    \Illuminate\Support\Facades\Log::warning("Eylandoo nodes fetch: Invalid URL for panel {$this->id}", [
                        'panel_id' => $this->id,
                        'panel_name' => $this->name,
                    ]);
                    return [];
                }
                
                $service = new \App\Services\EylandooService(
                    $credentials['url'],
                    $credentials['api_token'],
                    $credentials['extra']['node_hostname'] ?? ''
                );
                
                $nodes = $service->listNodes();
                
                // Ensure we return an array
                if (!is_array($nodes)) {
                    \Illuminate\Support\Facades\Log::warning("Eylandoo nodes fetch: API returned non-array for panel {$this->id}", [
                        'panel_id' => $this->id,
                        'panel_name' => $this->name,
                        'response_type' => gettype($nodes),
                    ]);
                    return [];
                }
                
                if (empty($nodes)) {
                    \Illuminate\Support\Facades\Log::info("Eylandoo nodes fetch: API returned no nodes for panel {$this->id}", [
                        'panel_id' => $this->id,
                        'panel_name' => $this->name,
                        'url' => $credentials['url'],
                    ]);
                } else {
                    \Illuminate\Support\Facades\Log::debug("Eylandoo nodes fetch: Successfully retrieved " . count($nodes) . " nodes for panel {$this->id}");
                }
                
                return $nodes;
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Failed to fetch Eylandoo nodes for panel {$this->id}: " . $e->getMessage(), [
                    'panel_id' => $this->id,
                    'panel_name' => $this->name,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);
                return [];
            }
        });
    }

    /**
     * Get registration default node IDs for Eylandoo panels
     *
     * @return array
     */
    public function getRegistrationDefaultNodeIds(): array
    {
        if (strtolower(trim($this->panel_type ?? '')) !== 'eylandoo') {
            return [];
        }

        return $this->registration_default_node_ids ?? [];
    }

    /**
     * Get Marzneshin services with caching (5 minutes)
     *
     * @return array Array of services with id and name
     */
    public function getCachedMarzneshinServices(): array
    {
        if (strtolower(trim($this->panel_type ?? '')) !== 'marzneshin') {
            return [];
        }

        $cacheKey = "panel:{$this->id}:marzneshin_services";
        
        return \Illuminate\Support\Facades\Cache::remember($cacheKey, 300, function () {
            try {
                $credentials = $this->getCredentials();
                
                // Validate credentials
                if (empty($credentials['url']) || empty($credentials['username']) || empty($credentials['password'])) {
                    \Illuminate\Support\Facades\Log::warning("Marzneshin services fetch: Missing credentials for panel {$this->id}", [
                        'panel_id' => $this->id,
                        'panel_name' => $this->name,
                        'has_url' => !empty($credentials['url']),
                        'has_username' => !empty($credentials['username']),
                        'has_password' => !empty($credentials['password']),
                    ]);
                    return [];
                }
                
                // Validate URL format
                $baseUrl = trim($credentials['url']);
                if (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
                    \Illuminate\Support\Facades\Log::warning("Marzneshin services fetch: Invalid URL for panel {$this->id}", [
                        'panel_id' => $this->id,
                        'panel_name' => $this->name,
                    ]);
                    return [];
                }
                
                $service = new \App\Services\MarzneshinService(
                    $credentials['url'],
                    $credentials['username'],
                    $credentials['password'],
                    $credentials['extra']['node_hostname'] ?? ''
                );
                
                $services = $service->listServices();
                
                // Ensure we return an array
                if (!is_array($services)) {
                    \Illuminate\Support\Facades\Log::warning("Marzneshin services fetch: API returned non-array for panel {$this->id}", [
                        'panel_id' => $this->id,
                        'panel_name' => $this->name,
                        'response_type' => gettype($services),
                    ]);
                    return [];
                }
                
                if (empty($services)) {
                    \Illuminate\Support\Facades\Log::info("Marzneshin services fetch: API returned no services for panel {$this->id}", [
                        'panel_id' => $this->id,
                        'panel_name' => $this->name,
                        'url' => $credentials['url'],
                    ]);
                } else {
                    \Illuminate\Support\Facades\Log::debug("Marzneshin services fetch: Successfully retrieved " . count($services) . " services for panel {$this->id}");
                }
                
                return $services;
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Failed to fetch Marzneshin services for panel {$this->id}: " . $e->getMessage(), [
                    'panel_id' => $this->id,
                    'panel_name' => $this->name,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);
                return [];
            }
        });
    }

    /**
     * Get registration default service IDs for Marzneshin panels
     *
     * @return array
     */
    public function getRegistrationDefaultServiceIds(): array
    {
        if (strtolower(trim($this->panel_type ?? '')) !== 'marzneshin') {
            return [];
        }

        return $this->registration_default_service_ids ?? [];
    }
}
