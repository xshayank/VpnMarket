<x-app-layout>
    <!-- Powered by VPNMarket CMS | v1.0 -->

    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('داشبورد کاربری') }}
        </h2>
    </x-slot>

    <div class="py-6 md:py-12">
        <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 space-y-4">

            {{-- نمایش پیغام‌های اطلاع‌رسانی --}}
            @if (session('renewal_success'))
                <div class="mb-4 bg-blue-100 border-t-4 border-blue-500 rounded-b text-blue-900 px-4 py-3 shadow-md text-right" role="alert">
                    <div class="flex flex-row-reverse items-center">
                        <div class="py-1"><svg class="fill-current h-6 w-6 text-blue-500 mr-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M2.93 17.07A10 10 0 1 1 17.07 2.93 10 10 0 0 1 2.93 17.07zm12.73-1.41A8 8 0 1 0 4.34 4.34a8 8 0 0 0 11.32 11.32zM9 11V9h2v6H9v-4zm0-6h2v2H9V5z"/></svg></div>
                        <div>
                            <p class="font-bold">اطلاعیه تمدید</p>
                            <p class="text-sm">{{ session('renewal_success') }}</p>
                        </div>
                    </div>
                </div>
            @endif
            @if (session('status'))
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg relative text-right" role="alert">
                    <strong class="font-bold">موفقیت!</strong>
                    <span class="block sm:inline">{{ session('status') }}</span>
                </div>
            @endif
            @if (session('error'))
                <div class="bg-red-100 dark:bg-red-800/30 border border-red-400 dark:border-red-600 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg relative text-right" role="alert">
                    <strong class="font-bold">خطا!</strong>
                    <span class="block sm:inline">{{ session('error') }}</span>
                </div>
            @endif

            {{-- Compact Stats Header - Marzban Style --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                {{-- Wallet Balance Card --}}
                <div class="bg-gradient-to-br from-blue-500 to-blue-600 dark:from-blue-600 dark:to-blue-700 rounded-xl p-4 text-white shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs text-blue-100 opacity-80">موجودی</p>
                            <p class="text-lg md:text-xl font-bold mt-1">{{ number_format(auth()->user()->balance) }}</p>
                            <p class="text-xs text-blue-100">تومان</p>
                        </div>
                        <div class="bg-white/20 rounded-full p-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                            </svg>
                        </div>
                    </div>
                </div>

                {{-- Active Services Card --}}
                <div class="bg-gradient-to-br from-green-500 to-green-600 dark:from-green-600 dark:to-green-700 rounded-xl p-4 text-white shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs text-green-100 opacity-80">سرویس‌های فعال</p>
                            <p class="text-lg md:text-xl font-bold mt-1">{{ $orders->count() }}</p>
                            <p class="text-xs text-green-100">سرویس</p>
                        </div>
                        <div class="bg-white/20 rounded-full p-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                    </div>
                </div>

                {{-- Total Transactions Card --}}
                <div class="bg-gradient-to-br from-purple-500 to-purple-600 dark:from-purple-600 dark:to-purple-700 rounded-xl p-4 text-white shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs text-purple-100 opacity-80">تراکنش‌ها</p>
                            <p class="text-lg md:text-xl font-bold mt-1">{{ $transactions->count() }}</p>
                            <p class="text-xs text-purple-100">تراکنش</p>
                        </div>
                        <div class="bg-white/20 rounded-full p-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                            </svg>
                        </div>
                    </div>
                </div>

                {{-- Referrals Card --}}
                <div class="bg-gradient-to-br from-amber-500 to-amber-600 dark:from-amber-600 dark:to-amber-700 rounded-xl p-4 text-white shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs text-amber-100 opacity-80">دعوت‌های موفق</p>
                            <p class="text-lg md:text-xl font-bold mt-1">{{ auth()->user()->referrals()->count() }}</p>
                            <p class="text-xs text-amber-100">نفر</p>
                        </div>
                        <div class="bg-white/20 rounded-full p-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                            </svg>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Action Buttons Row --}}
            <div class="flex flex-wrap gap-2 justify-end">
                <a href="{{ route('wallet.charge.form') }}" 
                   class="inline-flex items-center px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-lg transition-colors">
                    <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                    </svg>
                    شارژ کیف پول
                </a>
            </div>

            {{-- Main Content Card --}}
            <div x-data="{ tab: 'my_services' }" class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden">
                <div class="border-b border-gray-200 dark:border-gray-700">
                    <nav class="-mb-px flex flex-wrap gap-1 px-4 sm:px-8 py-2" aria-label="Tabs">
                        <button @click="tab = 'my_services'" :class="{'border-indigo-500 text-indigo-600 dark:text-indigo-400': tab === 'my_services', 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-200': tab !== 'my_services'}" class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition">
                            سرویس‌های من
                        </button>
                        <button @click="tab = 'order_history'" :class="{'border-indigo-500 text-indigo-600 dark:text-indigo-400': tab === 'order_history', 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-200': tab !== 'order_history'}" class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition">
                            تاریخچه سفارشات

                        </button>
                        <button @click="tab = 'new_service'" :class="{'border-indigo-500 text-indigo-600 dark:text-indigo-400': tab === 'new_service', 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-200': tab !== 'new_service'}" class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition">
                            خرید سرویس جدید
                        </button>

                        <button @click="tab = 'referral'" :class="{'border-indigo-500 text-indigo-600 dark:text-indigo-400': tab === 'referral', 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-200': tab !== 'referral'}" class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition">
                            دعوت از دوستان
                        </button>

                        <button @click="tab = 'tutorials'" :class="{'border-indigo-500 text-indigo-600 dark:text-indigo-400': tab === 'tutorials', 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-200': tab !== 'tutorials'}" class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition">
                            راهنمای اتصال
                        </button>
                        @if (Module::isEnabled('Ticketing'))
                            <button @click="tab = 'support'" :class="{'border-indigo-500 text-indigo-600 dark:text-indigo-400': tab === 'support', 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-200': tab !== 'support'}" class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition">
                                پشتیبانی
                            </button>
                        @endif
                    </nav>
                </div>

                <div class="p-2 sm:p-4">
                    {{-- محتوای تب سرویس‌های من --}}
                    <div x-show="tab === 'my_services'" x-transition.opacity>
                        @if($orders->isNotEmpty())
                            <div class="space-y-4">
                                @foreach ($orders as $order)
                                    <div class="p-5 rounded-xl bg-gray-50 dark:bg-gray-800/50 shadow-md transition-shadow hover:shadow-lg" x-data="{ open: false }">
                                        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 items-center text-right">
                                            <div>
                                                <span class="text-xs text-gray-500">پلن</span>
                                                <p class="font-bold text-gray-900 dark:text-white">{{ $order->plan->name }}</p>
                                            </div>
                                            <div>
                                                <span class="text-xs text-gray-500">حجم</span>
                                                <p class="font-bold text-gray-900 dark:text-white">{{ $order->plan->volume_gb }} GB</p>
                                            </div>
                                            <div>
                                                <span class="text-xs text-gray-500">وضعیت</span>
                                                <p class="font-semibold text-green-500">فعال</p>
                                            </div>
                                            <div>
                                                <span class="text-xs text-gray-500">تاریخ انقضا</span>
                                                <p class="font-mono text-gray-900 dark:text-white" dir="ltr">{{ $order->expires_at ? \Carbon\Carbon::parse($order->expires_at)->format('Y-m-d') : '-' }}</p>
                                            </div>
                                            <div class="text-left">
                                                <div class="flex items-center justify-end space-x-2 space-x-reverse">
                                                    <a href="{{ route('subscription.extend.show', $order->id) }}" class="px-3 py-2 bg-blue-500 text-white text-xs rounded-lg hover:bg-blue-600 focus:outline-none" title="تمدید سرویس">
                                                        تمدید
                                                    </a>
                                                    <button @click="open = !open" class="px-3 py-2 bg-gray-700 text-white text-xs rounded-lg hover:bg-gray-600 focus:outline-none">
                                                        <span x-show="!open">کانفیگ</span>
                                                        <span x-show="open">بستن</span>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <div x-show="open" x-transition x-cloak class="mt-4 pt-4 border-t dark:border-gray-700">
                                            <h4 class="font-bold mb-2 text-gray-900 dark:text-white text-right">اطلاعات سرویس:</h4>
                                            <div class="p-3 bg-gray-100 dark:bg-gray-900 rounded-lg relative" x-data="{copied: false, copyToClipboard(text) { navigator.clipboard.writeText(text); this.copied = true; setTimeout(() => { this.copied = false }, 2000); }}">
                                                <pre class="text-left text-sm text-gray-800 dark:text-gray-300 whitespace-pre-wrap" dir="ltr">{{ $order->config_details }}</pre>
                                                <button @click="copyToClipboard(`{{ $order->config_details }}`)" class="absolute top-2 right-2 px-2 py-1 text-xs bg-gray-300 dark:bg-gray-700 rounded hover:bg-gray-400"><span x-show="!copied">کپی</span><span x-show="copied" class="text-green-500 font-bold">کپی شد!</span></button>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="text-gray-500 dark:text-gray-400 text-center py-10">🚀 شما هنوز هیچ سرویس فعالی خریداری نکرده‌اید.</p>
                        @endif
                    </div>

                    {{-- محتوای تب تاریخچه سفارشات --}}
                    <div x-show="tab === 'order_history'" x-transition.opacity x-cloak>
                        <h2 class="text-xl font-bold mb-4 text-gray-900 dark:text-white text-right">تاریخچه سفارشات و تراکنش‌ها</h2>
                        <div class="space-y-3">
                            @forelse ($transactions as $transaction)
                                <div class="p-4 rounded-xl bg-gray-50 dark:bg-gray-800/50">
                                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 items-center text-right">
                                        <div>
                                            <span class="text-xs text-gray-500">نوع تراکنش</span>
                                            <p class="font-bold text-gray-900 dark:text-white">
                                                @if ($transaction->plan)
                                                    {{ $transaction->renews_order_id ? 'تمدید سرویس' : 'خرید سرویس' }}
                                                @else
                                                    شارژ کیف پول
                                                @endif
                                            </p>
                                        </div>
                                        <div>
                                            <span class="text-xs text-gray-500">مبلغ</span>
                                            <p class="font-bold text-gray-900 dark:text-white">
                                                {{ number_format($transaction->plan->price ?? $transaction->amount) }} تومان
                                            </p>
                                        </div>
                                        <div>
                                            <span class="text-xs text-gray-500">تاریخ</span>
                                            <p class="font-mono text-gray-900 dark:text-white" dir="ltr">
                                                {{ $transaction->created_at->format('Y-m-d') }}
                                            </p>
                                        </div>
                                        <div class="text-left">
                                            @if ($transaction->status == 'paid')
                                                <span class="px-3 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                                                    موفق
                                                </span>
                                            @elseif ($transaction->status == 'pending')
                                                <span class="px-3 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">
                                                    در انتظار تایید
                                                </span>
                                            @else
                                                <span class="px-3 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">
                                                    ناموفق/منقضی
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <p class="text-gray-500 dark:text-gray-400 text-center py-10">هیچ تراکنشی یافت نشد.</p>
                            @endforelse
                        </div>
                    </div>

                    {{-- تب خرید سرویس جدید --}}
                    <div x-show="tab === 'new_service'" x-transition.opacity x-cloak>
                        <h2 class="text-xl font-bold mb-4 text-gray-900 dark:text-white text-right">خرید سرویس جدید</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            @foreach ($plans as $plan)
                                <div class="p-6 rounded-xl bg-white dark:bg-gray-800 shadow-lg hover:shadow-blue-500/20 hover:-translate-y-1 transition-all text-right">
                                    <h3 class="font-bold text-lg text-gray-900 dark:text-white">{{ $plan->name }}</h3>
                                    <p class="text-3xl font-bold my-3 text-gray-900 dark:text-white">{{ $plan->price }} <span class="text-base font-normal text-gray-500 dark:text-gray-400">{{ $plan->currency }}</span></p>
                                    <ul class="text-sm space-y-2 text-gray-600 dark:text-gray-300 my-4">
                                        @foreach(explode("\n", $plan->features) as $feature)
                                            <li class="flex items-start"><svg class="w-4 h-4 text-green-500 ml-2 shrink-0 mt-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg><span>{{ trim($feature) }}</span></li>
                                        @endforeach
                                    </ul>
                                    <form method="POST" action="{{ route('order.store', $plan->id) }}" class="mt-6">
                                        @csrf
                                        <button type="submit" class="w-full px-4 py-3 bg-blue-600 text-white font-semibold rounded-lg shadow-md hover:bg-blue-700 transition">خرید این پلن</button>
                                    </form>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- تب راهنمای اتصال --}}
                    <div x-show="tab === 'tutorials'" x-transition.opacity x-cloak class="text-right">
                        <h2 class="text-xl font-bold mb-4 text-gray-900 dark:text-white">راهنمای استفاده از سرویس‌ها</h2>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400 mb-6">برای استفاده از کانفیگ‌ها، ابتدا باید نرم‌افزار V2Ray-Client مناسب دستگاه خود را نصب کنید.</p>

                        <div class="space-y-6" x-data="{ app: 'android' }">
                            <div class="flex justify-center p-1 bg-gray-200 dark:bg-gray-800 rounded-xl">
                                <button @click="app = 'android'" :class="app === 'android' ? 'bg-white dark:bg-gray-700 shadow' : ''" class="w-full text-center py-2 px-4 rounded-lg transition">اندروید</button>
                                <button @click="app = 'ios'" :class="app === 'ios' ? 'bg-white dark:bg-gray-700 shadow' : ''" class="w-full text-center py-2 px-4 rounded-lg transition">آیفون (iOS)</button>
                                <button @click="app = 'windows'" :class="app === 'windows' ? 'bg-white dark:bg-gray-700 shadow' : ''" class="w-full text-center py-2 px-4 rounded-lg transition">ویندوز</button>
                            </div>

                            <div x-show="app === 'android'" class="p-6 bg-gray-50 dark:bg-gray-800/50 rounded-xl animate-fadeIn">
                                <h3 class="font-bold text-lg mb-3">راهنمای اندروید (V2RayNG)</h3>
                                <ol class="list-decimal list-inside space-y-2 text-gray-700 dark:text-gray-300">
                                    <li>ابتدا نرم‌افزار <a href="https://github.com/2dust/v2rayNG/releases" target="_blank" class="text-blue-500 hover:underline">V2RayNG</a> را از این لینک دانلود و نصب کنید.</li>
                                    <li>در تب "سرویس‌های من"، روی دکمه "مشاهده کانفیگ" کلیک کرده و سپس دکمه "کپی" را بزنید.</li>
                                    <li>وارد برنامه V2RayNG شوید و روی علامت بعلاوه (+) در بالای صفحه ضربه بزنید.</li>
                                    <li>گزینه `Import config from Clipboard` را انتخاب کنید.</li>
                                    <li>برای اتصال، روی دایره خاکستری در پایین صفحه ضربه بزنید تا سبز شود.</li>
                                </ol>
                            </div>

                            <div x-show="app === 'ios'" x-cloak class="p-6 bg-gray-50 dark:bg-gray-800/50 rounded-xl animate-fadeIn">
                                <h3 class="font-bold text-lg mb-3">راهنمای آیفون (Streisand / V2Box)</h3>
                                <p class="mb-2 text-sm">برای iOS می‌توانید از چندین برنامه استفاده کنید. ما V2Box را پیشنهاد می‌کنیم.</p>
                                <ol class="list-decimal list-inside space-y-2 text-gray-700 dark:text-gray-300">
                                    <li>ابتدا یکی از نرم‌افزارهای <a href="https://apps.apple.com/us/app/v2box-v2ray-client/id6446814690" target="_blank" class="text-blue-500 hover:underline">V2Box</a> یا <a href="https://apps.apple.com/us/app/streisand/id6450534064" target="_blank" class="text-blue-500 hover:underline">Streisand</a> را از اپ استور نصب کنید.</li>
                                    <li>در تب "سرویس‌های من"، روی دکمه "مشاهده کانفیگ" کلیک کرده و سپس دکمه "کپی" را بزنید.</li>
                                    <li>وارد برنامه شده، به بخش کانفیگ‌ها (Configs) بروید.</li>
                                    <li>روی علامت بعلاوه (+) بزنید و گزینه `Import from Clipboard` را انتخاب کنید.</li>
                                    <li>برای اتصال، سرویس اضافه شده را انتخاب و آن را فعال کنید.</li>
                                </ol>
                            </div>

                            <div x-show="app === 'windows'" x-cloak class="p-6 bg-gray-50 dark:bg-gray-800/50 rounded-xl animate-fadeIn">
                                <h3 class="font-bold text-lg mb-3">راهنمای ویندوز (V2RayN)</h3>
                                <ol class="list-decimal list-inside space-y-2 text-gray-700 dark:text-gray-300">
                                    <li>ابتدا نرم‌افزار <a href="https://github.com/2dust/v2rayN/releases" target="_blank" class="text-blue-500 hover:underline">V2RayN</a> را از این لینک دانلود و از حالت فشرده خارج کنید.</li>
                                    <li>در تب "سرویس‌های من"، روی دکمه "مشاهده کانفیگ" کلیک کرده و سپس دکمه "کپی" را بزنید.</li>
                                    <li>در برنامه V2RayN، کلیدهای `Ctrl+V` را فشار دهید تا کانفیگ به صورت خودکار اضافه شود.</li>
                                    <li>روی آیکون برنامه در تسک‌بار راست کلیک کرده، از منوی `System proxy` گزینه `Set system proxy` را انتخاب کنید.</li>
                                    <li>برای اتصال، سرور اضافه شده را انتخاب کرده و کلید `Enter` را بزنید.</li>
                                </ol>
                            </div>
                        </div>
                    </div>

                    {{-- ========================================================== --}}
                    <div x-show="tab === 'referral'" x-transition.opacity x-cloak>
                        <h2 class="text-xl font-bold mb-6 text-gray-900 dark:text-white text-right">کسب درآمد با دعوت از دوستان</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-right">

                            {{-- کارت لینک دعوت --}}
                            <div class="p-6 rounded-2xl bg-gray-50 dark:bg-gray-800/50 space-y-4 shadow-lg">
                                <p class="text-gray-600 dark:text-gray-300">با اشتراک‌گذاری لینک زیر، دوستان خود را به ما معرفی کنید. پس از اولین خرید موفق آن‌ها، <span class="font-bold text-green-500">{{ number_format((int)\App\Models\Setting::where('key', 'referral_referrer_reward')->first()?->value ?? 0) }} تومان</span> به کیف پول شما اضافه خواهد شد!</p>

                                <div x-data="{ copied: false }">
                                    <label class="block text-sm font-medium text-gray-500">لینک دعوت اختصاصی شما:</label>
                                    <div class="mt-1 flex rounded-md shadow-sm">
                                        <input type="text" readonly id="referral-link" value="{{ route('register') }}?ref={{ auth()->user()->referral_code }}" class="flex-1 block w-full rounded-none rounded-r-md sm:text-sm border-gray-300 dark:bg-gray-900 dark:border-gray-600" dir="ltr">
                                        <button @click="navigator.clipboard.writeText(document.getElementById('referral-link').value); copied = true; setTimeout(() => copied = false, 2000)" type="button" class="relative -ml-px inline-flex items-center space-x-2 px-4 py-2 border border-gray-300 text-sm font-medium rounded-l-md text-gray-700 bg-gray-50 hover:bg-gray-100 dark:bg-gray-700 dark:text-gray-300 dark:border-gray-600 dark:hover:bg-gray-600">
                                            <span x-show="!copied">کپی</span>
                                            <span x-show="copied" x-cloak class="text-green-500 font-bold">کپی شد!</span>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            {{-- کارت آمار --}}
                            <div class="p-6 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex flex-col justify-center items-center shadow-lg">
                                <p class="opacity-80">تعداد دعوت‌های موفق شما</p>
                                <p class="font-bold text-6xl mt-2">{{ auth()->user()->referrals()->count() }}</p>
                                <p class="text-sm opacity-80 mt-1">نفر</p>
                            </div>

                        </div>
                    </div>
                    {{-- ========================================================== --}}


                    {{-- تب پشتیبانی --}}
                    @if (Module::isEnabled('Ticketing'))
                        <div x-show="tab === 'support'" x-transition.opacity x-cloak>
                            <div class="flex justify-between items-center mb-4">
                                <h2 class="text-xl font-bold text-gray-900 dark:text-white text-right">تیکت‌های پشتیبانی</h2>
                                <a href="{{ route('tickets.create') }}" class="px-4 py-2 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700">ارسال تیکت جدید</a>
                            </div>

                            <div class="space-y-4">
                                @forelse ($tickets as $ticket)
                                    <a href="{{ route('tickets.show', $ticket->id) }}" class="block p-4 bg-gray-50 dark:bg-gray-800/50 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 transition">
                                        <div class="flex justify-between items-center">
                                            <p class="font-semibold text-gray-800 dark:text-gray-200">{{ $ticket->subject }}</p>
                                            <span class="text-xs font-mono text-gray-500">{{ $ticket->created_at->format('Y-m-d') }}</span>
                                        </div>
                                        <div class="mt-2 flex justify-between items-center">
                                            <span class="text-sm text-gray-600 dark:text-gray-400">آخرین بروزرسانی: {{ $ticket->updated_at->diffForHumans() }}</span>
                                            <span class="text-xs px-2 py-1 rounded-full
                                                @switch($ticket->status)
                                                    @case('open') bg-blue-100 text-blue-800 @break
                                                    @case('answered') bg-green-100 text-green-800 @break
                                                    @case('closed') bg-gray-200 text-gray-700 @break
                                                @endswitch">
                                                {{ $ticket->status == 'open' ? 'باز' : ($ticket->status == 'answered' ? 'پاسخ داده شده' : 'بسته شده') }}
                                            </span>
                                        </div>
                                    </a>
                                @empty
                                    <p class="text-gray-500 dark:text-gray-400 text-center py-10">هیچ تیکتی یافت نشد.</p>
                                @endforelse
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>

