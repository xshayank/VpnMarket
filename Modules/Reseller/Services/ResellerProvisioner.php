<?php

namespace Modules\Reseller\Services;

use App\Models\Panel;
use App\Models\Plan;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\Setting;
use App\Provisioners\ProvisionerFactory;
use App\Services\EylandooService;
use App\Services\MarzbanService;
use App\Services\MarzneshinService;
use App\Services\UsernameGenerator;
use App\Services\XUIService;
use Illuminate\Support\Facades\Log;

class ResellerProvisioner
{
    /**
     * Retry an operation with exponential backoff
     * Attempts: 0s, 1s, 3s (3 total attempts)
     *
     * @param  callable  $operation  The operation to retry (should return bool)
     * @param  string  $description  Description for logging (no sensitive data)
     * @return array ['success' => bool, 'attempts' => int, 'last_error' => ?string]
     */
    protected function retryOperation(callable $operation, string $description): array
    {
        $maxAttempts = 3;
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                // Exponential backoff: 0s, 1s, 3s
                if ($attempt > 1) {
                    $delay = pow(2, $attempt - 2); // 2^0=1, 2^1=2, but we want 1, 3
                    if ($attempt == 2) {
                        $delay = 1;
                    } else {
                        $delay = 3;
                    }
                    usleep($delay * 1000000); // Convert to microseconds
                }

                $result = $operation();

                if ($result) {
                    return [
                        'success' => true,
                        'attempts' => $attempt,
                        'last_error' => null,
                    ];
                }

                $lastError = 'Operation returned false';
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                Log::warning("Attempt {$attempt}/{$maxAttempts} to {$description} failed: {$lastError}");
            }
        }

        Log::error("All {$maxAttempts} attempts to {$description} failed. Last error: {$lastError}");

        return [
            'success' => false,
            'attempts' => $maxAttempts,
            'last_error' => $lastError,
        ];
    }

    /**
     * Apply rate limiting with micro-sleeps to evenly distribute operations
     * Rate: 3 operations per second
     *
     * @param  int  $operationCount  Current operation count (0-indexed)
     */
    public function applyRateLimit(int $operationCount): void
    {
        if ($operationCount > 0) {
            // 3 ops/sec = 333ms between operations
            usleep(333333); // 333.333 milliseconds
        }
    }

    /**
     * Create a username following the reseller naming convention
     *
     * @param  Reseller  $reseller  The reseller instance
     * @param  string  $type  Type of resource ('order', 'config', etc.)
     * @param  int  $id  The resource ID
     * @param  int|null  $index  Optional index (for orders)
     * @param  string|null  $customPrefix  Optional custom prefix to use for this specific config
     * @param  string|null  $customName  Optional custom name that completely overrides the generator
     * @return string The generated username
     */
    public function generateUsername(Reseller $reseller, string $type, int $id, ?int $index = null, ?string $customPrefix = null, ?string $customName = null): string
    {
        // If custom name is provided, use it directly (overrides everything)
        if ($customName) {
            return $customName;
        }

        // Use custom prefix if provided, otherwise fall back to reseller's default prefix
        $prefix = $customPrefix ?? $reseller->username_prefix ?? Setting::where('key', 'reseller.username_prefix')->value('value') ?? 'resell';

        if ($type === 'order') {
            return "{$prefix}_{$reseller->id}_order_{$id}_{$index}";
        } elseif ($type === 'config') {
            return "{$prefix}_{$reseller->id}_cfg_{$id}";
        }

        return "{$prefix}_{$reseller->id}_{$type}_{$id}";
    }

    /**
     * Generate an enhanced username for panel interactions
     * Uses UsernameGenerator to create a sanitized, unique panel username
     *
     * @param  string  $requestedUsername  The original username requested by user/bot
     * @param  callable|null  $existsChecker  Optional callback to check if username exists
     * @return array ['panel_username' => string, 'username_prefix' => string, 'original_username' => string]
     */
    public function generateEnhancedUsername(string $requestedUsername, ?callable $existsChecker = null): array
    {
        $generator = new UsernameGenerator;

        // If no exists checker provided, use the default database checker
        if ($existsChecker === null) {
            $existsChecker = $generator->createDatabaseExistsChecker();
        }

        return $generator->generatePanelUsername($requestedUsername, $existsChecker);
    }

    /**
     * Create a panel-specific exists checker that queries the remote panel
     * This can be used for extra safety when collision handling needs to check the panel directly
     *
     * @param  Panel  $panel  The panel to check against
     */
    public function createPanelExistsChecker(Panel $panel): callable
    {
        return function (string $username): bool {
            // First check local database
            $localExists = ResellerConfig::where('panel_username', $username)
                ->orWhere('external_username', $username)
                ->exists();

            if ($localExists) {
                return true;
            }

            // Note: Remote panel check is disabled by default for performance.
            // Enable remote checking by uncommenting the following line if you need
            // to verify usernames against the panel API. This adds latency but
            // provides extra safety for edge cases where the local DB is out of sync.
            // To enable, uncomment: return $this->checkUsernameExistsOnPanel($panel, $username);

            return false;
        };
    }

    /**
     * Check if a username exists on a remote panel
     *
     * @param  Panel  $panel  The panel to check
     * @param  string  $username  The username to check
     */
    protected function checkUsernameExistsOnPanel(Panel $panel, string $username): bool
    {
        try {
            $credentials = $panel->getCredentials();
            $panelType = strtolower(trim($panel->panel_type ?? ''));

            switch ($panelType) {
                case 'marzneshin':
                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new MarzneshinService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password'],
                        $nodeHostname
                    );
                    if ($service->login()) {
                        $user = $service->getUser($username);

                        return $user !== null;
                    }
                    break;

                case 'marzban':
                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new MarzbanService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password'],
                        $nodeHostname
                    );
                    if ($service->login()) {
                        $user = $service->getUser($username);

                        return $user !== null;
                    }
                    break;

                case 'eylandoo':
                    if (empty($credentials['url']) || empty($credentials['api_token'])) {
                        return false;
                    }
                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new EylandooService(
                        $credentials['url'],
                        $credentials['api_token'],
                        $nodeHostname
                    );
                    $user = $service->getUser($username);

                    return $user !== null;
            }
        } catch (\Exception $e) {
            Log::warning('Failed to check username on panel', [
                'panel_id' => $panel->id,
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * Provision a user on a panel
     */
    public function provisionUser(Panel $panel, Plan $plan, string $username, array $options = []): ?array
    {
        try {
            $credentials = $panel->getCredentials();

            switch ($panel->panel_type) {
                case 'marzban':
                    return $this->provisionMarzban($credentials, $plan, $username, $options);

                case 'marzneshin':
                    return $this->provisionMarzneshin($credentials, $plan, $username, $options);

                case 'xui':
                    return $this->provisionXUI($credentials, $plan, $username, $options);

                case 'eylandoo':
                    return $this->provisionEylandoo($credentials, $plan, $username, $options);

                default:
                    Log::error("Unknown panel type: {$panel->panel_type}");

                    return null;
            }
        } catch (\Exception $e) {
            Log::error("Failed to provision user on panel {$panel->id}: ".$e->getMessage());

            return null;
        }
    }

    /**
     * Provision user on Marzban panel
     */
    protected function provisionMarzban(array $credentials, Plan $plan, string $username, array $options): ?array
    {
        $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';

        $service = new MarzbanService(
            $credentials['url'],
            $credentials['username'],
            $credentials['password'],
            $nodeHostname
        );

        if (! $service->login()) {
            return null;
        }

        $expiresAt = $options['expires_at'] ?? now()->addDays($plan->duration_days);
        $trafficLimit = $options['traffic_limit_bytes'] ?? ($plan->volume_gb * 1024 * 1024 * 1024);

        $result = $service->createUser([
            'username' => $username,
            'expire' => $expiresAt->timestamp,
            'data_limit' => $trafficLimit,
        ]);

        if ($result && isset($result['subscription_url'])) {
            $subscriptionUrl = $service->buildAbsoluteSubscriptionUrl($result);

            return [
                'username' => $username,
                'subscription_url' => $subscriptionUrl,
                'panel_type' => 'marzban',
                'panel_user_id' => $username,
            ];
        }

        return null;
    }

    /**
     * Provision user on Marzneshin panel
     */
    protected function provisionMarzneshin(array $credentials, Plan $plan, string $username, array $options): ?array
    {
        $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';

        $service = new MarzneshinService(
            $credentials['url'],
            $credentials['username'],
            $credentials['password'],
            $nodeHostname
        );

        if (! $service->login()) {
            return null;
        }

        $expiresAt = $options['expires_at'] ?? now()->addDays($plan->duration_days);
        $trafficLimit = $options['traffic_limit_bytes'] ?? ($plan->volume_gb * 1024 * 1024 * 1024);
        $serviceIds = $options['service_ids'] ?? $plan->marzneshin_service_ids ?? [];

        // Prepare user data array for MarzneshinService::createUser()
        $userData = [
            'username' => $username,
            'data_limit' => $trafficLimit,
            'service_ids' => (array) $serviceIds,
        ];

        // Handle expire strategy - pass to MarzneshinService for proper handling
        $expireStrategy = $options['expire_strategy'] ?? 'fixed_date';
        $userData['expire_strategy'] = $expireStrategy;

        if ($expireStrategy === 'start_on_first_use') {
            // Pass usage_duration in seconds for MarzneshinService to convert to days
            $usageDuration = $options['usage_duration'] ?? 0;
            if ($usageDuration > 0) {
                $userData['usage_duration'] = $usageDuration;

                Log::info('ResellerProvisioner: Provisioning Marzneshin user with start_on_first_use', [
                    'username' => $username,
                    'usage_duration_seconds' => $usageDuration,
                    'normalized_usage_days' => $options['normalized_usage_days'] ?? null,
                ]);
            }
        } elseif ($expireStrategy === 'never') {
            // MarzneshinService handles "never" strategy
            Log::info('ResellerProvisioner: Provisioning Marzneshin user with never expiry', [
                'username' => $username,
            ]);
        } else {
            // fixed_date strategy - use expire timestamp
            $userData['expire'] = $expiresAt->getTimestamp();
        }

        $result = $service->createUser($userData);

        if ($result && isset($result['subscription_url'])) {
            $subscriptionUrl = $service->buildAbsoluteSubscriptionUrl($result);

            return [
                'username' => $username,
                'subscription_url' => $subscriptionUrl,
                'panel_type' => 'marzneshin',
                'panel_user_id' => $username,
            ];
        }

        return null;
    }

    /**
     * Provision user on X-UI panel
     */
    protected function provisionXUI(array $credentials, Plan $plan, string $username, array $options): ?array
    {
        $service = new XUIService(
            $credentials['url'],
            $credentials['username'],
            $credentials['password']
        );

        if (! $service->login()) {
            return null;
        }

        $expiresAt = $options['expires_at'] ?? now()->addDays($plan->duration_days);
        $trafficLimit = $options['traffic_limit_bytes'] ?? ($plan->volume_gb * 1024 * 1024 * 1024);

        $result = $service->createUser(
            $username,
            $trafficLimit,
            $expiresAt->timestamp
        );

        if ($result) {
            return [
                'username' => $username,
                'subscription_url' => $result['subscription_url'] ?? null,
                'panel_type' => 'xui',
                'panel_user_id' => $result['user_id'] ?? $username,
            ];
        }

        return null;
    }

    /**
     * Provision user on Eylandoo panel
     */
    protected function provisionEylandoo(array $credentials, Plan $plan, string $username, array $options): ?array
    {
        $correlationId = uniqid('provision_eylandoo_', true);

        // Validate credentials before attempting API calls
        if (empty($credentials['url']) || empty($credentials['api_token'])) {
            Log::warning('Eylandoo provision: Missing credentials', [
                'correlation_id' => $correlationId,
                'has_url' => ! empty($credentials['url']),
                'has_api_token' => ! empty($credentials['api_token']),
                'username' => $username,
            ]);

            throw new \RuntimeException('اعتبارنامه پنل Eylandoo تنظیم نشده است.');
        }

        $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';

        try {
            $service = new EylandooService(
                $credentials['url'],
                $credentials['api_token'],
                $nodeHostname
            );

            // Preflight health check
            $healthCheck = $service->checkHealth();
            if (! $healthCheck['online']) {
                Log::warning('Eylandoo provision: Panel offline', [
                    'correlation_id' => $correlationId,
                    'username' => $username,
                    'health_message' => $healthCheck['message'],
                ]);

                throw new \RuntimeException('پنل Eylandoo در دسترس نیست. لطفاً بعداً تلاش کنید.');
            }

            $expiresAt = $options['expires_at'] ?? now()->addDays($plan->duration_days);
            $trafficLimit = $options['traffic_limit_bytes'] ?? ($plan->volume_gb * 1024 * 1024 * 1024);

            // Accept both 'max_clients' and 'connections' parameters, default to 1
            $maxClients = (int) ($options['max_clients'] ?? $options['connections'] ?? 1);
            if ($maxClients <= 0) {
                $maxClients = 1;
            }

            // Accept both 'nodes' and 'node_ids' parameters and normalize to array of integers
            $nodes = $options['nodes'] ?? $options['node_ids'] ?? [];
            if (! empty($nodes) && is_array($nodes)) {
                $nodes = array_map('intval', $nodes);
            } elseif (! is_array($nodes)) {
                $nodes = [];
            }

            // Only include nodes if array is non-empty
            $userData = [
                'username' => $username,
                'expire' => $expiresAt->timestamp,
                'data_limit' => $trafficLimit,
                'max_clients' => $maxClients,
            ];

            // Only add nodes if the array is non-empty
            if (! empty($nodes) && is_array($nodes)) {
                $userData['nodes'] = $nodes;
                Log::debug('Eylandoo provision: Including nodes in user data', [
                    'correlation_id' => $correlationId,
                    'username' => $username,
                    'nodes' => $nodes,
                    'nodes_count' => count($nodes),
                ]);
            }

            // Add L2TP support if enabled
            if (isset($options['enable_l2tp'])) {
                $userData['enable_l2tp'] = (bool) $options['enable_l2tp'];
                if ($userData['enable_l2tp'] && isset($options['l2tp_password']) && ! empty($options['l2tp_password'])) {
                    $userData['l2tp_password'] = (string) $options['l2tp_password'];
                }
            }

            // Add Cisco support if enabled
            if (isset($options['enable_cisco'])) {
                $userData['enable_cisco'] = (bool) $options['enable_cisco'];
                if ($userData['enable_cisco'] && isset($options['cisco_password']) && ! empty($options['cisco_password'])) {
                    $userData['cisco_password'] = (string) $options['cisco_password'];
                }
            }

            $result = $service->createUser($userData);

            Log::info('Eylandoo provision result', [
                'correlation_id' => $correlationId,
                'result' => $result,
                'username' => $username,
            ]);

            // Check for explicit failure with error message
            if ($result && is_array($result) && isset($result['success']) && $result['success'] === false) {
                $errorMessage = $result['error'] ?? 'خطای ناشناخته در ایجاد کانفیگ';
                Log::warning('Eylandoo provision failed with error', [
                    'correlation_id' => $correlationId,
                    'username' => $username,
                    'error' => $errorMessage,
                ]);

                throw new \RuntimeException($errorMessage);
            }

            // Detect success: either result.success === true or created_users contains username
            $success = false;
            if ($result && is_array($result)) {
                // Check for success flag
                if (isset($result['success']) && $result['success'] === true) {
                    $success = true;
                }
                // Or check if created_users array contains this username
                elseif (isset($result['created_users']) && is_array($result['created_users']) && in_array($username, $result['created_users'])) {
                    $success = true;
                }
            }

            if ($success) {
                $subscriptionUrl = null;

                // First, try to extract subscription URL from create response
                if ($service->extractSubscriptionUrl($result)) {
                    $subscriptionUrl = $service->buildAbsoluteSubscriptionUrl($result);
                }

                // If no subscription URL in create response, fetch from dedicated subscription endpoint
                if (empty($subscriptionUrl)) {
                    Log::info("No subscription URL in create response for {$username}, fetching from /sub endpoint", [
                        'correlation_id' => $correlationId,
                    ]);

                    $subResponse = $service->getUserSubscription($username);
                    if ($subResponse) {
                        Log::info('Eylandoo subscription endpoint response', [
                            'correlation_id' => $correlationId,
                            'subResponse' => $subResponse,
                        ]);

                        // Extract subscription URL from /sub response
                        $subscriptionUrl = $service->extractSubscriptionUrlFromSub($subResponse);
                        Log::info('Extracted subscription URL', [
                            'correlation_id' => $correlationId,
                            'username' => $username,
                            'subscription_url' => $subscriptionUrl,
                        ]);
                    }
                }

                return [
                    'username' => $username,
                    'subscription_url' => $subscriptionUrl,
                    'panel_type' => 'eylandoo',
                    'panel_user_id' => $username,
                    'correlation_id' => $correlationId,
                ];
            }

            // Provision failed without explicit error
            throw new \RuntimeException('ایجاد کانفیگ روی پنل ناموفق بود.');
        } catch (\RuntimeException $e) {
            // Re-throw runtime exceptions as they already have user-friendly messages
            throw $e;
        } catch (\Exception $e) {
            Log::error('Eylandoo provision exception', [
                'correlation_id' => $correlationId,
                'username' => $username,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('خطا در ایجاد کانفیگ: '.$e->getMessage());
        }
    }

    /**
     * Disable a user on a panel with retry logic
     *
     * @return array ['success' => bool, 'attempts' => int, 'last_error' => ?string]
     */
    public function disableUser(string $panelType, array $credentials, string $panelUserId): array
    {
        return $this->retryOperation(function () use ($panelType, $credentials, $panelUserId) {
            switch ($panelType) {
                case 'marzban':
                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new MarzbanService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password'],
                        $nodeHostname
                    );
                    if ($service->login()) {
                        return $service->updateUser($panelUserId, ['status' => 'disabled']);
                    }
                    break;

                case 'marzneshin':
                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new MarzneshinService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password'],
                        $nodeHostname
                    );
                    if ($service->login()) {
                        // For Marzneshin, use the dedicated disable endpoint
                        return $service->disableUser($panelUserId);
                    }
                    break;

                case 'xui':
                    $service = new XUIService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password']
                    );
                    if ($service->login()) {
                        return $service->updateUser($panelUserId, ['enable' => false]);
                    }
                    break;

                case 'eylandoo':
                    // Validate credentials
                    if (empty($credentials['url']) || empty($credentials['api_token'])) {
                        Log::warning('disableUser Eylandoo: Missing credentials', [
                            'panel_user_id' => $panelUserId,
                        ]);

                        return false;
                    }

                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new EylandooService(
                        $credentials['url'],
                        $credentials['api_token'],
                        $nodeHostname
                    );

                    return $service->disableUser($panelUserId);
            }

            return false;
        }, "disable user {$panelUserId}");
    }

    /**
     * Enable a user on a panel with retry logic
     *
     * @return array ['success' => bool, 'attempts' => int, 'last_error' => ?string]
     */
    public function enableUser(string $panelType, array $credentials, string $panelUserId): array
    {
        return $this->retryOperation(function () use ($panelType, $credentials, $panelUserId) {
            switch ($panelType) {
                case 'marzban':
                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new MarzbanService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password'],
                        $nodeHostname
                    );
                    if ($service->login()) {
                        return $service->updateUser($panelUserId, ['status' => 'active']);
                    }
                    break;

                case 'marzneshin':
                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new MarzneshinService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password'],
                        $nodeHostname
                    );
                    if ($service->login()) {
                        // For Marzneshin, use the dedicated enable endpoint
                        return $service->enableUser($panelUserId);
                    }
                    break;

                case 'xui':
                    $service = new XUIService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password']
                    );
                    if ($service->login()) {
                        return $service->updateUser($panelUserId, ['enable' => true]);
                    }
                    break;

                case 'eylandoo':
                    // Validate credentials
                    if (empty($credentials['url']) || empty($credentials['api_token'])) {
                        Log::warning('enableUser Eylandoo: Missing credentials', [
                            'panel_user_id' => $panelUserId,
                        ]);

                        return false;
                    }

                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new EylandooService(
                        $credentials['url'],
                        $credentials['api_token'],
                        $nodeHostname
                    );

                    return $service->enableUser($panelUserId);
            }

            return false;
        }, "enable user {$panelUserId}");
    }

    /**
     * Enable a config on its panel (uses new modular provisioner architecture)
     *
     * @return array ['success' => bool, 'attempts' => int, 'last_error' => ?string]
     */
    public function enableConfig(\App\Models\ResellerConfig $config): array
    {
        try {
            // Use the new provisioner factory for modular, provider-specific implementation
            $provisioner = ProvisionerFactory::forConfig($config);

            return $provisioner->enableConfig($config);
        } catch (\Exception $e) {
            Log::error("Failed to enable config {$config->id} via provisioner factory: ".$e->getMessage(), [
                'config_id' => $config->id,
                'reseller_id' => $config->reseller_id,
                'panel_id' => $config->panel_id,
                'panel_type' => $config->panel_type,
            ]);

            return ['success' => false, 'attempts' => 0, 'last_error' => $e->getMessage()];
        }
    }

    /**
     * Disable a config on its panel (uses new modular provisioner architecture)
     *
     * @return array ['success' => bool, 'attempts' => int, 'last_error' => ?string]
     */
    public function disableConfig(\App\Models\ResellerConfig $config): array
    {
        try {
            // Use the new provisioner factory for modular, provider-specific implementation
            $provisioner = ProvisionerFactory::forConfig($config);

            return $provisioner->disableConfig($config);
        } catch (\Exception $e) {
            Log::error("Failed to disable config {$config->id} via provisioner factory: ".$e->getMessage(), [
                'config_id' => $config->id,
                'reseller_id' => $config->reseller_id,
                'panel_id' => $config->panel_id,
                'panel_type' => $config->panel_type,
            ]);

            return ['success' => false, 'attempts' => 0, 'last_error' => $e->getMessage()];
        }
    }

    /**
     * Delete a user on a panel
     */
    public function deleteUser(string $panelType, array $credentials, string $panelUserId): bool
    {
        try {
            switch ($panelType) {
                case 'marzban':
                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new MarzbanService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password'],
                        $nodeHostname
                    );
                    if ($service->login()) {
                        return $service->deleteUser($panelUserId);
                    }
                    break;

                case 'marzneshin':
                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new MarzneshinService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password'],
                        $nodeHostname
                    );
                    if ($service->login()) {
                        return $service->deleteUser($panelUserId);
                    }
                    break;

                case 'xui':
                    $service = new XUIService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password']
                    );
                    if ($service->login()) {
                        return $service->deleteUser($panelUserId);
                    }
                    break;

                case 'eylandoo':
                    // Validate credentials
                    if (empty($credentials['url']) || empty($credentials['api_token'])) {
                        Log::warning('deleteUser Eylandoo: Missing credentials', [
                            'panel_user_id' => $panelUserId,
                        ]);

                        return false;
                    }

                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new EylandooService(
                        $credentials['url'],
                        $credentials['api_token'],
                        $nodeHostname
                    );

                    return $service->deleteUser($panelUserId);
            }
        } catch (\Exception $e) {
            Log::error("Failed to delete user {$panelUserId}: ".$e->getMessage());
        }

        return false;
    }

    /**
     * Update user limits (traffic and expiry) on a panel with retry logic
     *
     * @param  \Carbon\Carbon  $expiresAt
     * @return array ['success' => bool, 'attempts' => int, 'last_error' => ?string]
     */
    public function updateUserLimits(string $panelType, array $credentials, string $panelUserId, int $trafficLimitBytes, $expiresAt): array
    {
        return $this->retryOperation(function () use ($panelType, $credentials, $panelUserId, $trafficLimitBytes, $expiresAt) {
            switch ($panelType) {
                case 'marzban':
                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new MarzbanService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password'],
                        $nodeHostname
                    );
                    if ($service->login()) {
                        return $service->updateUser($panelUserId, [
                            'data_limit' => $trafficLimitBytes,
                            'expire' => $expiresAt->timestamp,
                        ]);
                    }
                    break;

                case 'marzneshin':
                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new MarzneshinService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password'],
                        $nodeHostname
                    );
                    if ($service->login()) {
                        return $service->updateUser($panelUserId, [
                            'data_limit' => $trafficLimitBytes,
                            'expire' => $expiresAt->getTimestamp(),
                        ]);
                    }
                    break;

                case 'xui':
                    $service = new XUIService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password']
                    );
                    if ($service->login()) {
                        return $service->updateUser($panelUserId, [
                            'total' => $trafficLimitBytes,
                            'expiryTime' => $expiresAt->timestamp * 1000, // X-UI uses milliseconds
                        ]);
                    }
                    break;

                case 'eylandoo':
                    // Validate credentials
                    if (empty($credentials['url']) || empty($credentials['api_token'])) {
                        Log::warning('updateUserLimits Eylandoo: Missing credentials', [
                            'panel_user_id' => $panelUserId,
                        ]);

                        return false;
                    }

                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new EylandooService(
                        $credentials['url'],
                        $credentials['api_token'],
                        $nodeHostname
                    );

                    return $service->updateUser($panelUserId, [
                        'data_limit' => $trafficLimitBytes,
                        'expire' => $expiresAt->timestamp,
                    ]);
            }

            return false;
        }, "update user limits for {$panelUserId}");
    }

    /**
     * Update user with additional properties (including max_clients for Eylandoo)
     * This method provides more flexibility than updateUserLimits
     *
     * @param  string  $panelType  Panel type
     * @param  array  $credentials  Panel credentials
     * @param  string  $panelUserId  Panel user ID
     * @param  array  $payload  Update payload (data_limit, expire, max_clients, nodes, etc.)
     * @return array ['success' => bool, 'attempts' => int, 'last_error' => ?string]
     */
    public function updateUser(string $panelType, array $credentials, string $panelUserId, array $payload): array
    {
        return $this->retryOperation(function () use ($panelType, $credentials, $panelUserId, $payload) {
            switch ($panelType) {
                case 'marzban':
                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new MarzbanService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password'],
                        $nodeHostname
                    );
                    if ($service->login()) {
                        $updateData = [];
                        if (isset($payload['data_limit'])) {
                            $updateData['data_limit'] = $payload['data_limit'];
                        }
                        if (isset($payload['expire'])) {
                            $updateData['expire'] = $payload['expire'];
                        }

                        return $service->updateUser($panelUserId, $updateData);
                    }
                    break;

                case 'marzneshin':
                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new MarzneshinService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password'],
                        $nodeHostname
                    );
                    if ($service->login()) {
                        $updateData = [];
                        if (isset($payload['data_limit'])) {
                            $updateData['data_limit'] = $payload['data_limit'];
                        }
                        if (isset($payload['expire'])) {
                            $expire = $payload['expire'];
                            if (is_int($expire)) {
                                $updateData['expire'] = $expire;
                            } elseif (is_object($expire) && method_exists($expire, 'getTimestamp')) {
                                $updateData['expire'] = $expire->getTimestamp();
                            }
                        }

                        return $service->updateUser($panelUserId, $updateData);
                    }
                    break;

                case 'xui':
                    $service = new XUIService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password']
                    );
                    if ($service->login()) {
                        $updateData = [];
                        if (isset($payload['data_limit'])) {
                            $updateData['total'] = $payload['data_limit'];
                        }
                        if (isset($payload['expire'])) {
                            $expire = $payload['expire'];
                            if (is_int($expire)) {
                                $updateData['expiryTime'] = $expire * 1000;
                            } elseif (is_object($expire) && property_exists($expire, 'timestamp')) {
                                $updateData['expiryTime'] = $expire->timestamp * 1000;
                            }
                        }

                        return $service->updateUser($panelUserId, $updateData);
                    }
                    break;

                case 'eylandoo':
                    // Validate credentials
                    if (empty($credentials['url']) || empty($credentials['api_token'])) {
                        Log::warning('updateUser Eylandoo: Missing credentials', [
                            'panel_user_id' => $panelUserId,
                        ]);

                        return false;
                    }

                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new EylandooService(
                        $credentials['url'],
                        $credentials['api_token'],
                        $nodeHostname
                    );

                    // Build update payload for Eylandoo
                    $updateData = [];
                    if (isset($payload['data_limit'])) {
                        $updateData['data_limit'] = $payload['data_limit'];
                    }
                    if (isset($payload['expire'])) {
                        $updateData['expire'] = $payload['expire'];
                    }
                    if (isset($payload['max_clients'])) {
                        $updateData['max_clients'] = (int) $payload['max_clients'];
                    }
                    if (isset($payload['nodes'])) {
                        $updateData['nodes'] = $payload['nodes'];
                    }

                    // Add L2TP settings if provided
                    if (array_key_exists('enable_l2tp', $payload)) {
                        $updateData['enable_l2tp'] = (bool) $payload['enable_l2tp'];
                        if ($updateData['enable_l2tp'] && isset($payload['l2tp_password']) && ! empty($payload['l2tp_password'])) {
                            $updateData['l2tp_password'] = (string) $payload['l2tp_password'];
                        }
                    }

                    // Add Cisco settings if provided
                    if (array_key_exists('enable_cisco', $payload)) {
                        $updateData['enable_cisco'] = (bool) $payload['enable_cisco'];
                        if ($updateData['enable_cisco'] && isset($payload['cisco_password']) && ! empty($payload['cisco_password'])) {
                            $updateData['cisco_password'] = (string) $payload['cisco_password'];
                        }
                    }

                    return $service->updateUser($panelUserId, $updateData);
            }

            return false;
        }, "update user {$panelUserId}");
    }

    /**
     * Reset user usage on a panel with retry logic
     *
     * @return array ['success' => bool, 'attempts' => int, 'last_error' => ?string]
     */
    public function resetUserUsage(string $panelType, array $credentials, string $panelUserId): array
    {
        return $this->retryOperation(function () use ($panelType, $credentials, $panelUserId) {
            switch ($panelType) {
                case 'marzban':
                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new MarzbanService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password'],
                        $nodeHostname
                    );
                    if ($service->login()) {
                        // Reset usage by setting used_traffic to 0
                        return $service->resetUserUsage($panelUserId);
                    }
                    break;

                case 'marzneshin':
                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new MarzneshinService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password'],
                        $nodeHostname
                    );
                    if ($service->login()) {
                        return $service->resetUserUsage($panelUserId);
                    }
                    break;

                case 'xui':
                    $service = new XUIService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password']
                    );
                    if ($service->login()) {
                        // X-UI resets usage by setting up and down to 0
                        return $service->resetUserUsage($panelUserId);
                    }
                    break;

                case 'eylandoo':
                    // Validate credentials
                    if (empty($credentials['url']) || empty($credentials['api_token'])) {
                        Log::warning('resetUserUsage Eylandoo: Missing credentials', [
                            'panel_user_id' => $panelUserId,
                        ]);

                        return false;
                    }

                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new EylandooService(
                        $credentials['url'],
                        $credentials['api_token'],
                        $nodeHostname
                    );

                    return $service->resetUserUsage($panelUserId);
            }

            return false;
        }, "reset user usage for {$panelUserId}");
    }

    /**
     * Fetch Eylandoo nodes for a panel
     * Helper method that can be used in Filament forms and reseller config creation
     *
     * @param  Panel  $panel  The panel to fetch nodes from
     * @return array Array of nodes with 'id' and 'name' keys
     */
    public function fetchEylandooNodes(Panel $panel): array
    {
        if (! $panel || strtolower(trim($panel->panel_type ?? '')) !== 'eylandoo') {
            return [];
        }

        try {
            $credentials = $panel->getCredentials();

            // Validate credentials before attempting API calls
            if (empty($credentials['url']) || empty($credentials['api_token'])) {
                Log::warning('fetchEylandooNodes: Missing credentials', [
                    'panel_id' => $panel->id,
                    'panel_name' => $panel->name,
                    'has_url' => ! empty($credentials['url']),
                    'has_api_token' => ! empty($credentials['api_token']),
                ]);

                return [];
            }

            // Short circuit for invalid base URL
            $baseUrl = trim($credentials['url']);
            if (empty($baseUrl) || ! filter_var($baseUrl, FILTER_VALIDATE_URL)) {
                Log::warning('fetchEylandooNodes: Invalid base URL', [
                    'panel_id' => $panel->id,
                    'panel_name' => $panel->name,
                ]);

                return [];
            }

            $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';

            $service = new EylandooService(
                $credentials['url'],
                $credentials['api_token'],
                $nodeHostname
            );

            $nodes = $service->listNodes();

            // Ensure we return an array
            if (! is_array($nodes)) {
                Log::warning('fetchEylandooNodes: API returned non-array', [
                    'panel_id' => $panel->id,
                    'panel_name' => $panel->name,
                    'response_type' => gettype($nodes),
                ]);

                return [];
            }

            if (empty($nodes)) {
                Log::info('fetchEylandooNodes: No nodes returned from API', [
                    'panel_id' => $panel->id,
                    'panel_name' => $panel->name,
                ]);
            }

            // Validate and normalize node structure
            $normalizedNodes = [];
            foreach ($nodes as $node) {
                if (is_array($node) && isset($node['id'])) {
                    $normalizedNodes[] = [
                        'id' => (int) $node['id'],
                        'name' => $node['name'] ?? "Node {$node['id']}",
                    ];
                }
            }

            return $normalizedNodes;
        } catch (\Exception $e) {
            Log::error('fetchEylandooNodes: Exception occurred', [
                'panel_id' => $panel->id,
                'panel_name' => $panel->name,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
