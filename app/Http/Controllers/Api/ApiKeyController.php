<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiAuditLog;
use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class ApiKeyController extends Controller
{
    /**
     * List API keys for the authenticated user.
     * This endpoint requires session authentication (not API key auth).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->reseller || !$user->reseller->api_enabled) {
            return response()->json([
                'error' => true,
                'message' => 'API access is not enabled for your account',
            ], 403);
        }

        $keys = $user->apiKeys()
            ->select([
                'id',
                'name',
                'scopes',
                'ip_whitelist',
                'expires_at',
                'last_used_at',
                'revoked',
                'api_style',
                'default_panel_id',
                'created_at',
            ])
            ->with('defaultPanel:id,name,panel_type')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $keys,
        ]);
    }

    /**
     * Create a new API key.
     * This endpoint requires session authentication (not API key auth).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->reseller || !$user->reseller->api_enabled) {
            return response()->json([
                'error' => true,
                'message' => 'API access is not enabled for your account',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'scopes' => 'required|array|min:1',
            'scopes.*' => 'string|in:' . implode(',', ApiKey::ALL_SCOPES),
            'expires_at' => 'nullable|date|after:now',
            'ip_whitelist' => 'nullable|array',
            'ip_whitelist.*' => 'ip',
            'api_style' => ['required', 'string', Rule::in([ApiKey::STYLE_FALCO, ApiKey::STYLE_MARZNESHIN])],
            'default_panel_id' => [
                Rule::requiredIf(fn () => $request->input('api_style') === ApiKey::STYLE_MARZNESHIN),
                'nullable',
                'integer',
                Rule::exists('panels', 'id'),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Limit number of active (non-revoked) keys per user
        $activeKeysCount = $user->apiKeys()->where('revoked', false)->count();
        $maxKeys = config('api.max_keys_per_user', 10);

        if ($activeKeysCount >= $maxKeys) {
            return response()->json([
                'error' => true,
                'message' => "Maximum number of API keys ({$maxKeys}) reached. Revoke an existing key to create a new one.",
            ], 429);
        }

        // Generate the plaintext key
        $plaintextKey = ApiKey::generateKeyString();

        // Create the API key record with hashed key
        $apiKey = ApiKey::create([
            'user_id' => $user->id,
            'name' => $request->input('name'),
            'key_hash' => ApiKey::hashKey($plaintextKey),
            'scopes' => $request->input('scopes'),
            'ip_whitelist' => $request->input('ip_whitelist'),
            'expires_at' => $request->input('expires_at'),
            'revoked' => false,
            'api_style' => $request->input('api_style'),
            'default_panel_id' => $request->input('api_style') === ApiKey::STYLE_MARZNESHIN
                ? $request->input('default_panel_id')
                : null,
        ]);

        // Log the key creation
        ApiAuditLog::logAction(
            $user->id,
            $apiKey->id,
            'api_key.created',
            'api_key',
            $apiKey->id,
            ['key_name' => $apiKey->name, 'scopes' => $apiKey->scopes]
        );

        // Return the plaintext key only once
        return response()->json([
            'data' => [
                'id' => $apiKey->id,
                'api_key' => $plaintextKey,
                'name' => $apiKey->name,
                'scopes' => $apiKey->scopes,
                'ip_whitelist' => $apiKey->ip_whitelist,
                'expires_at' => $apiKey->expires_at?->toIso8601String(),
                'created_at' => $apiKey->created_at->toIso8601String(),
                'api_style' => $apiKey->api_style,
                'default_panel_id' => $apiKey->default_panel_id,
            ],
            'message' => 'API key created. Save this key securely - it will not be shown again.',
        ], 201);
    }

    /**
     * Update an API key's metadata.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->reseller || !$user->reseller->api_enabled) {
            return response()->json([
                'error' => true,
                'message' => 'API access is not enabled for your account',
            ], 403);
        }

        $apiKey = ApiKey::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$apiKey) {
            return response()->json([
                'error' => true,
                'message' => 'API key not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:100',
            'scopes' => 'sometimes|required|array|min:1',
            'scopes.*' => 'string|in:' . implode(',', ApiKey::ALL_SCOPES),
            'expires_at' => 'nullable|date|after:now',
            'ip_whitelist' => 'nullable|array',
            'ip_whitelist.*' => 'ip',
            'api_style' => ['sometimes', 'required', 'string', Rule::in([ApiKey::STYLE_FALCO, ApiKey::STYLE_MARZNESHIN])],
            'default_panel_id' => [
                Rule::requiredIf(fn () => $request->input('api_style') === ApiKey::STYLE_MARZNESHIN),
                'nullable',
                'integer',
                Rule::exists('panels', 'id'),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = $validator->validated();

        if (($payload['api_style'] ?? $apiKey->api_style) === ApiKey::STYLE_FALCO) {
            $payload['default_panel_id'] = null;
        }

        $apiKey->update($payload);

        ApiAuditLog::logAction(
            $user->id,
            $apiKey->id,
            'api_key.updated',
            'api_key',
            $apiKey->id,
            [
                'key_name' => $apiKey->name,
                'api_style' => $apiKey->api_style,
                'default_panel_id' => $apiKey->default_panel_id,
            ]
        );

        return response()->json([
            'data' => $apiKey->fresh(['defaultPanel:id,name,panel_type'])->only([
                'id',
                'name',
                'scopes',
                'ip_whitelist',
                'expires_at',
                'revoked',
                'api_style',
                'default_panel_id',
            ]),
            'message' => 'API key updated successfully',
        ]);
    }

    /**
     * Revoke an API key.
     * This endpoint requires session authentication (not API key auth).
     */
    public function revoke(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->reseller || !$user->reseller->api_enabled) {
            return response()->json([
                'error' => true,
                'message' => 'API access is not enabled for your account',
            ], 403);
        }

        $apiKey = ApiKey::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$apiKey) {
            return response()->json([
                'error' => true,
                'message' => 'API key not found',
            ], 404);
        }

        if ($apiKey->revoked) {
            return response()->json([
                'error' => true,
                'message' => 'API key is already revoked',
            ], 400);
        }

        $apiKey->revoke();

        // Log the revocation
        ApiAuditLog::logAction(
            $user->id,
            $apiKey->id,
            'api_key.revoked',
            'api_key',
            $apiKey->id,
            ['key_name' => $apiKey->name]
        );

        return response()->json([
            'message' => 'API key revoked successfully',
        ]);
    }

    /**
     * Rotate an API key (re-issue the secret while keeping metadata).
     */
    public function rotate(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->reseller || !$user->reseller->api_enabled) {
            return response()->json([
                'error' => true,
                'message' => 'API access is not enabled for your account',
            ], 403);
        }

        $apiKey = ApiKey::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$apiKey) {
            return response()->json([
                'error' => true,
                'message' => 'API key not found',
            ], 404);
        }

        $plaintextKey = ApiKey::generateKeyString();
        $apiKey->update([
            'key_hash' => ApiKey::hashKey($plaintextKey),
            'revoked' => false,
            'last_used_at' => null,
        ]);

        ApiAuditLog::logAction(
            $user->id,
            $apiKey->id,
            'api_key.rotated',
            'api_key',
            $apiKey->id,
            [
                'key_name' => $apiKey->name,
                'api_style' => $apiKey->api_style,
            ]
        );

        return response()->json([
            'data' => [
                'id' => $apiKey->id,
                'api_key' => $plaintextKey,
                'api_style' => $apiKey->api_style,
                'default_panel_id' => $apiKey->default_panel_id,
            ],
            'message' => 'API key rotated. Save this key securely - it will not be shown again.',
        ]);
    }

    /**
     * Delete an API key permanently.
     * This endpoint requires session authentication (not API key auth).
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->reseller || !$user->reseller->api_enabled) {
            return response()->json([
                'error' => true,
                'message' => 'API access is not enabled for your account',
            ], 403);
        }

        $apiKey = ApiKey::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$apiKey) {
            return response()->json([
                'error' => true,
                'message' => 'API key not found',
            ], 404);
        }

        // Log before deletion
        ApiAuditLog::logAction(
            $user->id,
            $apiKey->id,
            'api_key.deleted',
            'api_key',
            $apiKey->id,
            ['key_name' => $apiKey->name]
        );

        $apiKey->delete();

        return response()->json([
            'message' => 'API key deleted successfully',
        ]);
    }
}
