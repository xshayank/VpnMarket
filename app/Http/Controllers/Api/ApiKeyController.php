<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiAuditLog;
use App\Models\ApiKey;
use App\Models\Panel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        if (! $user || ! $user->reseller || ! $user->reseller->api_enabled) {
            return response()->json([
                'error' => true,
                'message' => 'API access is not enabled for your account',
            ], 403);
        }

        $keys = $user->apiKeys()
            ->with('defaultPanel:id,name,panel_type')
            ->select([
                'id', 'name', 'scopes', 'api_style', 'default_panel_id',
                'rate_limit_per_minute', 'ip_whitelist', 'expires_at',
                'last_used_at', 'revoked', 'created_at',
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $keys->map(function ($key) {
                return [
                    'id' => $key->id,
                    'name' => $key->name,
                    'api_style' => $key->api_style,
                    'style_label' => $key->style_label,
                    'default_panel' => $key->defaultPanel ? [
                        'id' => $key->defaultPanel->id,
                        'name' => $key->defaultPanel->name,
                        'panel_type' => $key->defaultPanel->panel_type,
                    ] : null,
                    'scopes' => $key->scopes,
                    'rate_limit_per_minute' => $key->rate_limit_per_minute,
                    'ip_whitelist' => $key->ip_whitelist,
                    'expires_at' => $key->expires_at?->toIso8601String(),
                    'last_used_at' => $key->last_used_at?->toIso8601String(),
                    'revoked' => $key->revoked,
                    'created_at' => $key->created_at->toIso8601String(),
                ];
            }),
        ]);
    }

    /**
     * Create a new API key.
     * This endpoint requires session authentication (not API key auth).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->reseller || ! $user->reseller->api_enabled) {
            return response()->json([
                'error' => true,
                'message' => 'API access is not enabled for your account',
            ], 403);
        }

        // Build validation rules based on API style
        $rules = [
            'name' => 'required|string|max:100',
            'api_style' => 'required|string|in:'.implode(',', ApiKey::ALL_STYLES),
            'scopes' => 'required|array|min:1',
            'scopes.*' => 'string|in:'.implode(',', ApiKey::ALL_SCOPES),
            'expires_at' => 'nullable|date|after:now',
            'ip_whitelist' => 'nullable|array',
            'ip_whitelist.*' => 'ip',
            'rate_limit_per_minute' => 'nullable|integer|min:1|max:1000',
        ];

        // If Marzneshin style is selected, require default_panel_id
        if ($request->input('api_style') === ApiKey::STYLE_MARZNESHIN) {
            $rules['default_panel_id'] = 'required|exists:panels,id';
        } else {
            $rules['default_panel_id'] = 'nullable|exists:panels,id';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // If panel is specified, verify reseller has access
        if ($request->filled('default_panel_id')) {
            $panelId = $request->input('default_panel_id');
            $reseller = $user->reseller;

            $hasAccess = $reseller->hasPanelAccess($panelId)
                || $reseller->panel_id == $panelId
                || $reseller->primary_panel_id == $panelId;

            if (! $hasAccess) {
                return response()->json([
                    'error' => true,
                    'message' => 'You do not have access to the selected panel',
                ], 403);
            }
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
            'api_style' => $request->input('api_style'),
            'default_panel_id' => $request->input('default_panel_id'),
            'rate_limit_per_minute' => $request->input('rate_limit_per_minute', 60),
            'ip_whitelist' => $request->input('ip_whitelist'),
            'expires_at' => $request->input('expires_at'),
            'revoked' => false,
        ]);

        // Log the key creation
        ApiAuditLog::logAction(
            $user->id,
            $apiKey->id,
            'api_key.created',
            'api_key',
            $apiKey->id,
            [
                'key_name' => $apiKey->name,
                'api_style' => $apiKey->api_style,
                'default_panel_id' => $apiKey->default_panel_id,
                'scopes' => $apiKey->scopes,
            ]
        );

        // Return the plaintext key only once
        return response()->json([
            'data' => [
                'id' => $apiKey->id,
                'api_key' => $plaintextKey,
                'name' => $apiKey->name,
                'api_style' => $apiKey->api_style,
                'style_label' => $apiKey->style_label,
                'default_panel_id' => $apiKey->default_panel_id,
                'scopes' => $apiKey->scopes,
                'rate_limit_per_minute' => $apiKey->rate_limit_per_minute,
                'ip_whitelist' => $apiKey->ip_whitelist,
                'expires_at' => $apiKey->expires_at?->toIso8601String(),
                'created_at' => $apiKey->created_at->toIso8601String(),
            ],
            'message' => 'API key created. Save this key securely - it will not be shown again.',
        ], 201);
    }

    /**
     * Update an API key (style, panel, rate limit, etc.).
     * This endpoint requires session authentication (not API key auth).
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->reseller || ! $user->reseller->api_enabled) {
            return response()->json([
                'error' => true,
                'message' => 'API access is not enabled for your account',
            ], 403);
        }

        $apiKey = ApiKey::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (! $apiKey) {
            return response()->json([
                'error' => true,
                'message' => 'API key not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:100',
            'scopes' => 'nullable|array|min:1',
            'scopes.*' => 'string|in:'.implode(',', ApiKey::ALL_SCOPES),
            'default_panel_id' => 'nullable|exists:panels,id',
            'rate_limit_per_minute' => 'nullable|integer|min:1|max:1000',
            'ip_whitelist' => 'nullable|array',
            'ip_whitelist.*' => 'ip',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // If panel is being updated, verify reseller has access
        if ($request->filled('default_panel_id')) {
            $panelId = $request->input('default_panel_id');
            $reseller = $user->reseller;

            $hasAccess = $reseller->hasPanelAccess($panelId)
                || $reseller->panel_id == $panelId
                || $reseller->primary_panel_id == $panelId;

            if (! $hasAccess) {
                return response()->json([
                    'error' => true,
                    'message' => 'You do not have access to the selected panel',
                ], 403);
            }
        }

        $updateData = array_filter([
            'name' => $request->input('name'),
            'scopes' => $request->input('scopes'),
            'default_panel_id' => $request->input('default_panel_id'),
            'rate_limit_per_minute' => $request->input('rate_limit_per_minute'),
            'ip_whitelist' => $request->input('ip_whitelist'),
        ], fn ($v) => $v !== null);

        $apiKey->update($updateData);

        // Log the update
        ApiAuditLog::logAction(
            $user->id,
            $apiKey->id,
            'api_key.updated',
            'api_key',
            $apiKey->id,
            ['updated_fields' => array_keys($updateData)]
        );

        return response()->json([
            'data' => [
                'id' => $apiKey->id,
                'name' => $apiKey->name,
                'api_style' => $apiKey->api_style,
                'style_label' => $apiKey->style_label,
                'default_panel_id' => $apiKey->default_panel_id,
                'scopes' => $apiKey->scopes,
                'rate_limit_per_minute' => $apiKey->rate_limit_per_minute,
                'ip_whitelist' => $apiKey->ip_whitelist,
                'expires_at' => $apiKey->expires_at?->toIso8601String(),
            ],
            'message' => 'API key updated successfully',
        ]);
    }

    /**
     * Rotate (regenerate) an API key.
     * This endpoint requires session authentication (not API key auth).
     */
    public function rotate(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->reseller || ! $user->reseller->api_enabled) {
            return response()->json([
                'error' => true,
                'message' => 'API access is not enabled for your account',
            ], 403);
        }

        $apiKey = ApiKey::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (! $apiKey) {
            return response()->json([
                'error' => true,
                'message' => 'API key not found',
            ], 404);
        }

        if ($apiKey->revoked) {
            return response()->json([
                'error' => true,
                'message' => 'Cannot rotate a revoked API key',
            ], 400);
        }

        // Generate new key
        $plaintextKey = ApiKey::generateKeyString();
        $apiKey->update([
            'key_hash' => ApiKey::hashKey($plaintextKey),
            'requests_this_minute' => 0,
            'rate_limit_reset_at' => null,
        ]);

        // Log the rotation
        ApiAuditLog::logAction(
            $user->id,
            $apiKey->id,
            'api_key.rotated',
            'api_key',
            $apiKey->id,
            ['key_name' => $apiKey->name]
        );

        return response()->json([
            'data' => [
                'id' => $apiKey->id,
                'api_key' => $plaintextKey,
                'name' => $apiKey->name,
            ],
            'message' => 'API key rotated. Save this key securely - it will not be shown again.',
        ]);
    }

    /**
     * Revoke an API key.
     * This endpoint requires session authentication (not API key auth).
     */
    public function revoke(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->reseller || ! $user->reseller->api_enabled) {
            return response()->json([
                'error' => true,
                'message' => 'API access is not enabled for your account',
            ], 403);
        }

        $apiKey = ApiKey::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (! $apiKey) {
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
     * Delete an API key permanently.
     * This endpoint requires session authentication (not API key auth).
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->reseller || ! $user->reseller->api_enabled) {
            return response()->json([
                'error' => true,
                'message' => 'API access is not enabled for your account',
            ], 403);
        }

        $apiKey = ApiKey::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (! $apiKey) {
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

    /**
     * Get analytics for an API key.
     */
    public function analytics(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->reseller || ! $user->reseller->api_enabled) {
            return response()->json([
                'error' => true,
                'message' => 'API access is not enabled for your account',
            ], 403);
        }

        $apiKey = ApiKey::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (! $apiKey) {
            return response()->json([
                'error' => true,
                'message' => 'API key not found',
            ], 404);
        }

        $hours = $request->input('hours', 24);
        $analytics = ApiAuditLog::getKeyAnalytics($apiKey->id, $hours);

        return response()->json([
            'data' => $analytics,
        ]);
    }

    /**
     * Get available panels for API key creation.
     */
    public function availablePanels(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->reseller || ! $user->reseller->api_enabled) {
            return response()->json([
                'error' => true,
                'message' => 'API access is not enabled for your account',
            ], 403);
        }

        $reseller = $user->reseller;

        // Get panels the reseller has access to
        $panels = $reseller->panels()->where('is_active', true)->get();

        // If no panels via pivot, check primary panel
        if ($panels->isEmpty() && $reseller->primary_panel_id) {
            $primaryPanel = Panel::where('id', $reseller->primary_panel_id)
                ->where('is_active', true)
                ->first();

            if ($primaryPanel) {
                $panels = collect([$primaryPanel]);
            }
        }

        return response()->json([
            'data' => $panels->map(function ($panel) {
                return [
                    'id' => $panel->id,
                    'name' => $panel->name,
                    'panel_type' => $panel->panel_type,
                ];
            }),
        ]);
    }
}
