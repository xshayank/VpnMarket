<?php

namespace App\Filament\Pages;

use App\Models\Inbound;
use App\Models\Panel;
use App\Models\Setting;
use App\Support\PaymentMethodConfig;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Filament\Pages\Page;

class ThemeSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static string $view = 'filament.pages.theme-settings';
    protected static ?string $navigationLabel = 'تنظیمات سایت';
    protected static ?string $title = 'تنظیمات و محتوای سایت';

    public ?array $data = [];

//    public function mount(): void
//    {
//        $this->data = Setting::all()->pluck('value', 'key')->toArray();
//    }




    public function mount(): void
    {

        $settings = Setting::all()->pluck('value', 'key')->toArray();

        $defaultData = [
            'starsefar_enabled' => config('starsefar.enabled', false),
            'starsefar_api_key' => config('starsefar.api_key'),
            'starsefar_base_url' => config('starsefar.base_url'),
            'starsefar_callback_path' => config('starsefar.callback_path'),
            'starsefar_default_target_account' => config('starsefar.default_target_account'),
            'payment_card_to_card_enabled' => true,
            'payment_tetra98_enabled' => config('tetra98.enabled', false),
            'payment_tetra98_api_key' => config('tetra98.api_key'),
            'payment_tetra98_base_url' => config('tetra98.base_url', 'https://tetra98.ir'),
            'payment_tetra98_callback_path' => config('tetra98.callback_path', '/webhooks/tetra98/callback'),
            'payment_tetra98_min_amount' => config('tetra98.min_amount_toman', 10000),
            'homepage.hero_title' => 'همین امروز فروش VPN را شروع کنید',
            'homepage.hero_subtitle' => 'ریسلر OpenVPN و V2Ray شوید؛ پنل آماده، اتصال پایدار و پشتیبانی لحظه‌ای.',
            'homepage.primary_cta_text' => 'شروع به عنوان ریسلر',
            'homepage.secondary_cta_text' => 'مشاهده پلن‌ها',
            'homepage.show_panels' => true,
            'homepage.show_plans' => true,
            'homepage.show_testimonials' => false,
            'homepage.show_faq' => true,
            'homepage.default_reseller_type' => 'wallet',
        ];

        $this->data = array_merge($defaultData, $settings);

        $this->data['payment_card_to_card_enabled'] = array_key_exists('payment_card_to_card_enabled', $settings)
            ? filter_var($settings['payment_card_to_card_enabled'], FILTER_VALIDATE_BOOLEAN)
            : true;

        $this->data['payment_tetra98_enabled'] = array_key_exists('payment.tetra98.enabled', $settings)
            ? filter_var($settings['payment.tetra98.enabled'], FILTER_VALIDATE_BOOLEAN)
            : (bool) $defaultData['payment_tetra98_enabled'];

        $this->data['payment_tetra98_api_key'] = $settings['payment.tetra98.api_key'] ?? $defaultData['payment_tetra98_api_key'];
        $this->data['payment_tetra98_base_url'] = $settings['payment.tetra98.base_url'] ?? $defaultData['payment_tetra98_base_url'];
        $this->data['payment_tetra98_callback_path'] = $settings['payment.tetra98.callback_path'] ?? $defaultData['payment_tetra98_callback_path'];
        $this->data['payment_tetra98_min_amount'] = $settings['payment.tetra98.min_amount'] ?? $defaultData['payment_tetra98_min_amount'];

        $homepageToggles = [
            'homepage.show_panels',
            'homepage.show_plans',
            'homepage.show_testimonials',
            'homepage.show_faq',
        ];

        foreach ($homepageToggles as $toggleKey) {
            $this->data[$toggleKey] = array_key_exists($toggleKey, $settings)
                ? filter_var($settings[$toggleKey], FILTER_VALIDATE_BOOLEAN)
                : (bool) ($defaultData[$toggleKey] ?? false);
        }

        $jsonFields = [
            'homepage.trust_badges',
            'homepage.features',
            'homepage.testimonials',
            'homepage.faqs',
        ];

        foreach ($jsonFields as $jsonKey) {
            $raw = $settings[$jsonKey] ?? null;
            $this->data[$jsonKey] = $raw ? json_decode($raw, true) : [];
        }
    }
    public function form(Form $form): Form
    {
        return $form->schema([
            Tabs::make('Tabs')
                ->id('main-tabs')
                ->persistTab()
                ->tabs([

                    Tabs\Tab::make('تنظیمات قالب')
                        ->icon('heroicon-o-swatch')
                        ->schema([
                            // --- تغییر کلیدی ۲: حذف ->required() از این فیلدها ---
                            Select::make('active_theme')->label('قالب اصلی سایت')->options([
                                'welcome' => 'قالب خوش‌آمدگویی',
                                'cyberpunk' => 'قالب سایبرپانک',
                                'dragon' => 'قالب اژدها',
                                'arcane' => 'قالب آرکین (جادوی تکنولوژی)',
                            ])->default('welcome')->live(),
                            Select::make('active_auth_theme')->label('قالب صفحات ورود/ثبت‌نام')->options([
                                'default' => 'قالب پیش‌فرض (Breeze)',
                                'cyberpunk' => 'قالب سایبرپانک',
                                'dragon' => 'قالب اژدها',
                            ])->default('cyberpunk')->live(),
//                            FileUpload::make('site_logo')->label('لوگوی سایت')->image()->directory('logos')->visibility('public'),

                        ]),

                    Tabs\Tab::make('صفحه اصلی')
                        ->icon('heroicon-o-home')
                        ->schema([
                            Section::make('محتوای اصلی')->schema([
                                TextInput::make('homepage.hero_title')->label('تیتر اصلی')->placeholder('شروع درآمد دلاری از فروش VPN'),
                                Textarea::make('homepage.hero_subtitle')->label('زیرتیتر')->rows(2),
                                TextInput::make('homepage.hero_media_url')->label('لینک تصویر یا Lottie')->helperText('اختیاری - برای نمایش در پس‌زمینه یا کنار متن'),
                                TextInput::make('homepage.primary_cta_text')->label('متن دکمه اصلی')->placeholder('شروع به عنوان ریسلر'),
                                TextInput::make('homepage.secondary_cta_text')->label('متن دکمه ثانویه')->placeholder('مشاهده پلن‌ها'),
                            ])->columns(2),

                            Section::make('پیش‌فرض ثبت‌نام')->schema([
                                Radio::make('homepage.default_reseller_type')
                                    ->label('نوع پیش‌فرض ریسلر')
                                    ->options([
                                        'wallet' => 'کیف پول',
                                        'traffic' => 'ترافیک',
                                    ])->inline()->default('wallet'),
                                Select::make('homepage.default_panel_id')
                                    ->label('پنل پیش‌فرض (اختیاری)')
                                    ->options(fn () => Panel::where('is_active', true)->pluck('name', 'id'))
                                    ->searchable()
                                    ->placeholder('انتخاب پنل پیش‌فرض')
                                    ->nullable(),
                            ])->columns(2),

                            Section::make('نمایش بخش‌ها')->schema([
                                Toggle::make('homepage.show_panels')->label('نمایش پنل‌های فعال'),
                                Toggle::make('homepage.show_plans')->label('نمایش پلن‌ها'),
                                Toggle::make('homepage.show_testimonials')->label('نمایش نظر مشتریان'),
                                Toggle::make('homepage.show_faq')->label('نمایش پرسش‌های متداول'),
                            ])->columns(2),

                            Section::make('اعتماد و ویژگی‌ها')->schema([
                                Repeater::make('homepage.trust_badges')
                                    ->label('بج‌های اعتماد')
                                    ->schema([
                                        TextInput::make('icon')->label('آیکن')->placeholder('💠'),
                                        TextInput::make('label')->label('برچسب'),
                                        TextInput::make('value')->label('مقدار'),
                                    ])->columns(3),
                                Repeater::make('homepage.features')
                                    ->label('ویژگی‌ها')
                                    ->schema([
                                        TextInput::make('icon')->label('آیکن')->placeholder('⚡'),
                                        TextInput::make('title')->label('عنوان'),
                                        Textarea::make('description')->label('توضیح')->rows(2),
                                    ])->columns(3),
                            ]),

                            Section::make('تستیمونال و FAQ')->schema([
                                Repeater::make('homepage.testimonials')
                                    ->label('نظرات کاربران')
                                    ->schema([
                                        Textarea::make('quote')->label('متن نظر')->rows(2),
                                        TextInput::make('name')->label('نام'),
                                        TextInput::make('role')->label('سمت'),
                                        TextInput::make('avatar_url')->label('تصویر آواتار')->nullable(),
                                    ])->columns(2),
                                Repeater::make('homepage.faqs')
                                    ->label('پرسش‌های متداول')
                                    ->schema([
                                        TextInput::make('question')->label('سوال'),
                                        Textarea::make('answer')->label('پاسخ')->rows(2),
                                    ])->columns(2),
                            ]),

                            Section::make('سئو و شبکه‌های اجتماعی')->schema([
                                TextInput::make('homepage.seo_title')->label('عنوان سئو صفحه')->placeholder('ثبت‌نام ریسلر VPN Market'),
                                Textarea::make('homepage.seo_description')->label('توضیحات متا')->rows(2),
                                TextInput::make('homepage.og_image_url')->label('تصویر Open Graph')->placeholder('https://...'),
                            ])->columns(2),
                        ]),

                    Tabs\Tab::make('محتوای قالب اژدها')->icon('heroicon-o-fire')->visible(fn(Get $get) => $get('active_theme') === 'dragon')->schema([
                        Section::make('عمومی')->schema([
                            TextInput::make('dragon_navbar_brand')->label('نام برند در Navbar')->placeholder('EZHDEHA VPN'),
                            TextInput::make('dragon_footer_text')->label('متن فوتر')->placeholder('© 2025 Ezhdeha Networks. قدرت آتشین.'),
                        ])->columns(2),
                        Section::make('بخش اصلی (Hero Section)')->schema([
                            TextInput::make('dragon_hero_title')->label('تیتر اصلی')->placeholder('مرزهای دیجیتال را بسوزان'),
                            Textarea::make('dragon_hero_subtitle')->label('زیرتیتر')->rows(2)->placeholder('سرعتی افسانه‌ای و امنیتی نفوذناپذیر. سلطه بر اینترنت.'),
                            TextInput::make('dragon_hero_button_text')->label('متن دکمه اصلی')->placeholder('فتح شبکه'),
                        ]),
                        Section::make('بخش ویژگی‌ها (Features)')->schema([
                            TextInput::make('dragon_features_title')->label('عنوان بخش')->placeholder('عناصر قدرت اژدها'),
                            TextInput::make('dragon_feature1_title')->label('عنوان ویژگی ۱')->placeholder('نفس آتشین (سرعت)'),
                            Textarea::make('dragon_feature1_desc')->label('توضیح ویژگی ۱')->rows(2),
                            TextInput::make('dragon_feature2_title')->label('عنوان ویژگی ۲')->placeholder('زره فلس‌دار (امنیت)'),
                            Textarea::make('dragon_feature2_desc')->label('توضیح ویژگی ۲')->rows(2),
                            TextInput::make('dragon_feature3_title')->label('عنوان ویژگی ۳')->placeholder('بینایی فراتر (آزادی)'),
                            Textarea::make('dragon_feature3_desc')->label('توضیح ویژگی ۳')->rows(2),
                        ])->columns(3),
                        Section::make('بخش قیمت‌گذاری (Pricing)')->schema([
                            TextInput::make('dragon_pricing_title')->label('عنوان بخش')->placeholder('پیمان خون'),
                        ]),
                        Section::make('بخش سوالات متداول (FAQ)')->schema([
                            TextInput::make('dragon_faq_title')->label('عنوان بخش')->placeholder('طومارهای باستانی'),
                            TextInput::make('dragon_faq1_q')->label('سوال اول')->placeholder('آیا این سرویس باستانی است؟'),
                            Textarea::make('dragon_faq1_a')->label('پاسخ اول')->rows(2),
                            TextInput::make('dragon_faq2_q')->label('سوال دوم')->placeholder('چگونه قدرت اژدها را فعال کنم؟'),
                            Textarea::make('dragon_faq2_a')->label('پاسخ دوم')->rows(2),
                        ]),
                    ]),

                    Tabs\Tab::make('محتوای قالب آرکین')->icon('heroicon-o-sparkles')->visible(fn(Get $get) => $get('active_theme') === 'arcane')->schema([
                        Section::make('عمومی')->schema([
                            TextInput::make('arcane_navbar_brand')->label('نام برند')->placeholder('ARCANE'),
                            TextInput::make('arcane_footer_text')->label('متن فوتر')->placeholder('© 2025 Arcane Networks'),
                        ]),
                        Section::make('بخش اصلی (Hero Section)')->schema([
                            TextInput::make('arcane_hero_title')->label('تیتر اصلی')->placeholder('کدگشایی اینترنت آزاد'),
                            Textarea::make('arcane_hero_subtitle')->label('زیرتیتر')->rows(2),
                            TextInput::make('arcane_hero_button')->label('متن دکمه')->placeholder('دسترسی به شبکه'),
                        ]),
                        Section::make('بخش ویژگی‌ها (Features)')->schema([
                            TextInput::make('arcane_features_title')->label('عنوان بخش')->placeholder('اصول جادوی دیجیتال'),
                            TextInput::make('arcane_feature1_title')->label('عنوان ویژگی ۱')->placeholder('پروتکل‌های کوانتومی'),
                            Textarea::make('arcane_feature1_desc')->label('توضیح ویژگی ۱')->rows(2),
                            TextInput::make('arcane_feature2_title')->label('عنوان ویژگی ۲')->placeholder('پنهان‌سازی هویت'),
                            Textarea::make('arcane_feature2_desc')->label('توضیح ویژگی ۲')->rows(2),
                            TextInput::make('arcane_feature3_title')->label('عنوان ویژگی ۳')->placeholder('اتصال بی‌پایان'),
                            Textarea::make('arcane_feature3_desc')->label('توضیح ویژگی ۳')->rows(2),
                        ])->columns(3),
                        Section::make('بخش قیمت‌گذاری (Pricing)')->schema([
                            TextInput::make('arcane_pricing_title')->label('عنوان بخش')->placeholder('انتخاب دسترسی'),
                        ]),
                        Section::make('بخش سوالات متداول (FAQ)')->schema([
                            TextInput::make('arcane_faq_title')->label('عنوان بخش')->placeholder('سوالات متداول'),
                            TextInput::make('arcane_faq1_q')->label('سوال اول')->placeholder('آیا اطلاعات کاربران ذخیره می‌شود؟'),
                            Textarea::make('arcane_faq1_a')->label('پاسخ اول')->rows(2),
                            TextInput::make('arcane_faq2_q')->label('سوال دوم')->placeholder('چگونه می‌توانم سرویس را روی چند دستگاه استفاده کنم؟'),
                            Textarea::make('arcane_faq2_a')->label('پاسخ دوم')->rows(2),
                        ]),
                    ]),

                    Tabs\Tab::make('محتوای قالب سایبرپانک')->icon('heroicon-o-bolt')->visible(fn(Get $get) => $get('active_theme') === 'cyberpunk')->schema([
                        Section::make('عمومی')->schema([
                            TextInput::make('cyberpunk_navbar_brand')->label('نام برند در Navbar')->placeholder('VPN Market'),
                            TextInput::make('cyberpunk_footer_text')->label('متن فوتر')->placeholder('© 2025 Quantum Network. اتصال برقرار شد.'),
                        ])->columns(2),
                        Section::make('بخش اصلی (Hero Section)')->schema([
                            TextInput::make('cyberpunk_hero_title')->label('تیتر اصلی')->placeholder('واقعیت را هک کن'),
                            Textarea::make('cyberpunk_hero_subtitle')->label('زیرتیتر')->rows(3),
                            TextInput::make('cyberpunk_hero_button_text')->label('متن دکمه اصلی')->placeholder('دریافت دسترسی'),
                        ]),
                        Section::make('بخش ویژگی‌ها (Features)')->schema([
                            TextInput::make('cyberpunk_features_title')->label('عنوان بخش')->placeholder('سیستم‌عامل آزادی دیجیتال شما'),
                            TextInput::make('cyberpunk_feature1_title')->label('عنوان ویژگی ۱')->placeholder('پروتکل Warp'),
                            Textarea::make('cyberpunk_feature1_desc')->label('توضیح ویژگی ۱')->rows(2),
                            TextInput::make('cyberpunk_feature2_title')->label('عنوان ویژگی ۲')->placeholder('حالت Ghost'),
                            Textarea::make('cyberpunk_feature2_desc')->label('توضیح ویژگی ۲')->rows(2),
                            TextInput::make('cyberpunk_feature3_title')->label('عنوان ویژگی ۳')->placeholder('اتصال پایدار'),
                            Textarea::make('cyberpunk_feature3_desc')->label('توضیح ویژگی ۳')->rows(2),
                            TextInput::make('cyberpunk_feature4_title')->label('عنوان ویژگی ۴')->placeholder('پشتیبانی Elite'),
                            Textarea::make('cyberpunk_feature4_desc')->label('توضیح ویژگی ۴')->rows(2),
                        ])->columns(2),
                        Section::make('بخش قیمت‌گذاری (Pricing)')->schema([
                            TextInput::make('cyberpunk_pricing_title')->label('عنوان بخش')->placeholder('انتخاب پلن اتصال'),
                        ]),
                        Section::make('بخش سوالات متداول (FAQ)')->schema([
                            TextInput::make('cyberpunk_faq_title')->label('عنوان بخش')->placeholder('اطلاعات طبقه‌بندی شده'),
                            TextInput::make('cyberpunk_faq1_q')->label('سوال اول')->placeholder('آیا اطلاعات کاربران ذخیره می‌شود؟'),
                            Textarea::make('cyberpunk_faq1_a')->label('پاسخ اول')->rows(2),
                            TextInput::make('cyberpunk_faq2_q')->label('سوال دوم')->placeholder('چگونه می‌توانم سرویس را روی چند دستگاه استفاده کنم؟'),
                            Textarea::make('cyberpunk_faq2_a')->label('پاسخ دوم')->rows(2),
                        ]),
                    ]),

                    Tabs\Tab::make('محتوای صفحات ورود')->icon('heroicon-o-key')->schema([
                        Section::make('متن‌های عمومی')->schema([TextInput::make('auth_brand_name')->label('نام برند')->placeholder('VPNMarket'),]),
                        Section::make('صفحه ورود (Login)')->schema([
                            TextInput::make('auth_login_title')->label('عنوان فرم ورود'),
                            TextInput::make('auth_login_email_placeholder')->label('متن داخل فیلد ایمیل'),
                            TextInput::make('auth_login_password_placeholder')->label('متن داخل فیلد رمز عبور'),
                            TextInput::make('auth_login_remember_me_label')->label('متن "مرا به خاطر بسپار"'),
                            TextInput::make('auth_login_forgot_password_link')->label('متن لینک "فراموشی رمز"'),
                            TextInput::make('auth_login_submit_button')->label('متن دکمه ورود'),
                            TextInput::make('auth_login_register_link')->label('متن لینک ثبت‌نام'),
                        ])->columns(2),
                        Section::make('صفحه ثبت‌نام (Register)')->schema([
                            TextInput::make('auth_register_title')->label('عنوان فرم ثبت‌نام'),
                            TextInput::make('auth_register_name_placeholder')->label('متن داخل فیلد نام'),
                            TextInput::make('auth_register_password_confirm_placeholder')->label('متن داخل فیلد تکرار رمز'),
                            TextInput::make('auth_register_submit_button')->label('متن دکمه ثبت‌نام'),
                            TextInput::make('auth_register_login_link')->label('متن لینک ورود'),
                        ])->columns(2),
                    ]),

                    Tabs\Tab::make('تنظیمات پرداخت')->icon('heroicon-o-credit-card')->schema([

                        Section::make('پرداخت کارت به کارت')->schema([
                            Toggle::make('payment_card_to_card_enabled')
                                ->label('کارت به کارت')
                                ->helperText('نمایش روش پرداخت کارت به کارت به کاربران و ریسلرها')
                                ->default(true),
                            TextInput::make('payment_card_number')
                                ->label('شماره کارت')
                                ->mask('9999-9999-9999-9999')
                                ->placeholder('XXXX-XXXX-XXXX-XXXX')
                                ->helperText('شماره کارت ۱۶ رقمی خود را وارد کنید.')
                                ->numeric(false)
                                ->validationAttribute('شماره کارت'),
                            TextInput::make('payment_card_holder_name')->label('نام صاحب حساب'),
                            Textarea::make('payment_card_instructions')->label('توضیحات اضافی')->rows(3),
                        ]),

                        Section::make('درگاه پرداخت Tetra98')->schema([
                            Toggle::make('payment_tetra98_enabled')
                                ->label('فعال‌سازی درگاه Tetra98')
                                ->reactive()
                                ->helperText('با فعال‌سازی این گزینه، پرداخت مستقیم از طریق Tetra98 برای کاربران و ریسلرها فعال می‌شود.'),
                            TextInput::make('payment_tetra98_api_key')
                                ->label('API Key Tetra98')
                                ->password()
                                ->revealable()
                                ->required(fn (Get $get) => (bool) $get('payment_tetra98_enabled'))
                                ->helperText('کلید ارائه‌شده در پنل Tetra98. هنگام فعال بودن درگاه باید این مقدار تکمیل شود.'),
                            TextInput::make('payment_tetra98_base_url')
                                ->label('آدرس پایه API')
                                ->helperText('آدرس سرویس Tetra98. در صورت تغییر دامنه، مقدار را به‌روز کنید.'),
                            TextInput::make('payment_tetra98_callback_path')
                                ->label('مسیر Callback (نسبی)')
                                ->helperText('مسیر نسبی برای دریافت نتیجه پرداخت، مانند /webhooks/tetra98/callback.'),
                            TextInput::make('payment_tetra98_min_amount')
                                ->label('حداقل مبلغ (تومان)')
                                ->numeric()
                                ->minValue(1000)
                                ->helperText('حداقل مبلغ قابل پرداخت از طریق Tetra98 به تومان.'),
                        ])->columns(2),

                        Section::make('درگاه استارز تلگرام')->schema([
                            Toggle::make('starsefar_enabled')
                                ->label('فعال‌سازی درگاه استارز')
                                ->helperText('با فعال‌سازی این گزینه، کاربران می‌توانند از طریق استارز تلگرام کیف پول خود را شارژ کنند.'),
                            TextInput::make('starsefar_api_key')
                                ->label('API Key استارز')
                                ->password()
                                ->revealable(),
                            TextInput::make('starsefar_base_url')
                                ->label('آدرس پایه API')
                                ->default('https://starsefar.xyz'),
                            TextInput::make('starsefar_callback_path')
                                ->label('مسیر Callback (نسبی)')
                                ->default('/webhooks/Stars-Callback')
                                ->helperText('در صورت نیاز می‌توانید مسیر وب‌هوک را تغییر دهید.'),
                            TextInput::make('starsefar_default_target_account')
                                ->label('هدف پیش‌فرض (اختیاری)')
                                ->helperText('در صورت تمایل، شماره یا نام کاربری پیش‌فرض برای فیلد اختیاری پرداخت را وارد کنید.'),
                        ])->columns(2),
                    ]),

                    Tabs\Tab::make('تنظیمات ربات تلگرام')->icon('heroicon-o-paper-airplane')->schema([

                        Section::make('اطلاعات اتصال ربات')->schema([
                            TextInput::make('telegram_bot_token')->label('توکن ربات تلگرام')->password(),
                            TextInput::make('telegram_admin_chat_id')->label('چت آی‌دی ادمین')->numeric(),
                        ]),
                    ]),

                    Tabs\Tab::make('سیستم دعوت از دوستان')
                        ->icon('heroicon-o-gift')
                        ->schema([
                            Section::make('تنظیمات پاداش دعوت')
                                ->description('مبالغ پاداش را به تومان وارد کنید.')
                                ->schema([
                                    TextInput::make('referral_welcome_gift')
                                        ->label('هدیه خوش‌آمدگویی')
                                        ->numeric()
                                        ->default(0)
                                        ->helperText('مبلغی که بلافاصله پس از ثبت‌نام با کد معرف، به کیف پول کاربر جدید اضافه می‌شود.'),

                                    TextInput::make('referral_referrer_reward')
                                        ->label('پاداش معرف')
                                        ->numeric()
                                        ->default(0)
                                        ->helperText('مبلغی که پس از اولین خرید موفق کاربر جدید، به کیف پول معرف او اضافه می‌شود.'),
                                ]),
                        ]),

                ])->columnSpanFull(),
        ])->statePath('data');
    }

    public function submit(): void
    {

        $this->form->validate();

        $formData = $this->form->getState();
        $cardToggle = (bool) ($formData['payment_card_to_card_enabled'] ?? true);

        $tetraEnabled = (bool) ($formData['payment_tetra98_enabled'] ?? false);
        $tetraApiKey = trim((string) ($formData['payment_tetra98_api_key'] ?? ''));
        $tetraBaseUrl = trim((string) ($formData['payment_tetra98_base_url'] ?? ''));
        $tetraCallbackPath = trim((string) ($formData['payment_tetra98_callback_path'] ?? ''));
        $tetraMinAmount = (int) ($formData['payment_tetra98_min_amount'] ?? config('tetra98.min_amount_toman', 10000));

        $tetraBaseUrl = $tetraBaseUrl !== '' ? $tetraBaseUrl : config('tetra98.base_url', 'https://tetra98.ir');
        $tetraCallbackPath = $tetraCallbackPath !== '' ? $tetraCallbackPath : config('tetra98.callback_path', '/webhooks/tetra98/callback');
        $tetraMinAmount = max(1000, $tetraMinAmount);

        Setting::updateOrCreate(['key' => 'payment.tetra98.enabled'], ['value' => $tetraEnabled ? '1' : '0']);
        Setting::updateOrCreate(['key' => 'payment.tetra98.api_key'], ['value' => $tetraApiKey]);
        Setting::updateOrCreate(['key' => 'payment.tetra98.base_url'], ['value' => $tetraBaseUrl]);
        Setting::updateOrCreate(['key' => 'payment.tetra98.callback_path'], ['value' => $tetraCallbackPath]);
        Setting::updateOrCreate(['key' => 'payment.tetra98.min_amount'], ['value' => (string) $tetraMinAmount]);

        unset(
            $formData['payment_tetra98_enabled'],
            $formData['payment_tetra98_api_key'],
            $formData['payment_tetra98_base_url'],
            $formData['payment_tetra98_callback_path'],
            $formData['payment_tetra98_min_amount'],
        );

        foreach ($formData as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }

            Setting::updateOrCreate(['key' => $key], ['value' => $value ?? '']);
        }

        PaymentMethodConfig::clearCache();
        Setting::clearCache();

        Log::info('payment.card_to_card.enabled updated', [
            'admin_id' => Auth::id(),
            'enabled' => $cardToggle,
        ]);

        Log::info('payment.tetra98.settings_updated', [
            'admin_id' => Auth::id(),
            'enabled' => $tetraEnabled,
            'api_key_configured' => $tetraApiKey !== '',
            'api_key_suffix' => $tetraApiKey !== '' ? Str::of($tetraApiKey)->substr(-4)->toString() : null,
            'base_url' => $tetraBaseUrl,
            'callback_path' => $tetraCallbackPath,
            'min_amount' => $tetraMinAmount,
        ]);

        Notification::make()->title('تنظیمات با موفقیت ذخیره شد.')->success()->send();
    }
}
