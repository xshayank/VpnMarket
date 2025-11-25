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
        $token = $this->extractBearerToken($request);

        if (!$token) {
            return $this->errorResponse('API key is required', 401);
        }

        // Find API key by hash
        $keyHash = ApiKey::hashKey($token);
        $apiKey = ApiKey::where('key_hash', $keyHash)->first();

        if (!$apiKey) {
            return $this->errorResponse('Invalid API key', 401);
        }

        // Check if key is valid (not revoked, not expired)
        if (!$apiKey->isValid()) {
            $reason = $apiKey->revoked ? 'API key has been revoked' : 'API key has expired';
            return $this->errorResponse($reason, 401);
        }

        // Check IP whitelist
        if (!$apiKey->isIpAllowed($request->ip())) {
            ApiAuditLog::logAction(
                $apiKey->user_id,
                $apiKey->id,
                'auth.ip_denied',
                null,
                null,
                ['denied_ip' => $request->ip()]
            );
            return $this->errorResponse('IP address not allowed', 403);
        }

        // Check if reseller has API enabled
        $user = $apiKey->user;
        if (!$user || !$user->reseller || !$user->reseller->api_enabled) {
            return $this->errorResponse('API access is not enabled for this account', 403);
        }

        // Check if reseller is active
        if (!$user->reseller->isActive()) {
            return $this->errorResponse('Reseller account is not active', 403);
        }

        // Check required scope if specified
        if ($scope !== null && !$apiKey->hasScope($scope)) {
            ApiAuditLog::logAction(
                $apiKey->user_id,
                $apiKey->id,
                'auth.scope_denied',
                null,
                null,
                ['required_scope' => $scope, 'key_scopes' => $apiKey->scopes]
            );
            return $this->errorResponse("Missing required scope: {$scope}", 403);
        }

        // Update last used timestamp
        $apiKey->touchLastUsed();

        // Attach API key and user to request for use in controllers
        $request->attributes->set('api_key', $apiKey);
        $request->attributes->set('api_user', $user);
        $request->attributes->set('api_reseller', $user->reseller);

        return $next($request);
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
     * Return a JSON error response.
     */
    protected function errorResponse(string $message, int $status): Response
    {
        return response()->json([
            'error' => true,
            'message' => $message,
        ], $status);
    }
}
