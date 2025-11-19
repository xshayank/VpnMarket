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
use Illuminate\Support\Facades\DB;
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
            // Get settings for branding and defaults
            $settings = Setting::getCachedMap();

            $requestedType = strtolower((string) request()->query('reseller_type', ''));
            $settingsDefaultType = $settings->get('homepage.default_reseller_type');
            $prefillResellerType = in_array($requestedType, ['wallet', 'traffic'], true)
                ? $requestedType
                : (in_array($settingsDefaultType, ['wallet', 'traffic'], true) ? $settingsDefaultType : null);

            return view('auth.register', [
                'settings' => $settings,
                'defaultResellerType' => $prefillResellerType,
                'prefill' => [
                    'reseller_type' => $prefillResellerType,
                ],
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
                'message' => 'مشکل موقت در بارگذاری صفحه ثبت‌نام. لطفاً بعداً تلاش کنید.'
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
            'ip' => $request->ip(),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'reseller_type' => ['required', 'in:wallet,traffic'],
        ]);

        DB::beginTransaction();
        try {
            // Create the user
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'ip_address' => $request->ip(),
            ]);

            // Determine initial status based on reseller type
            $initialStatus = $validated['reseller_type'] === 'wallet' 
                ? 'suspended_wallet' 
                : 'suspended_traffic';

            // Prepare reseller data
            $resellerData = [
                'user_id' => $user->id,
                'type' => $validated['reseller_type'],
                'status' => $initialStatus,
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

            // Create the reseller record
            $reseller = Reseller::create($resellerData);

            // Auto-attach all panels that are marked auto_assign_to_resellers
            $autoPanels = Panel::where('is_active', true)->autoAssign()->get();
            foreach ($autoPanels as $panel) {
                $reseller->panels()->attach($panel->id, [
                    'allowed_node_ids' => json_encode($panel->getRegistrationDefaultNodeIds()),
                    'allowed_service_ids' => json_encode($panel->getRegistrationDefaultServiceIds()),
                ]);
            }

            Log::info('reseller_created', [
                'user_id' => $user->id,
                'reseller_id' => $reseller->id,
                'type' => $validated['reseller_type'],
                'initial_status' => $initialStatus,
                'auto_panels_count' => $autoPanels->count(),
                'max_configs' => $resellerData['max_configs'],
            ]);

            // Handle referral if present
            if ($request->filled('ref')) {
                $referrer = User::where('referral_code', $request->ref)->first();
                if ($referrer) {
                    $user->referrer_id = $referrer->id;
                    $user->save();

                    Log::info('referral_registered', [
                        'new_user_id' => $user->id,
                        'referrer_id' => $referrer->id,
                    ]);
                }
            }

            event(new Registered($user));

            Auth::login($user);

            DB::commit();

            Log::info('registration_complete', [
                'user_id' => $user->id,
                'reseller_id' => $reseller->id,
            ]);

            // Redirect to wallet charge page with appropriate message
            $message = $validated['reseller_type'] === 'wallet'
                ? 'حساب شما با موفقیت ایجاد شد! برای فعال‌سازی، لطفاً حداقل ۱۵۰,۰۰۰ تومان شارژ کنید.'
                : 'حساب شما با موفقیت ایجاد شد! برای فعال‌سازی، لطفاً حداقل ۲۵۰ گیگابایت ترافیک خریداری کنید.';

            return redirect()->route('wallet.charge.form')->with('status', $message);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('registration_failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            abort(500, 'خطا در ثبت‌نام. لطفاً بعداً دوباره تلاش کنید.');
        }
    }
}
