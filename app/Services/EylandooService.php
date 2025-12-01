<?php

namespace App\Services;

use App\Helpers\DurationNormalization;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EylandooService
{
    protected string $baseUrl;

    protected string $apiKey;

    protected string $nodeHostname;

    public function __construct(string $baseUrl, string $apiKey, string $nodeHostname = '')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->nodeHostname = $nodeHostname;
    }

    /**
     * Get authenticated HTTP client
     */
    protected function client()
    {
        return Http::withHeaders([
            'X-API-KEY' => $this->apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * Create a new user
     *
     * @param  array  $userData  User data with keys: username, data_limit, expire, max_clients (connections), nodes
     * @return array|null Response from API or null on failure
     */
    public function createUser(array $userData): ?array
    {
        try {
            $payload = [
                'username' => $userData['username'],
                'activation_type' => 'fixed_date',
            ];

            // Add max_clients (connections) if provided
            if (isset($userData['max_clients'])) {
                $payload['max_clients'] = (int) $userData['max_clients'];
            }

            // Add data_limit - Eylandoo expects the limit in the specified unit
            // Use MB for values < 1 GB, GB for values >= 1 GB
            if (isset($userData['data_limit']) && $userData['data_limit'] !== null) {
                $dataLimitBytes = (int) $userData['data_limit'];
                $dataLimitResult = DurationNormalization::prepareEylandooDataLimit($dataLimitBytes);
                $payload['data_limit'] = $dataLimitResult['value'];
                $payload['data_limit_unit'] = $dataLimitResult['unit'];

                Log::info('Eylandoo data limit conversion', [
                    'input_bytes' => $dataLimitBytes,
                    'output_value' => $dataLimitResult['value'],
                    'output_unit' => $dataLimitResult['unit'],
                    'username' => $userData['username'],
                ]);
            }

            // Add expiry date if provided
            if (isset($userData['expire'])) {
                $payload['expiry_date_str'] = date('Y-m-d', $userData['expire']);
            }

            // Add nodes if provided and non-empty (array of node IDs)
            if (isset($userData['nodes']) && is_array($userData['nodes']) && ! empty($userData['nodes'])) {
                $payload['nodes'] = array_map('intval', $userData['nodes']);
            }

            // Add L2TP support if enabled
            if (isset($userData['enable_l2tp'])) {
                $payload['enable_l2tp'] = (bool) $userData['enable_l2tp'];
                if ($payload['enable_l2tp'] && isset($userData['l2tp_password']) && ! empty($userData['l2tp_password'])) {
                    $payload['l2tp_password'] = (string) $userData['l2tp_password'];
                }
            }

            // Add Cisco support if enabled
            if (isset($userData['enable_cisco'])) {
                $payload['enable_cisco'] = (bool) $userData['enable_cisco'];
                if ($payload['enable_cisco'] && isset($userData['cisco_password']) && ! empty($userData['cisco_password'])) {
                    $payload['cisco_password'] = (string) $userData['cisco_password'];
                }
            }

            $response = $this->client()->post($this->baseUrl.'/api/v1/users', $payload);

            Log::info('Eylandoo Create User Response:', $response->json() ?? ['raw' => $response->body()]);

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Eylandoo Create User Exception:', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Get user details
     *
     * @param  string  $username  Username to fetch
     * @return array|null User data or null on failure
     */
    public function getUser(string $username): ?array
    {
        try {
            $encodedUsername = rawurlencode($username);
            $endpoint = "/api/v1/users/{$encodedUsername}";

            Log::debug('Eylandoo Get User: requesting endpoint', [
                'username' => $username,
                'endpoint' => $endpoint,
            ]);

            $response = $this->client()->get($this->baseUrl.$endpoint);

            if ($response->successful()) {
                $userData = $response->json();

                // Inject normalized 'used_traffic' key to match other panel services
                // Parse usage from response using existing helper
                $parseResult = $this->parseUsageFromResponse($userData);
                $usageBytes = $parseResult['bytes'] ?? 0;

                // Add used_traffic and data_used at the top level of the response
                // Both set to the same value for Eylandoo (Marzban/Marzneshin compatibility)
                $userData['used_traffic'] = $usageBytes;
                $userData['data_used'] = $usageBytes;

                return $userData;
            }

            Log::warning('Eylandoo Get User failed:', [
                'status' => $response->status(),
                'username' => $username,
                'endpoint' => $endpoint,
                'body_preview' => substr($response->body(), 0, 500),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Eylandoo Get User Exception:', ['message' => $e->getMessage(), 'username' => $username]);

            return null;
        }
    }

    /**
     * Get user usage in bytes
     *
     * @param  string  $username  Username to fetch usage for
     * @return int|null Usage in bytes, or null on hard failure (HTTP error/exception)
     */
    public function getUserUsageBytes(string $username): ?int
    {
        try {
            Log::info('Eylandoo usage fetch: calling endpoint', [
                'username' => $username,
                'endpoint' => "/api/v1/users/{$username}",
            ]);

            $userResponse = $this->getUser($username);

            if ($userResponse === null) {
                Log::warning('Eylandoo Get User Usage: failed to retrieve user data', ['username' => $username]);

                return null;
            }

            // Parse usage from response using enhanced parser
            $parseResult = $this->parseUsageFromResponse($userResponse);

            if ($parseResult['bytes'] === null) {
                // Hard failure or success:false
                Log::warning('Eylandoo usage parse failed', [
                    'username' => $username,
                    'reason' => $parseResult['reason'],
                    'available_keys' => $parseResult['keys'],
                ]);

                return null;
            }

            // Log success with matched path
            Log::info('Eylandoo usage parsed successfully', [
                'username' => $username,
                'usage_bytes' => $parseResult['bytes'],
                'matched_path' => $parseResult['reason'],
            ]);

            return $parseResult['bytes'];
        } catch (\Exception $e) {
            Log::error('Eylandoo Get User Usage Exception:', ['message' => $e->getMessage(), 'username' => $username]);

            return null;
        }
    }

    /**
     * Parse usage from Eylandoo API response
     * Handles multiple wrapper keys and usage field combinations
     * Collects all viable candidates and chooses the maximum non-null value
     *
     * @param  array  $resp  API response
     * @return array ['bytes' => int|null, 'reason' => string, 'keys' => array]
     */
    private function parseUsageFromResponse(array $resp): array
    {
        // Check for success:false flag (treat as hard failure)
        if (isset($resp['success']) && $resp['success'] === false) {
            return [
                'bytes' => null,
                'reason' => 'API returned success:false',
                'keys' => array_keys($resp),
            ];
        }

        // Collect wrappers to search (include root response and known wrapper keys)
        // Using __root__ to clearly indicate this represents top-level response data
        $wrappers = ['__root__' => $resp];
        $wrapperKeys = ['userInfo', 'data', 'user', 'result', 'stats'];

        foreach ($wrapperKeys as $key) {
            if (isset($resp[$key]) && is_array($resp[$key])) {
                $wrappers[$key] = $resp[$key];
            }
        }

        // Single field keys to check
        $singleKeys = [
            'total_traffic_bytes',
            'traffic_total_bytes',
            'total_bytes',
            'used_traffic',
            'usage_bytes',
            'bytes_used',
            'data_used',
            'data_used_bytes',
            'data_usage_bytes',
            'traffic_used_bytes',
            'totalDataBytes',
            'totalTrafficBytes',
            'trafficBytes',
        ];

        // Pairs to sum (upload + download combinations)
        $pairs = [
            ['upload_bytes', 'download_bytes'],
            ['upload', 'download'],
            ['up', 'down'],
            ['uploaded', 'downloaded'],
            ['uplink', 'downlink'],
        ];

        // Collect all viable candidates
        $candidates = [];

        // Check single fields in each wrapper
        foreach ($wrappers as $wrapperName => $wrapper) {
            foreach ($singleKeys as $key) {
                if (isset($wrapper[$key])) {
                    $value = $wrapper[$key];
                    // Handle string numbers safely - accept only valid integer representations
                    if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                        $bytes = max(0, (int) $value);
                        $candidates[] = [
                            'bytes' => $bytes,
                            'reason' => "{$wrapperName}.{$key}",
                            'keys' => array_keys($wrapper),
                        ];
                    } elseif (is_numeric($value) && $value >= 0) {
                        // Fallback for float/scientific notation, but ensure non-negative
                        $bytes = max(0, (int) $value);
                        $candidates[] = [
                            'bytes' => $bytes,
                            'reason' => "{$wrapperName}.{$key}",
                            'keys' => array_keys($wrapper),
                        ];
                    }
                }
            }
        }

        // Check pairs to sum in each wrapper
        foreach ($wrappers as $wrapperName => $wrapper) {
            foreach ($pairs as $pair) {
                [$upKey, $downKey] = $pair;
                if (isset($wrapper[$upKey]) && isset($wrapper[$downKey])) {
                    $up = $wrapper[$upKey];
                    $down = $wrapper[$downKey];
                    // Handle string numbers safely - accept only valid integer representations
                    $upValid = is_int($up) || (is_string($up) && ctype_digit($up)) || (is_numeric($up) && $up >= 0);
                    $downValid = is_int($down) || (is_string($down) && ctype_digit($down)) || (is_numeric($down) && $down >= 0);

                    if ($upValid && $downValid) {
                        $bytes = max(0, (int) $up + (int) $down);
                        $candidates[] = [
                            'bytes' => $bytes,
                            'reason' => "{$wrapperName}.{$upKey}+{$downKey}",
                            'keys' => array_keys($wrapper),
                        ];
                    }
                }
            }
        }

        // Choose the maximum non-null value from all candidates (O(n) efficiency)
        if (! empty($candidates)) {
            $maxCandidate = $candidates[0];
            foreach ($candidates as $candidate) {
                if ($candidate['bytes'] > $maxCandidate['bytes']) {
                    $maxCandidate = $candidate;
                }
            }

            return $maxCandidate;
        }

        // No usage fields found - valid response but no traffic yet (return 0)
        $availableKeys = [];
        foreach ($wrappers as $wrapperName => $wrapper) {
            $availableKeys[$wrapperName] = array_keys($wrapper);
        }

        return [
            'bytes' => 0,
            'reason' => 'no traffic fields found (valid response, no usage)',
            'keys' => $availableKeys,
        ];
    }

    /**
     * Update user details
     *
     * @param  string  $username  Username to update
     * @param  array  $userData  Data to update (data_limit, expire, max_clients, nodes, etc.)
     * @return bool Success status
     */
    public function updateUser(string $username, array $userData): bool
    {
        try {
            $payload = [];

            // Update max_clients if provided
            if (isset($userData['max_clients'])) {
                $payload['max_clients'] = (int) $userData['max_clients'];
            }

            // Update data_limit if provided
            // Use MB for values < 1 GB, GB for values >= 1 GB
            if (array_key_exists('data_limit', $userData)) {
                if ($userData['data_limit'] === null) {
                    $payload['data_limit'] = null; // Unlimited
                } else {
                    $dataLimitBytes = (int) $userData['data_limit'];
                    $dataLimitResult = DurationNormalization::prepareEylandooDataLimit($dataLimitBytes);
                    $payload['data_limit'] = $dataLimitResult['value'];
                    $payload['data_limit_unit'] = $dataLimitResult['unit'];

                    Log::info('Eylandoo update data limit conversion', [
                        'input_bytes' => $dataLimitBytes,
                        'output_value' => $dataLimitResult['value'],
                        'output_unit' => $dataLimitResult['unit'],
                        'username' => $username,
                    ]);
                }
            }

            // Update expiry date if provided
            if (isset($userData['expire'])) {
                $payload['activation_type'] = 'fixed_date';
                $payload['expiry_date_str'] = date('Y-m-d', $userData['expire']);
            }

            // Update nodes if provided
            if (isset($userData['nodes']) && is_array($userData['nodes'])) {
                $payload['nodes'] = array_map('intval', $userData['nodes']);
            }

            // Update L2TP settings if provided
            if (array_key_exists('enable_l2tp', $userData)) {
                $payload['enable_l2tp'] = (bool) $userData['enable_l2tp'];
                if ($payload['enable_l2tp'] && isset($userData['l2tp_password']) && ! empty($userData['l2tp_password'])) {
                    $payload['l2tp_password'] = (string) $userData['l2tp_password'];
                }
            }

            // Update Cisco settings if provided
            if (array_key_exists('enable_cisco', $userData)) {
                $payload['enable_cisco'] = (bool) $userData['enable_cisco'];
                if ($payload['enable_cisco'] && isset($userData['cisco_password']) && ! empty($userData['cisco_password'])) {
                    $payload['cisco_password'] = (string) $userData['cisco_password'];
                }
            }

            // Only send request if there's something to update
            if (empty($payload)) {
                return true;
            }

            $encodedUsername = rawurlencode($username);
            $response = $this->client()->put($this->baseUrl."/api/v1/users/{$encodedUsername}", $payload);

            Log::info('Eylandoo Update User Response:', $response->json() ?? ['raw' => $response->body()]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Eylandoo Update User Exception:', ['message' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Extract user status from Eylandoo API response
     * Handles multiple response shapes for robustness
     *
     * @param  array  $userJson  User data from API
     * @return string|null 'active', 'disabled', or null if status cannot be determined
     */
    private function extractUserStatus(array $userJson): ?string
    {
        // Try userJson['data']['status'] (string)
        if (isset($userJson['data']['status']) && is_string($userJson['data']['status'])) {
            $status = strtolower(trim($userJson['data']['status']));
            if (in_array($status, ['active', 'disabled'])) {
                return $status;
            }
        }

        // Try userJson['status'] (string 'active'/'disabled')
        if (isset($userJson['status']) && is_string($userJson['status'])) {
            $status = strtolower(trim($userJson['status']));
            if (in_array($status, ['active', 'disabled'])) {
                return $status;
            }
        }

        // Try userJson['data']['is_active'] (boolean)
        if (isset($userJson['data']['is_active']) && is_bool($userJson['data']['is_active'])) {
            return $userJson['data']['is_active'] ? 'active' : 'disabled';
        }

        // Try userJson['is_active'] (boolean)
        if (isset($userJson['is_active']) && is_bool($userJson['is_active'])) {
            return $userJson['is_active'] ? 'active' : 'disabled';
        }

        // Status not found or unrecognized
        return null;
    }

    /**
     * Enable a user
     *
     * @param  string  $username  Username to enable
     * @return bool Success status
     */
    public function enableUser(string $username): bool
    {
        try {
            // First check current status
            $encodedUsername = rawurlencode($username);
            $getUserUrl = $this->baseUrl."/api/v1/users/{$encodedUsername}";

            $user = $this->getUser($username);

            if (! $user) {
                Log::warning('Eylandoo enableUser failed: user not found', [
                    'action' => 'eylandoo_enable_failed',
                    'username' => $username,
                    'url' => $getUserUrl,
                    'base_url' => $this->baseUrl,
                    'reason' => 'user_not_found',
                ]);

                return false;
            }

            // Use robust status extraction
            $currentStatus = $this->extractUserStatus($user);

            // If status is unknown, log diagnostic info and proceed to toggle (safer)
            if ($currentStatus === null) {
                Log::warning('Eylandoo enableUser: unknown status format, proceeding to toggle', [
                    'action' => 'eylandoo_enable_toggle',
                    'username' => $username,
                    'url' => $getUserUrl,
                    'base_url' => $this->baseUrl,
                    'available_keys' => array_keys($user),
                    'data_keys' => isset($user['data']) && is_array($user['data']) ? array_keys($user['data']) : null,
                ]);
                // Proceed to toggle to be safe
            } elseif ($currentStatus === 'active') {
                // If already enabled, return success
                Log::info('Eylandoo user already enabled', [
                    'action' => 'eylandoo_enable_success',
                    'username' => $username,
                    'url' => $getUserUrl,
                    'current_status' => $currentStatus,
                    'base_url' => $this->baseUrl,
                    'reason' => 'already_active',
                ]);

                return true;
            }

            // Toggle to enable (when disabled or status unknown)
            $toggleUrl = $this->baseUrl."/api/v1/users/{$encodedUsername}/toggle";

            Log::info('Eylandoo enable: sending toggle request', [
                'action' => 'eylandoo_enable_toggle',
                'username' => $username,
                'url' => $toggleUrl,
                'current_status' => $currentStatus ?? 'unknown',
                'base_url' => $this->baseUrl,
            ]);

            $response = $this->client()->post($toggleUrl);
            $statusCode = $response->status();
            $responseBody = $response->body();
            $responsePreview = strlen($responseBody) > 500 ? substr($responseBody, 0, 500) : $responseBody;

            if ($response->successful()) {
                Log::info('Eylandoo enable toggle succeeded', [
                    'action' => 'eylandoo_enable_response',
                    'username' => $username,
                    'url' => $toggleUrl,
                    'status_code' => $statusCode,
                    'response_preview' => $responsePreview,
                ]);

                return true;
            } else {
                Log::warning('Eylandoo enable toggle failed', [
                    'action' => 'eylandoo_enable_failed',
                    'username' => $username,
                    'url' => $toggleUrl,
                    'status_code' => $statusCode,
                    'response_preview' => $responsePreview,
                ]);

                return false;
            }
        } catch (\Exception $e) {
            Log::error('Eylandoo enable exception', [
                'action' => 'eylandoo_enable_failed',
                'username' => $username,
                'base_url' => $this->baseUrl,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Disable a user
     *
     * @param  string  $username  Username to disable
     * @return bool Success status
     */
    public function disableUser(string $username): bool
    {
        try {
            // First check current status
            $encodedUsername = rawurlencode($username);
            $getUserUrl = $this->baseUrl."/api/v1/users/{$encodedUsername}";

            $user = $this->getUser($username);

            if (! $user) {
                Log::warning('Eylandoo disableUser failed: user not found', [
                    'action' => 'eylandoo_disable_failed',
                    'username' => $username,
                    'url' => $getUserUrl,
                    'base_url' => $this->baseUrl,
                    'reason' => 'user_not_found',
                ]);

                return false;
            }

            // Use robust status extraction
            $currentStatus = $this->extractUserStatus($user);

            // If status is unknown, log diagnostic info and proceed to toggle (safer)
            if ($currentStatus === null) {
                Log::warning('Eylandoo disableUser: unknown status format, proceeding to toggle', [
                    'action' => 'eylandoo_disable_toggle',
                    'username' => $username,
                    'url' => $getUserUrl,
                    'base_url' => $this->baseUrl,
                    'available_keys' => array_keys($user),
                    'data_keys' => isset($user['data']) && is_array($user['data']) ? array_keys($user['data']) : null,
                ]);
                // Proceed to toggle to be safe
            } elseif ($currentStatus === 'disabled') {
                // If already disabled, return success
                Log::info('Eylandoo user already disabled', [
                    'action' => 'eylandoo_disable_success',
                    'username' => $username,
                    'url' => $getUserUrl,
                    'current_status' => $currentStatus,
                    'base_url' => $this->baseUrl,
                    'reason' => 'already_disabled',
                ]);

                return true;
            }

            // Toggle to disable (when active or status unknown)
            $toggleUrl = $this->baseUrl."/api/v1/users/{$encodedUsername}/toggle";

            Log::info('Eylandoo disable: sending toggle request', [
                'action' => 'eylandoo_disable_toggle',
                'username' => $username,
                'url' => $toggleUrl,
                'current_status' => $currentStatus ?? 'unknown',
                'base_url' => $this->baseUrl,
            ]);

            $response = $this->client()->post($toggleUrl);
            $statusCode = $response->status();
            $responseBody = $response->body();
            $responsePreview = strlen($responseBody) > 500 ? substr($responseBody, 0, 500) : $responseBody;

            if ($response->successful()) {
                Log::info('Eylandoo disable toggle succeeded', [
                    'action' => 'eylandoo_disable_response',
                    'username' => $username,
                    'url' => $toggleUrl,
                    'status_code' => $statusCode,
                    'response_preview' => $responsePreview,
                ]);

                return true;
            } else {
                Log::warning('Eylandoo disable toggle failed', [
                    'action' => 'eylandoo_disable_failed',
                    'username' => $username,
                    'url' => $toggleUrl,
                    'status_code' => $statusCode,
                    'response_preview' => $responsePreview,
                ]);

                return false;
            }
        } catch (\Exception $e) {
            Log::error('Eylandoo disable exception', [
                'action' => 'eylandoo_disable_failed',
                'username' => $username,
                'base_url' => $this->baseUrl,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Delete a user
     *
     * @param  string  $username  Username to delete
     * @return bool Success status
     */
    public function deleteUser(string $username): bool
    {
        try {
            $encodedUsername = rawurlencode($username);
            $response = $this->client()->delete($this->baseUrl."/api/v1/users/{$encodedUsername}");

            Log::info('Eylandoo Delete User Response:', $response->json() ?? ['raw' => $response->body()]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Eylandoo Delete User Exception:', ['message' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Reset user traffic usage
     *
     * @param  string  $username  Username to reset
     * @return bool Success status
     */
    public function resetUserUsage(string $username): bool
    {
        try {
            $encodedUsername = rawurlencode($username);
            $response = $this->client()->post($this->baseUrl."/api/v1/users/{$encodedUsername}/reset_traffic");

            Log::info('Eylandoo Reset User Usage Response:', $response->json() ?? ['raw' => $response->body()]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Eylandoo Reset User Usage Exception:', ['message' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Get user subscription details from the dedicated subscription endpoint
     *
     * @param  string  $username  Username to fetch subscription for
     * @return array|null Subscription data or null on failure
     */
    public function getUserSubscription(string $username): ?array
    {
        try {
            $encodedUsername = rawurlencode($username);
            $response = $this->client()->get($this->baseUrl."/api/v1/users/{$encodedUsername}/sub");

            if ($response->successful()) {
                $data = $response->json();
                Log::info('Eylandoo Get User Subscription Response:', ['username' => $username, 'response' => $data]);

                return $data;
            }

            Log::warning('Eylandoo Get User Subscription failed:', ['status' => $response->status(), 'username' => $username]);

            return null;
        } catch (\Exception $e) {
            Log::error('Eylandoo Get User Subscription Exception:', ['message' => $e->getMessage(), 'username' => $username]);

            return null;
        }
    }

    /**
     * Extract subscription URL from the subscription endpoint response
     *
     * @param  array|null  $subResponse  Response from /api/v1/users/{username}/sub endpoint
     * @return string|null The extracted subscription URL or null if not found
     */
    public function extractSubscriptionUrlFromSub(?array $subResponse): ?string
    {
        if (! $subResponse) {
            return null;
        }

        // Try various known response shapes for subscription URL
        $configUrl = null;

        // Shape 1: subResponse.sub_url (production shape)
        if (isset($subResponse['subResponse']['sub_url'])) {
            $configUrl = $subResponse['subResponse']['sub_url'];
        }
        // Shape 2: sub_url at root
        elseif (isset($subResponse['sub_url'])) {
            $configUrl = $subResponse['sub_url'];
        }
        // Shape 3: subscription_url at root
        elseif (isset($subResponse['subscription_url'])) {
            $configUrl = $subResponse['subscription_url'];
        }
        // Shape 4: data.sub_url
        elseif (isset($subResponse['data']['sub_url'])) {
            $configUrl = $subResponse['data']['sub_url'];
        }
        // Shape 5: data.subscription_url
        elseif (isset($subResponse['data']['subscription_url'])) {
            $configUrl = $subResponse['data']['subscription_url'];
        }
        // Shape 6: url field
        elseif (isset($subResponse['url'])) {
            $configUrl = $subResponse['url'];
        }

        return $this->makeAbsoluteUrl($configUrl);
    }

    /**
     * Extract subscription/config URL from API response (various shapes)
     *
     * @param  array  $userApiResponse  API response from Eylandoo
     * @return string|null The extracted URL or null if not found
     */
    public function extractSubscriptionUrl(array $userApiResponse): ?string
    {
        // Try various known response shapes for subscription URL
        $configUrl = null;

        // Shape 1: data.subscription_url
        if (isset($userApiResponse['data']['subscription_url'])) {
            $configUrl = $userApiResponse['data']['subscription_url'];
        }
        // Shape 2: data.users[0].config_url
        elseif (isset($userApiResponse['data']['users'][0]['config_url'])) {
            $configUrl = $userApiResponse['data']['users'][0]['config_url'];
        }
        // Shape 3: data.config_url
        elseif (isset($userApiResponse['data']['config_url'])) {
            $configUrl = $userApiResponse['data']['config_url'];
        }
        // Shape 4: data.users[0].subscription_url
        elseif (isset($userApiResponse['data']['users'][0]['subscription_url'])) {
            $configUrl = $userApiResponse['data']['users'][0]['subscription_url'];
        }

        return $configUrl;
    }

    /**
     * Build absolute subscription URL from API response
     */
    public function buildAbsoluteSubscriptionUrl(array $userApiResponse): string
    {
        $configUrl = $this->extractSubscriptionUrl($userApiResponse) ?? '';

        return $this->makeAbsoluteUrl($configUrl) ?? '';
    }

    /**
     * Convert a relative URL to absolute URL using base hostname
     *
     * @param  string|null  $url  URL to convert (can be relative or absolute)
     * @return string|null Absolute URL or null if input is null/empty
     */
    protected function makeAbsoluteUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        // If the URL is already absolute, return as is
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        // Use nodeHostname if set, otherwise fall back to baseUrl
        $baseHost = ! empty($this->nodeHostname) ? $this->nodeHostname : $this->baseUrl;

        // Ensure exactly one slash between hostname and path
        return rtrim($baseHost, '/').'/'.ltrim($url, '/');
    }

    /**
     * Generate subscription link message
     */
    public function generateSubscriptionLink(array $userApiResponse): string
    {
        $absoluteUrl = $this->buildAbsoluteSubscriptionUrl($userApiResponse);

        return "لینک سابسکریپشن شما (در تمام برنامه‌ها import کنید):\n".$absoluteUrl;
    }

    /**
     * List all available nodes with robust parsing
     * Handles multiple response shapes and provides fallback labels
     *
     * @return array Array of nodes with 'id' and 'name' keys
     */
    public function listNodes(): array
    {
        try {
            $response = $this->client()->get($this->baseUrl.'/api/v1/nodes');

            if (! $response->successful()) {
                Log::warning('Eylandoo List Nodes failed:', ['status' => $response->status()]);

                return [];
            }

            $data = $response->json();

            // Parse nodes from various response shapes
            return $this->parseNodesList($data);
        } catch (\Exception $e) {
            Log::error('Eylandoo List Nodes Exception:', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Parse nodes list from API response with resilient handling
     * Mirrors the robust parsing strategy used for Marzneshin nodes
     *
     * Supported response shapes:
     * - Array of objects: [{id, name?}]
     * - Wrapped object: { data: [{id, name?}], meta?: {...} }
     * - Nested wrapper: { data: { nodes: [{id, name?}] } }
     *
     * @param  mixed  $payload  API response payload
     * @return array Normalized array of nodes with 'id' and 'name'
     */
    protected function parseNodesList($payload): array
    {
        if (! is_array($payload)) {
            Log::warning('Eylandoo parseNodesList: Invalid payload type', [
                'type' => gettype($payload),
            ]);

            return [];
        }

        // Check for success:false flag (treat as empty result)
        if (isset($payload['success']) && $payload['success'] === false) {
            Log::info('Eylandoo parseNodesList: API returned success:false');

            return [];
        }

        // Try to extract nodes array from various wrappers
        $rawNodes = null;

        // Shape 1: Direct array of nodes (least common but possible)
        if ($this->isNodeArray($payload)) {
            $rawNodes = $payload;
            Log::debug('Eylandoo parseNodesList: Using direct array shape');
        }
        // Shape 2: Nested wrapper - data.nodes (most common)
        elseif (isset($payload['data']['nodes']) && is_array($payload['data']['nodes'])) {
            $rawNodes = $payload['data']['nodes'];
            Log::debug('Eylandoo parseNodesList: Using data.nodes shape');
        }
        // Shape 3: Wrapped in data (alternative shape)
        elseif (isset($payload['data']) && is_array($payload['data']) && $this->isNodeArray($payload['data'])) {
            $rawNodes = $payload['data'];
            Log::debug('Eylandoo parseNodesList: Using data array shape');
        }
        // Shape 4: Wrapped in items (paginated response)
        elseif (isset($payload['items']) && is_array($payload['items'])) {
            $rawNodes = $payload['items'];
            Log::debug('Eylandoo parseNodesList: Using items array shape');
        }
        // Shape 5: Wrapped in nodes key at root
        elseif (isset($payload['nodes']) && is_array($payload['nodes'])) {
            $rawNodes = $payload['nodes'];
            Log::debug('Eylandoo parseNodesList: Using nodes array shape');
        }

        if ($rawNodes === null) {
            Log::warning('Eylandoo parseNodesList: No nodes array found in response', [
                'available_keys' => array_keys($payload),
            ]);

            return [];
        }

        // Parse each node with resilient label fallback
        $nodes = [];
        foreach ($rawNodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            // Extract ID (required)
            $id = $node['id'] ?? null;
            if ($id === null) {
                Log::warning('Eylandoo parseNodesList: Node missing ID', [
                    'node_keys' => array_keys($node),
                ]);

                continue;
            }

            // Extract name with multiple fallbacks
            // Priority: name > title > host > hostname > address > id
            $name = $node['name'] ?? $node['title'] ?? $node['host'] ?? $node['hostname'] ?? $node['address'] ?? null;

            if ($name === null || $name === '') {
                // Last resort: use ID as the name
                $name = (string) $id;
                Log::info("Eylandoo parseNodesList: Using ID as name for node {$id}", [
                    'node_id' => $id,
                    'available_keys' => array_keys($node),
                ]);
            }

            $nodes[] = [
                'id' => (int) $id, // Ensure ID is integer for consistency
                'name' => (string) $name, // Ensure name is string
            ];
        }

        if (empty($nodes)) {
            Log::info('Eylandoo parseNodesList: No valid nodes found in response');
        } else {
            Log::debug('Eylandoo parseNodesList: Successfully parsed '.count($nodes).' nodes');
        }

        return $nodes;
    }

    /**
     * Check if array appears to be a node array (has elements with 'id' key)
     *
     * @param  array  $arr  Array to check
     * @return bool True if appears to be node array
     */
    protected function isNodeArray(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }

        // Check if first element has an 'id' key
        $first = reset($arr);

        return is_array($first) && isset($first['id']);
    }

    /**
     * List all users
     *
     * @return array Array of users with normalized used_traffic field
     */
    public function listUsers(): array
    {
        try {
            $response = $this->client()->get($this->baseUrl.'/api/v1/users/list_all');

            if ($response->successful()) {
                $data = $response->json();
                $users = $data['data']['users'] ?? [];

                // Add normalized 'used_traffic' and 'data_used' to each user
                return array_map(function ($user) {
                    // Parse usage from the user data
                    $parseResult = $this->parseUsageFromResponse($user);
                    $usageBytes = $parseResult['bytes'] ?? 0;

                    // Add used_traffic and data_used at the top level
                    // Both set to the same value for Eylandoo (Marzban/Marzneshin compatibility)
                    $user['used_traffic'] = $usageBytes;
                    $user['data_used'] = $usageBytes;

                    return $user;
                }, $users);
            }

            Log::warning('Eylandoo List Users failed:', ['status' => $response->status()]);

            return [];
        } catch (\Exception $e) {
            Log::error('Eylandoo List Users Exception:', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * List configs/users created by a specific admin (sub-admin)
     */
    public function listConfigsByAdmin(string $adminUsername): array
    {
        try {
            $response = $this->client()->get($this->baseUrl.'/api/v1/users/list_all');

            if ($response->successful()) {
                $data = $response->json();
                $users = $data['data']['users'] ?? [];

                // Map users to our expected format
                $configs = array_map(function ($user) {
                    // Parse usage using the same robust parser as getUser()
                    $parseResult = $this->parseUsageFromResponse($user);
                    $usageBytes = $parseResult['bytes'] ?? 0;

                    return [
                        'id' => $user['id'] ?? null,
                        'username' => $user['username'],
                        'status' => $user['status'] ?? 'active',
                        'used_traffic' => $usageBytes,
                        'data_used' => $usageBytes,
                        'data_limit' => $user['data_limit'] ?? null,
                        'admin' => $user['sub_admin'] ?? null,
                        'owner_username' => $user['sub_admin'] ?? null,
                    ];
                }, $users);

                // Filter by admin username
                return array_values(array_filter($configs, function ($config) use ($adminUsername) {
                    return $config['admin'] === $adminUsername;
                }));
            }

            Log::warning('Eylandoo List Configs by Admin failed:', ['status' => $response->status()]);

            return [];
        } catch (\Exception $e) {
            Log::error('Eylandoo List Configs by Admin Exception:', ['message' => $e->getMessage()]);

            return [];
        }
    }
}
