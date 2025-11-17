<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureWalletAccess
{
    /**
     * Handle an incoming request.
     *
     * For wallet-based resellers who are suspended due to low balance,
     * redirect them to the wallet charge page with a warning message.
     *
     * This middleware should be applied to reseller routes but NOT to wallet routes.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->check()) {
            return redirect()->route('login');
        }

        $user = auth()->user();

        // Only check for resellers
        if (! $user->isReseller()) {
            return $next($request);
        }

        $reseller = $user->reseller;

        if (! $reseller) {
            Log::warning('reseller_panel_redirect', [
                'reason' => 'missing_reseller',
                'user_id' => $user->id,
            ]);

            return redirect()->route('register')->with(
                'error',
                'حساب ریسلر برای شما یافت نشد. لطفاً ثبت‌نام را تکمیل کنید.'
            );
        }

        // Check if this is a wallet-based reseller who is suspended
        if ($reseller->isWalletBased() && $reseller->isSuspendedWallet()) {
            $walletMin = (int) config('billing.reseller.first_topup.wallet_min', 150000);
            Log::info('reseller_panel_redirect', [
                'reseller_id' => $reseller->id,
                'status' => $reseller->status,
                'type' => $reseller->type,
                'threshold' => $walletMin,
            ]);

            // Redirect to wallet charge page with warning
            return redirect()
                ->route('wallet.charge.form')
                ->with('warning', "برای فعال‌سازی حساب، ابتدا حداقل ".number_format($walletMin)." تومان شارژ کنید.");
        }

        if ($reseller->isTrafficBased() && $reseller->isSuspendedTraffic()) {
            $trafficMin = (int) config('billing.reseller.first_topup.traffic_min_gb', 250);
            Log::info('reseller_panel_redirect', [
                'reseller_id' => $reseller->id,
                'status' => $reseller->status,
                'type' => $reseller->type,
                'threshold_gb' => $trafficMin,
            ]);

            return redirect()
                ->route('wallet.charge.form')
                ->with('warning', "برای فعال‌سازی حساب، ابتدا حداقل ".number_format($trafficMin)." گیگابایت ترافیک خریداری کنید.");
        }

        return $next($request);
    }
}
