<?php

namespace Modules\Reseller\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ApiAuditLog;
use App\Models\ApiKey;
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

        if (!$reseller) {
            return redirect()->route('reseller.dashboard')
                ->with('error', 'Reseller account not found.');
        }

        if (!$reseller->api_enabled) {
            return redirect()->route('reseller.dashboard')
                ->with('error', 'API access is not enabled for your account. Please contact admin.');
        }

        $keys = $request->user()->apiKeys()
            ->orderBy('created_at', 'desc')
            ->get();

        return view('reseller::api-keys.index', [
            'reseller' => $reseller,
            'keys' => $keys,
            'scopes' => ApiKey::ALL_SCOPES,
        ]);
    }

    /**
     * Store a new API key.
     */
    public function store(Request $request)
    {
        $reseller = $request->user()->reseller;

        if (!$reseller || !$reseller->api_enabled) {
            return back()->with('error', 'API access is not enabled for your account.');
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'scopes' => 'required|array|min:1',
            'scopes.*' => 'string|in:' . implode(',', ApiKey::ALL_SCOPES),
            'expires_at' => 'nullable|date|after:now',
            'ip_whitelist' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
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
                if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                    return back()->with('error', "Invalid IP address: {$ip}")->withInput();
                }
            }
            $ipWhitelist = array_values($ips);
        }

        // Generate the plaintext key
        $plaintextKey = ApiKey::generateKeyString();

        // Create the API key record
        $apiKey = ApiKey::create([
            'user_id' => $request->user()->id,
            'name' => $request->input('name'),
            'key_hash' => ApiKey::hashKey($plaintextKey),
            'scopes' => $request->input('scopes'),
            'ip_whitelist' => $ipWhitelist,
            'expires_at' => $request->input('expires_at'),
            'revoked' => false,
        ]);

        // Log the key creation
        ApiAuditLog::logAction(
            $request->user()->id,
            $apiKey->id,
            'api_key.created',
            'api_key',
            $apiKey->id,
            ['key_name' => $apiKey->name, 'scopes' => $apiKey->scopes]
        );

        return redirect()->route('reseller.api-keys.index')
            ->with('success', 'API key created successfully. Save this key - it will not be shown again!')
            ->with('new_api_key', $plaintextKey);
    }

    /**
     * Revoke an API key.
     */
    public function revoke(Request $request, string $id)
    {
        $reseller = $request->user()->reseller;

        if (!$reseller || !$reseller->api_enabled) {
            return back()->with('error', 'API access is not enabled for your account.');
        }

        $apiKey = ApiKey::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$apiKey) {
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

        if (!$reseller || !$reseller->api_enabled) {
            return back()->with('error', 'API access is not enabled for your account.');
        }

        $apiKey = ApiKey::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$apiKey) {
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
