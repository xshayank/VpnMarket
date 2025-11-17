<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Reseller;
use App\Models\Panel;
use App\Models\Setting;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View|Response
    {
        try {
            // Get active panels for reseller selection
            $panels = Panel::where('is_active', true)->get();

            if ($panels->isEmpty()) {
                abort(503, 'در حال حاضر هیچ پنل فعالی برای ثبت‌نام موجود نیست. لطفاً بعداً دوباره تلاش کنید.');
            }

            // Get settings for branding and defaults
            $settings = Setting::getCachedMap();
            $defaultResellerType = request('reseller_type', $settings->get('homepage.default_reseller_type'));
            $defaultResellerType = in_array($defaultResellerType, ['wallet', 'traffic'], true) ? $defaultResellerType : null;

            $defaultPanelId = request('primary_panel_id', $settings->get('homepage.default_panel_id'));
            if ($defaultPanelId && !$panels->pluck('id')->contains((int) $defaultPanelId)) {
                $defaultPanelId = null;
            }

            return view('auth.register', [
                'panels' => $panels,
                'settings' => $settings,
                'defaultResellerType' => $defaultResellerType,
                'defaultPanelId' => $defaultPanelId,
            ]);
        } catch (\Throwable $e) {
            // Log model autoload or other critical errors
            Log::error('Registration page failed to load: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            
            // Return a graceful 503 error instead of raw exception
            return response()->view('errors.503', [
                'message' => 'مشکل موقت در بارگذاری پنلها. لطفاً بعداً تلاش کنید.'
            ], 503);
        }
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        Log::info('registration_start', [
            'email' => $request->email,
            'reseller_type' => $request->reseller_type,
            'panel_id' => $request->primary_panel_id,
            'ip' => $request->ip(),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'reseller_type' => ['required', 'in:wallet,traffic'],
            'primary_panel_id' => ['required', 'exists:panels,id'],
            'selected_nodes' => ['nullable', 'array'], // For Eylandoo panels
            'selected_nodes.*' => ['integer'],
            'selected_services' => ['nullable', 'array'], // For Marzneshin panels
            'selected_services.*' => ['integer'],
        ]);

        // Create the user
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'ip_address' => $request->ip(),
        ]);

        // Get the selected panel
        $panel = Panel::findOrFail($validated['primary_panel_id']);
        $panelType = strtolower(trim($panel->panel_type ?? ''));

        // Determine initial status based on reseller type
        $initialStatus = $validated['reseller_type'] === 'wallet' 
            ? 'suspended_wallet' 
            : 'suspended_traffic';

        // Prepare reseller data
        $resellerData = [
            'user_id' => $user->id,
            'type' => $validated['reseller_type'],
            'status' => $initialStatus,
            'primary_panel_id' => $validated['primary_panel_id'],
            'wallet_balance' => 0,
            'traffic_total_bytes' => 0,
            'traffic_used_bytes' => 0,
            'meta' => [],
        ];

        // Set config limit based on type
        if ($validated['reseller_type'] === 'wallet') {
            $resellerData['max_configs'] = config('billing.reseller.config_limits.wallet', 1000);
        } else {
            $resellerData['max_configs'] = null; // Unlimited for traffic resellers
        }

        // Handle Eylandoo panel node selection
        if ($panelType === 'eylandoo') {
            $defaultNodes = $panel->getRegistrationDefaultNodeIds();
            $selectedNodes = $request->input('selected_nodes', []);
            
            // Validate that selected nodes are subset of defaults
            if (!empty($selectedNodes)) {
                $validNodes = array_intersect($selectedNodes, $defaultNodes);
                $resellerData['eylandoo_allowed_node_ids'] = array_values($validNodes);
            } else {
                $resellerData['eylandoo_allowed_node_ids'] = $defaultNodes;
            }

            $resellerData['meta']['eylandoo_allowed_node_ids'] = $resellerData['eylandoo_allowed_node_ids'];
        }

        // Handle Marzneshin panel service selection
        if ($panelType === 'marzneshin') {
            $defaultServices = $panel->getRegistrationDefaultServiceIds();
            $selectedServices = $request->input('selected_services', []);
            
            // Validate that selected services are subset of defaults
            if (!empty($selectedServices)) {
                $validServices = array_intersect($selectedServices, $defaultServices);
                $resellerData['marzneshin_allowed_service_ids'] = array_values($validServices);
            } else {
                $resellerData['marzneshin_allowed_service_ids'] = $defaultServices;
            }

            $resellerData['meta']['marzneshin_allowed_service_ids'] = $resellerData['marzneshin_allowed_service_ids'];
        }

        // Create the reseller record
        $reseller = Reseller::create($resellerData);

        // Log defaults application
        if ($panelType === 'eylandoo' && !empty($resellerData['eylandoo_allowed_node_ids'])) {
            Log::info('registration_defaults_applied', [
                'reseller_id' => $reseller->id,
                'panel_id' => $validated['primary_panel_id'],
                'panel_type' => 'eylandoo',
                'default_node_count' => count($resellerData['eylandoo_allowed_node_ids']),
                'node_ids' => $resellerData['eylandoo_allowed_node_ids'],
            ]);
        } elseif ($panelType === 'marzneshin' && !empty($resellerData['marzneshin_allowed_service_ids'])) {
            Log::info('registration_defaults_applied', [
                'reseller_id' => $reseller->id,
                'panel_id' => $validated['primary_panel_id'],
                'panel_type' => 'marzneshin',
                'default_service_count' => count($resellerData['marzneshin_allowed_service_ids']),
                'service_ids' => $resellerData['marzneshin_allowed_service_ids'],
            ]);
        } elseif (in_array($panelType, ['eylandoo', 'marzneshin'])) {
            Log::info('registration_defaults_none', [
                'reseller_id' => $reseller->id,
                'panel_id' => $validated['primary_panel_id'],
                'panel_type' => $panelType,
                'reason' => 'no_defaults_configured',
            ]);
        }

        Log::info('reseller_created', [
            'user_id' => $user->id,
            'reseller_id' => $reseller->id,
            'type' => $validated['reseller_type'],
            'initial_status' => $initialStatus,
            'panel_id' => $validated['primary_panel_id'],
            'panel_type' => $panelType,
            'max_configs' => $resellerData['max_configs'],
        ]);

        // Handle referral if present
        if ($request->filled('ref')) {
            $referrer = User::where('referral_code', $request->ref)->first();
            if ($referrer) {
                $user->referrer_id = $referrer->id;
                $user->save();

                // Note: Welcome gift is disabled for reseller-only architecture
                // Resellers must make first top-up to activate
                Log::info('referral_registered', [
                    'new_user_id' => $user->id,
                    'referrer_id' => $referrer->id,
                ]);
            }
        }

        event(new Registered($user));

        Auth::login($user);

        Log::info('registration_complete', [
            'user_id' => $user->id,
            'reseller_id' => $reseller->id,
        ]);

        // Redirect to wallet charge page with appropriate message
        $message = $validated['reseller_type'] === 'wallet'
            ? 'حساب شما با موفقیت ایجاد شد! برای فعال‌سازی، لطفاً حداقل ۱۵۰,۰۰۰ تومان شارژ کنید.'
            : 'حساب شما با موفقیت ایجاد شد! برای فعال‌سازی، لطفاً حداقل ۲۵۰ گیگابایت ترافیک خریداری کنید.';

        return redirect()->route('wallet.charge.form')->with('status', $message);
    }
}
