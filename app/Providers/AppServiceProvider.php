<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use App\Observers\ResellerConfigObserver;
use App\Observers\ResellerObserver;
use App\Policies\AuditLogPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Configure API rate limiter
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Register policies
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(Reseller::class, \App\Policies\ResellerPolicy::class);
        Gate::policy(ResellerConfig::class, \App\Policies\ResellerConfigPolicy::class);
        Gate::policy(\App\Models\Panel::class, \App\Policies\PanelPolicy::class);
        Gate::policy(\App\Models\Transaction::class, \App\Policies\WalletTopUpTransactionPolicy::class);

        // Register observers for audit safety net
        ResellerConfig::observe(ResellerConfigObserver::class);
        Reseller::observe(ResellerObserver::class);
        User::creating(function ($user) {
            do {

                $code = 'REF-' . strtoupper(Str::random(6));

            } while (User::where('referral_code', $code)->exists());

            $user->referral_code = $code;
        });
        // ==========================================================
    }
}
