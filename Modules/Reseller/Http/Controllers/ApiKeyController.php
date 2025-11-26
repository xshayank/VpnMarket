<?php

namespace Modules\Reseller\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ApiAuditLog;
use App\Models\ApiKey;
use App\Models\Panel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ApiKeyController extends Controller
{
    /**
     * Display API keys management page.
     */
    public function index(Request $request)
    {
        $reseller = $request->user()->reseller;

        if (! $reseller) {
            return redirect()->route('reseller.dashboard')
                ->with('error', 'Reseller account not found.');
        }

        if (! $reseller->api_enabled) {
            return redirect()->route('reseller.dashboard')
                ->with('error', 'API access is not enabled for your account. Please contact admin.');
        }

        $keys = $request->user()->apiKeys()
            ->orderBy('created_at', 'desc')
            ->get();

        // Compute available panels for the reseller
        $panels = $reseller->panels()->where('is_active', true)->get();

        // Fall back to primary panel if no panels via pivot
        if ($panels->isEmpty() && $reseller->primary_panel_id) {
            $primaryPanel = Panel::where('id', $reseller->primary_panel_id)
                ->where('is_active', true)
                ->first();

            if ($primaryPanel) {
                $panels = collect([$primaryPanel]);
            }
        }

        return view('reseller::api-keys.index', [
            'reseller' => $reseller,
            'keys' => $keys,
            'scopes' => ApiKey::ALL_SCOPES,
            'styles' => ApiKey::ALL_STYLES ?? [],
            'panels' => $panels,
        ]);
    }

    /**
     * Store a new API key.
     */
    public function store(Request $request)
    {
        $reseller = $request->user()->reseller;

        if (! $reseller || ! $reseller->api_enabled) {
            return back()->with('error', 'API access is not enabled for your account.');
        }

        // Build validation rules based on API style
        $rules = [
            'name' => 'required|string|max:100',
            'api_style' => 'required|string|in:'.implode(',', ApiKey::ALL_STYLES),
            'scopes' => 'required|array|min:1',
            'scopes.*' => 'string|in:'.implode(',', ApiKey::ALL_SCOPES),
            'expires_at' => 'nullable|date|after:now',
            'ip_whitelist' => 'nullable|string|max:500',
        ];

        // If Marzneshin style is selected, require default_panel_id
        if ($request->input('api_style') === ApiKey::STYLE_MARZNESHIN) {
            $rules['default_panel_id'] = 'required|exists:panels,id';
        } else {
            $rules['default_panel_id'] = 'nullable|exists:panels,id';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        // If panel is specified, verify reseller has access
        if ($request->filled('default_panel_id')) {
            $panelId = $request->input('default_panel_id');

            $hasAccess = $reseller->hasPanelAccess($panelId)
                || $reseller->panel_id == $panelId
                || $reseller->primary_panel_id == $panelId;

            if (! $hasAccess) {
                return back()->with('error', 'You do not have access to the selected panel.')->withInput();
            }
        }

        // Limit number of active keys
        $activeKeysCount = $request->user()->apiKeys()->where('revoked', false)->count();
        $maxKeys = config('api.max_keys_per_user', 10);

        if ($activeKeysCount >= $maxKeys) {
            return back()->with('error', "Maximum number of API keys ({$maxKeys}) reached. Revoke an existing key to create a new one.");
        }

        // Parse IP whitelist
        $ipWhitelist = null;
        if ($request->filled('ip_whitelist')) {
            $ips = array_filter(array_map('trim', preg_split('/[\s,]+/', $request->input('ip_whitelist'))));
            foreach ($ips as $ip) {
                if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                    return back()->with('error', "Invalid IP address: {$ip}")->withInput();
                }
            }
            $ipWhitelist = array_values($ips);
        }

        // Generate the plaintext key
        $plaintextKey = ApiKey::generateKeyString();

        // Prepare data for API key creation
        $apiKeyData = [
            'user_id' => $request->user()->id,
            'name' => $request->input('name'),
            'key_hash' => ApiKey::hashKey($plaintextKey),
            'scopes' => $request->input('scopes'),
            'api_style' => $request->input('api_style'),
            'default_panel_id' => $request->input('default_panel_id'),
            'ip_whitelist' => $ipWhitelist,
            'expires_at' => $request->input('expires_at'),
            'revoked' => false,
        ];

        // Generate admin credentials for Marzneshin-style API keys
        $plaintextAdminPassword = null;
        if ($request->input('api_style') === ApiKey::STYLE_MARZNESHIN) {
            $apiKeyData['admin_username'] = ApiKey::generateAdminUsername();
            $plaintextAdminPassword = ApiKey::generateAdminPassword();
            $apiKeyData['admin_password'] = $plaintextAdminPassword; // Will be hashed by mutator
        }

        // Create the API key record
        $apiKey = ApiKey::create($apiKeyData);

        // Log the key creation
        ApiAuditLog::logAction(
            $request->user()->id,
            $apiKey->id,
            'api_key.created',
            'api_key',
            $apiKey->id,
            [
                'key_name' => $apiKey->name,
                'api_style' => $apiKey->api_style,
                'default_panel_id' => $apiKey->default_panel_id,
                'scopes' => $apiKey->scopes,
                'has_admin_credentials' => $apiKey->hasAdminCredentials(),
            ]
        );

        // Build redirect with flash data
        $redirect = redirect()->route('reseller.api-keys.index')
            ->with('success', 'API key created successfully. Save this key - it will not be shown again!')
            ->with('new_api_key', $plaintextKey);

        // Add admin credentials to flash data for Marzneshin-style keys
        if ($request->input('api_style') === ApiKey::STYLE_MARZNESHIN && $plaintextAdminPassword) {
            $redirect = $redirect
                ->with('new_admin_username', $apiKey->admin_username)
                ->with('new_admin_password', $plaintextAdminPassword)
                ->with('new_api_style', ApiKey::STYLE_MARZNESHIN);
        }

        return $redirect;
    }

    /**
     * Revoke an API key.
     */
    public function revoke(Request $request, string $id)
    {
        $reseller = $request->user()->reseller;

        if (! $reseller || ! $reseller->api_enabled) {
            return back()->with('error', 'API access is not enabled for your account.');
        }

        $apiKey = ApiKey::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $apiKey) {
            return back()->with('error', 'API key not found.');
        }

        if ($apiKey->revoked) {
            return back()->with('error', 'API key is already revoked.');
        }

        $apiKey->revoke();

        // Log the revocation
        ApiAuditLog::logAction(
            $request->user()->id,
            $apiKey->id,
            'api_key.revoked',
            'api_key',
            $apiKey->id,
            ['key_name' => $apiKey->name]
        );

        return back()->with('success', 'API key revoked successfully.');
    }

    /**
     * Delete an API key.
     */
    public function destroy(Request $request, string $id)
    {
        $reseller = $request->user()->reseller;

        if (! $reseller || ! $reseller->api_enabled) {
            return back()->with('error', 'API access is not enabled for your account.');
        }

        $apiKey = ApiKey::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $apiKey) {
            return back()->with('error', 'API key not found.');
        }

        // Log before deletion
        ApiAuditLog::logAction(
            $request->user()->id,
            $apiKey->id,
            'api_key.deleted',
            'api_key',
            $apiKey->id,
            ['key_name' => $apiKey->name]
        );

        $apiKey->delete();

        return back()->with('success', 'API key deleted successfully.');
    }
}
