<?php

namespace App\Http\Middleware;

use App\Models\ApiAuditLog;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $startTime = microtime(true);
        $token = $this->extractToken($request);

        if (!$token) {
            return $this->errorResponse('API key is required', 401, $request);
        }

        // Find API key by hash
        $keyHash = ApiKey::hashKey($token);
        $apiKey = ApiKey::where('key_hash', $keyHash)->first();

        if (!$apiKey) {
            return $this->errorResponse('Invalid API key', 401, $request);
        }

        // Check if key is valid (not revoked, not expired)
        if (!$apiKey->isValid()) {
            $reason = $apiKey->revoked ? 'API key has been revoked' : 'API key has expired';
            return $this->errorResponse($reason, 401, $request, $apiKey);
        }

        // Check IP whitelist
        if (!$apiKey->isIpAllowed($request->ip())) {
            ApiAuditLog::logRequest(
                $apiKey->user_id,
                $apiKey->id,
                'auth.ip_denied',
                [
                    'api_style' => $apiKey->api_style,
                    'response_status' => 403,
                    'metadata' => ['denied_ip' => $request->ip()],
                ]
            );
            return $this->errorResponse('IP address not allowed', 403, $request, $apiKey);
        }

        // Check rate limiting
        if ($apiKey->isRateLimited()) {
            ApiAuditLog::logRequest(
                $apiKey->user_id,
                $apiKey->id,
                'auth.rate_limited',
                [
                    'api_style' => $apiKey->api_style,
                    'response_status' => 429,
                    'rate_limited' => true,
                ]
            );

            $retryAfter = $apiKey->rate_limit_reset_at 
                ? $apiKey->rate_limit_reset_at->diffInSeconds(now()) 
                : 60;

            return $this->errorResponse(
                'Rate limit exceeded. Please try again later.',
                429,
                $request,
                $apiKey,
                ['Retry-After' => $retryAfter]
            );
        }

        // Check if reseller has API enabled
        $user = $apiKey->user;
        if (!$user || !$user->reseller || !$user->reseller->api_enabled) {
            return $this->errorResponse('API access is not enabled for this account', 403, $request, $apiKey);
        }

        // Check if reseller is active
        if (!$user->reseller->isActive()) {
            return $this->errorResponse('Reseller account is not active', 403, $request, $apiKey);
        }

        // Check required scope if specified
        if ($scope !== null && !$apiKey->hasScope($scope)) {
            ApiAuditLog::logRequest(
                $apiKey->user_id,
                $apiKey->id,
                'auth.scope_denied',
                [
                    'api_style' => $apiKey->api_style,
                    'response_status' => 403,
                    'metadata' => ['required_scope' => $scope, 'key_scopes' => $apiKey->scopes],
                ]
            );
            return $this->errorResponse("Missing required scope: {$scope}", 403, $request, $apiKey);
        }

        // Update last used timestamp and increment request count
        $apiKey->touchLastUsed();
        $apiKey->incrementRequestCount();

        // Attach API key and user to request for use in controllers
        $request->attributes->set('api_key', $apiKey);
        $request->attributes->set('api_user', $user);
        $request->attributes->set('api_reseller', $user->reseller);
        $request->attributes->set('api_start_time', $startTime);

        return $next($request);
    }

    /**
     * Extract token from various sources.
     * Supports: Bearer token, X-API-KEY header, Basic auth (username/password as key)
     */
    protected function extractToken(Request $request): ?string
    {
        // 1. Try Bearer token
        $token = $this->extractBearerToken($request);
        if ($token) {
            return $token;
        }

        // 2. Try X-API-KEY header
        $apiKeyHeader = $request->header('X-API-KEY');
        if ($apiKeyHeader) {
            return $apiKeyHeader;
        }

        // 3. Try Basic auth (for Marzneshin compatibility - key as both username and password)
        $authHeader = $request->header('Authorization', '');
        if (str_starts_with($authHeader, 'Basic ')) {
            $credentials = base64_decode(substr($authHeader, 6));
            if ($credentials !== false) {
                $parts = explode(':', $credentials, 2);
                // Use password if it looks like an API key, otherwise use username
                if (count($parts) === 2) {
                    $username = $parts[0];
                    $password = $parts[1];
                    // Prefer the one that looks like a VPN Market API key
                    if (str_starts_with($password, 'vpnm_')) {
                        return $password;
                    }
                    if (str_starts_with($username, 'vpnm_')) {
                        return $username;
                    }
                    // If neither looks like our key, try password first
                    return $password ?: $username;
                }
                return $credentials;
            }
        }

        return null;
    }

    /**
     * Extract the Bearer token from the Authorization header.
     */
    protected function extractBearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization', '');
        
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return null;
    }

    /**
     * Return a JSON error response with style-appropriate format.
     */
    protected function errorResponse(
        string $message,
        int $status,
        Request $request,
        ?ApiKey $apiKey = null,
        array $headers = []
    ): Response {
        // Determine response format based on API key style or request path
        $useMarzneshinFormat = false;

        if ($apiKey && $apiKey->isMarzneshinStyle()) {
            $useMarzneshinFormat = true;
        } elseif (str_contains($request->path(), 'marzneshin') || str_contains($request->path(), 'admins/token')) {
            $useMarzneshinFormat = true;
        }

        if ($useMarzneshinFormat) {
            $body = ['detail' => $message];
        } else {
            $body = [
                'error' => true,
                'message' => $message,
            ];
        }

        $response = response()->json($body, $status);

        foreach ($headers as $key => $value) {
            $response->header($key, $value);
        }

        return $response;
    }
}
