<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsReseller
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->check()) {
            return redirect()->route('login');
        }

        if (! auth()->user()->isReseller()) {
            abort(403, 'Access denied. Only resellers can access this area.');
        }

        $reseller = auth()->user()->reseller;

        if (! $reseller) {
            Log::warning('reseller_panel_redirect', [
                'reason' => 'missing_reseller',
                'user_id' => auth()->id(),
            ]);

            return redirect()->route('register')->with(
                'error',
                'حساب ریسلر برای شما ایجاد نشده است. لطفاً ثبت‌نام را کامل کنید یا با پشتیبانی تماس بگیرید.'
            );
        }

        // For suspended resellers, redirect to charge page with clear messaging
        if ($reseller->isSuspendedWallet() || $reseller->isSuspendedTraffic() || $reseller->isSuspended()) {
            $walletMin = (int) config('billing.reseller.first_topup.wallet_min', 150000);
            $trafficMin = (int) config('billing.reseller.first_topup.traffic_min_gb', 250);
            $message = $reseller->isSuspendedTraffic()
                ? 'برای فعال‌سازی حساب، ابتدا حداقل '.number_format($trafficMin).' گیگابایت ترافیک خریداری کنید.'
                : 'برای فعال‌سازی حساب، ابتدا حداقل '.number_format($walletMin).' تومان شارژ کنید.';

            Log::info('reseller_panel_redirect', [
                'reseller_id' => $reseller->id,
                'status' => $reseller->status,
                'type' => $reseller->type,
            ]);

            return redirect()->route('wallet.charge.form')->with('warning', $message);
        }

        // Check if reseller has any panels assigned (multi-panel system)
        if (! $reseller->hasAnyPanels()) {
            Log::warning('reseller_panel_redirect', [
                'reseller_id' => $reseller->id,
                'status' => $reseller->status,
                'type' => $reseller->type,
                'reason' => 'no_panels_assigned',
            ]);

            return redirect()->route('wallet.charge.form')->with(
                'warning',
                'هیچ پنلی برای شما تنظیم نشده است. لطفاً با پشتیبانی برای دسترسی به پنل تماس بگیرید.'
            );
        }

        return $next($request);
    }
}
