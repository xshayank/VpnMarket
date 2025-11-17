<?php

use App\Http\Controllers\OrderController;
use App\Http\Controllers\Payments\StarsefarController;
use App\Http\Controllers\Payments\Tetra98Controller;
use App\Http\Controllers\ProfileController;
use App\Models\Order;
use App\Models\Panel;
use App\Models\Plan;
use App\Models\Setting;
use Illuminate\Support\Facades\Route;
use App\Support\Tetra98Config;

use App\Http\Controllers\WebhookController as NowPaymentsWebhookController;
use Modules\TelegramBot\Http\Controllers\WebhookController as TelegramWebhookController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    $settings = Setting::getCachedMap();

    $decodeJson = function (string $key) use ($settings) {
        $raw = $settings->get($key);
        return $raw ? (json_decode($raw, true) ?: []) : [];
    };

    $boolSetting = function (string $key, bool $default = false) use ($settings) {
        return filter_var($settings->get($key, $default ? '1' : '0'), FILTER_VALIDATE_BOOLEAN);
    };

    $defaultResellerType = $settings->get('homepage.default_reseller_type', 'wallet');
    $defaultResellerType = in_array($defaultResellerType, ['wallet', 'traffic'], true) ? $defaultResellerType : 'wallet';
    $defaultPanelId = $settings->get('homepage.default_panel_id');

    $homepage = [
        'hero_title' => $settings->get('homepage.hero_title', 'فالکو پنل | Falco Panel - داشبورد مدیریت نماینده'),
        'hero_subtitle' => $settings->get('homepage.hero_subtitle', 'OpenVPN و V2Ray با تحویل سریع، پایداری بالا و پشتیبانی اختصاصی برای نماینده‌ها'),
        'hero_media_url' => $settings->get('homepage.hero_media_url'),
        'primary_cta_text' => $settings->get('homepage.primary_cta_text', 'شروع در فالکو پنل'),
        'secondary_cta_text' => $settings->get('homepage.secondary_cta_text', 'مشاهده پلن‌ها'),
        'show_panels' => $boolSetting('homepage.show_panels', true),
        'show_plans' => $boolSetting('homepage.show_plans', true),
        'show_testimonials' => $boolSetting('homepage.show_testimonials', false),
        'show_faq' => $boolSetting('homepage.show_faq', true),
        'trust_badges' => $decodeJson('homepage.trust_badges'),
        'features' => $decodeJson('homepage.features'),
        'testimonials' => $decodeJson('homepage.testimonials'),
        'faqs' => $decodeJson('homepage.faqs'),
        'seo_title' => $settings->get('homepage.seo_title', 'Falco Panel | فالکو پنل'),
        'seo_description' => $settings->get('homepage.seo_description', 'فالکو پنل | فالکو پنل - ثبت‌نام سریع نماینده با داشبورد فارسی و پایدار'),
        'og_image_url' => $settings->get('homepage.og_image_url'),
        'default_reseller_type' => $defaultResellerType,
        'default_panel_id' => $defaultPanelId,
    ];

    if (empty($homepage['trust_badges'])) {
        $homepage['trust_badges'] = [
            ['icon' => '⏱️', 'label' => 'تحویل اکانت', 'value' => '< 5 دقیقه'],
            ['icon' => '📈', 'label' => 'میزان رضایت', 'value' => '۹۸٪ نماینده‌ها'],
            ['icon' => '🛡️', 'label' => 'پایداری شبکه', 'value' => '۹۹.۹٪ آپتایم'],
        ];
    }

    if (empty($homepage['features'])) {
        $homepage['features'] = [
            ['icon' => '🚀', 'title' => 'اتصال پرسرعت', 'description' => 'زیرساخت بهینه‌شده برای ایران با پینگ کم و تحویل سریع کانفیگ‌ها.'],
            ['icon' => '🧠', 'title' => 'مدیریت هوشمند', 'description' => 'محدودیت و سهمیه‌بندی خودکار برای اطمینان از سلامت نودها و حساب‌ها.'],
            ['icon' => '🤝', 'title' => 'پشتیبانی ویژه نماینده', 'description' => 'پاسخ‌گویی سریع و راهنمای توسعه کسب‌وکار شما در هر مرحله.'],
        ];
    }

    if (empty($homepage['faqs'])) {
        $homepage['faqs'] = [
            ['question' => 'چطور فعال می‌شوم؟', 'answer' => 'ثبت‌نام کنید، نوع نماینده را انتخاب کنید و اولین شارژ را انجام دهید. فعال‌سازی کمتر از ۵ دقیقه طول می‌کشد.'],
            ['question' => 'نماینده کیف پول چه شرایطی دارد؟', 'answer' => 'تسویه بر اساس تومان و ترافیک مصرفی انجام می‌شود؛ کاربران نهایی نامحدود هستند. برای شروع، حداقل ۱۵۰,۰۰۰ تومان شارژ اولیه نیاز است.'],
            ['question' => 'نماینده ترافیک چه شرایطی دارد؟', 'answer' => 'پرداخت بر اساس ترافیک خریداری‌شده/مصرفی است و استفاده بسیار ساده است. کاربران نهایی نامحدود هستند مگر سیاست دیگری تعریف شود.'],
        ];
    }

    $panels = Panel::where('is_active', true)->get();
    $plans = Plan::where('is_active', true)->orderBy('price')->take(6)->get();

    return view('landing.index', [
        'settings' => $settings,
        'homepage' => $homepage,
        'panels' => $panels,
        'plans' => $plans,
    ]);
})->name('home');

Route::get('/legacy-home', function () {
    $settings = Setting::getCachedMap();
    $plans = Plan::where('is_active', true)->orderBy('price')->get();
    $activeTheme = $settings->get('active_theme', 'welcome');
    $view = "themes.{$activeTheme}";

    if (!view()->exists($view)) {
        return view('welcome', ['settings' => $settings, 'plans' => $plans]);
    }

    return view($view, ['settings' => $settings, 'plans' => $plans]);
})->name('legacy-home');


Route::middleware(['auth'])->group(function () {
    // Dashboard
    Route::permanentRedirect('/dashboard', '/reseller')->name('dashboard');

    // Wallet
    Route::get('/wallet/charge', [OrderController::class, 'showChargeForm'])->name('wallet.charge.form');
    Route::post('/wallet/charge', [OrderController::class, 'createChargeOrder'])->name('wallet.charge.create');
    Route::post('/wallet/charge/starsefar/initiate', [StarsefarController::class, 'initiate'])->name('wallet.charge.starsefar.initiate');
    Route::get('/wallet/charge/starsefar/status/{orderId}', [StarsefarController::class, 'status'])->name('wallet.charge.starsefar.status');
    Route::post('/wallet/charge/tetra98/initiate', [Tetra98Controller::class, 'initiate'])->name('wallet.charge.tetra98.initiate');

    // Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Order & Payment Process
    Route::post('/order/{plan}', [OrderController::class, 'store'])->name('order.store');
    Route::get('/order/{order}', [OrderController::class, 'show'])->name('order.show');
    Route::get('/order/{order}/renew', [OrderController::class, 'showRenewForm'])->name('order.renew.form');
    Route::post('/order/{order}/renew', [OrderController::class, 'renew'])->name('order.renew');

    // Subscription Extension (keeping for backward compatibility but redirecting GET to renewal form)
    Route::get('/subscription/{order}/extend', function (Order $order) {
        return redirect()->route('order.renew.form', $order);
    })->name('subscription.extend.show');
    Route::post('/subscription/{order}/extend', [\App\Http\Controllers\SubscriptionExtensionController::class, 'store'])->name('subscription.extend');

    Route::post('/payment/card/{order}/submit', [OrderController::class, 'submitCardReceipt'])->name('payment.card.submit');
    Route::post('/payment/card/{order}', [OrderController::class, 'processCardPayment'])->name('payment.card.process');

    Route::post('/payment/crypto/{order}', [OrderController::class, 'processCryptoPayment'])->name('payment.crypto.process');
    Route::post('/payment/wallet/{order}', [OrderController::class, 'processWalletPayment'])->name('payment.wallet.process');

    // Coupon routes
    Route::post('/order/{order}/apply-coupon', [OrderController::class, 'applyCoupon'])->name('order.apply-coupon');
    Route::post('/order/{order}/remove-coupon', [OrderController::class, 'removeCoupon'])->name('order.remove-coupon');
});

Route::post('/webhooks/nowpayments', [NowPaymentsWebhookController::class, 'handle'])->name('webhooks.nowpayments');
Route::post('/webhooks/telegram', [TelegramWebhookController::class, 'handle'])->name('webhooks.telegram');
Route::post(config('starsefar.callback_path', '/webhooks/Stars-Callback'), [StarsefarController::class, 'webhook'])->name('webhooks.starsefar');
Route::match(['GET', 'POST'], Tetra98Config::getCallbackPath(), [Tetra98Controller::class, 'callback'])->name('webhooks.tetra98');


/* BREEZE AUTHENTICATION */
require __DIR__.'/auth.php';

