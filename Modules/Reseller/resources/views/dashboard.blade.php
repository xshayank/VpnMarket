<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">
                {{ __('داشبورد ریسلر') }}
            </h2>
        </div>
    </x-slot>

    <div class="py-6 md:py-12">
        <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 space-y-3 md:space-y-6">
            
            <x-reseller-back-button :fallbackRoute="route('dashboard')" label="بازگشت به داشبورد اصلی" />
            
            @if (session('success'))
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg relative text-right" role="alert">
                    <strong class="font-bold">موفقیت!</strong>
                    <span class="block sm:inline">{{ session('success') }}</span>
                </div>
            @endif

            @if (session('status'))
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg relative text-right" role="alert">
                    <strong class="font-bold">موفقیت!</strong>
                    <span class="block sm:inline">{{ session('status') }}</span>
                </div>
            @endif

            @if (session('error'))
                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative text-right" role="alert">
                    <strong class="font-bold">خطا!</strong>
                    <span class="block sm:inline">{{ session('error') }}</span>
                </div>
            @endif

            {{-- Reseller Type Badge --}}
            <div class="p-3 md:p-6 bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg text-right">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
                    <div>
                        <span class="text-sm md:text-base text-gray-500 dark:text-gray-400">نوع اکانت:</span>
                        <span class="font-bold text-base md:text-lg text-gray-900 dark:text-gray-100 block sm:inline">
                            @if ($reseller->isPlanBased())
                                ریسلر پلن‌محور
                            @elseif ($reseller->isWalletBased())
                                ریسلر کیف پول‌محور
                            @else
                                ریسلر ترافیک‌محور
                            @endif
                        </span>
                    </div>
                    <span class="px-4 py-2 {{ $reseller->status === 'active' ? 'bg-green-600' : ($reseller->isSuspendedWallet() ? 'bg-orange-600' : 'bg-red-600') }} text-white rounded-md text-sm md:text-base">
                        @if ($reseller->status === 'active')
                            فعال
                        @elseif ($reseller->isSuspendedWallet())
                            معلق (کمبود موجودی)
                        @else
                            معلق
                        @endif
                    </span>
                </div>
            </div>

            @if ($reseller->isPlanBased())
                {{-- Plan-based reseller stats --}}
                <div class="p-3 md:p-6 bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg text-right">
                    <h3 class="text-base md:text-lg font-semibold mb-3 md:mb-4 text-gray-900 dark:text-gray-100">آمار و اطلاعات</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 md:gap-4">
                        <div class="bg-blue-50 dark:bg-blue-900 p-3 md:p-4 rounded-lg">
                            <div class="text-xs md:text-sm text-gray-600 dark:text-gray-300">موجودی</div>
                            <div class="text-lg md:text-2xl font-bold text-gray-900 dark:text-gray-100 break-words">{{ number_format($stats['balance']) }} تومان</div>
                        </div>
                        <div class="bg-green-50 dark:bg-green-900 p-3 md:p-4 rounded-lg">
                            <div class="text-xs md:text-sm text-gray-600 dark:text-gray-300">تعداد سفارشات</div>
                            <div class="text-lg md:text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['total_orders'] }}</div>
                        </div>
                        <div class="bg-yellow-50 dark:bg-yellow-900 p-3 md:p-4 rounded-lg">
                            <div class="text-xs md:text-sm text-gray-600 dark:text-gray-300">سفارشات تکمیل شده</div>
                            <div class="text-lg md:text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['fulfilled_orders'] }}</div>
                        </div>
                        <div class="bg-purple-50 dark:bg-purple-900 p-3 md:p-4 rounded-lg">
                            <div class="text-xs md:text-sm text-gray-600 dark:text-gray-300">مجموع اکانت‌ها</div>
                            <div class="text-lg md:text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['total_accounts'] }}</div>
                        </div>
                    </div>
                    <div class="mt-4 md:mt-6 flex flex-col sm:flex-row gap-3 md:gap-4">
                        <a href="{{ route('reseller.plans.index') }}" class="w-full sm:w-auto px-4 py-3 md:py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-center text-sm md:text-base">
                            مشاهده پلن‌ها
                        </a>
                        <a href="{{ route('wallet.charge.form') }}" class="w-full sm:w-auto px-4 py-3 md:py-2 bg-green-600 text-white rounded-md hover:bg-green-700 text-center text-sm md:text-base">
                            شارژ کیف پول
                        </a>
                    </div>
                </div>

                {{-- Recent orders --}}
                @if (count($stats['recent_orders']) > 0)
                    <div class="p-3 md:p-6 bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg text-right">
                        <h3 class="text-base md:text-lg font-semibold mb-3 md:mb-4 text-gray-900 dark:text-gray-100">آخرین سفارشات</h3>
                        <div class="overflow-x-auto -mx-3 md:mx-0">
                            <table class="w-full min-w-[640px]">
                                <thead>
                                    <tr class="border-b dark:border-gray-700">
                                        <th class="text-right px-2 md:px-0 pb-2 text-xs md:text-sm text-gray-700 dark:text-gray-100">شناسه</th>
                                        <th class="text-right px-2 md:px-0 pb-2 text-xs md:text-sm text-gray-700 dark:text-gray-100">پلن</th>
                                        <th class="text-right px-2 md:px-0 pb-2 text-xs md:text-sm text-gray-700 dark:text-gray-100 hidden sm:table-cell">تعداد</th>
                                        <th class="text-right px-2 md:px-0 pb-2 text-xs md:text-sm text-gray-700 dark:text-gray-100">مبلغ کل</th>
                                        <th class="text-right px-2 md:px-0 pb-2 text-xs md:text-sm text-gray-700 dark:text-gray-100">وضعیت</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($stats['recent_orders'] as $order)
                                        <tr class="border-b dark:border-gray-700">
                                            <td class="py-2 px-2 md:px-0 text-xs md:text-base text-gray-900 dark:text-gray-100">{{ $order->id }}</td>
                                            <td class="py-2 px-2 md:px-0 text-xs md:text-base text-gray-900 dark:text-gray-100 break-words max-w-[120px] md:max-w-none">{{ $order->plan->name }}</td>
                                            <td class="py-2 px-2 md:px-0 text-xs md:text-base text-gray-900 dark:text-gray-100 hidden sm:table-cell">{{ $order->quantity }}</td>
                                            <td class="py-2 px-2 md:px-0 text-xs md:text-base text-gray-900 dark:text-gray-100 break-words">{{ number_format($order->total_price) }} تومان</td>
                                            <td class="py-2 px-2 md:px-0">
                                                <span class="px-2 py-1 rounded text-xs {{ $order->status === 'fulfilled' ? 'bg-green-100 text-green-800 dark:bg-green-800 dark:text-green-100' : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-800 dark:text-yellow-100' }}">
                                                    {{ $order->status }}
                                                </span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            @elseif ($reseller->isWalletBased())
                {{-- Wallet-based reseller stats --}}
                <div class="p-3 md:p-6 bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg text-right">
                    <h3 class="text-base md:text-lg font-semibold mb-3 md:mb-4 text-gray-900 dark:text-gray-100">موجودی کیف پول و آمار</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 md:gap-4 mb-3 md:mb-4">
                        <div class="bg-blue-50 dark:bg-blue-900 p-3 md:p-4 rounded-lg">
                            <div class="text-xs md:text-sm text-gray-600 dark:text-gray-300">موجودی کیف پول</div>
                            <div class="text-lg md:text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($stats['wallet_balance']) }} تومان</div>
                            @if ($stats['wallet_balance'] <= config('billing.wallet.suspension_threshold', -1000))
                                <div class="text-xs text-red-600 dark:text-red-400 mt-1">موجودی کم - لطفاً شارژ کنید</div>
                            @endif
                        </div>
                        <div class="bg-purple-50 dark:bg-purple-900 p-3 md:p-4 rounded-lg">
                            <div class="text-xs md:text-sm text-gray-600 dark:text-gray-300">قیمت هر گیگابایت</div>
                            <div class="text-lg md:text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($stats['wallet_price_per_gb']) }} تومان</div>
                        </div>
                        <div class="bg-red-50 dark:bg-red-900 p-3 md:p-4 rounded-lg">
                            <div class="text-xs md:text-sm text-gray-600 dark:text-gray-300">ترافیک مصرف شده</div>
                            <div class="text-lg md:text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['traffic_consumed_gb'] }} GB</div>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 md:gap-4">
                        <div class="bg-indigo-50 dark:bg-indigo-900 p-3 md:p-4 rounded-lg">
                            <div class="text-xs md:text-sm text-gray-600 dark:text-gray-300">کانفیگ‌های فعال</div>
                            <div class="text-lg md:text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['active_configs'] }}</div>
                        </div>
                        <div class="bg-pink-50 dark:bg-pink-900 p-3 md:p-4 rounded-lg">
                            <div class="text-xs md:text-sm text-gray-600 dark:text-gray-300">مجموع کانفیگ‌ها</div>
                            <div class="text-lg md:text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['total_configs'] }}</div>
                        </div>
                        <div class="bg-teal-50 dark:bg-teal-900 p-3 md:p-4 rounded-lg">
                            <div class="text-xs md:text-sm text-gray-600 dark:text-gray-300">محدودیت کانفیگ</div>
                            @if ($stats['is_unlimited_limit'])
                                <div class="text-lg md:text-2xl font-bold text-gray-900 dark:text-gray-100">نامحدود</div>
                            @else
                                <div class="text-lg md:text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['configs_remaining'] }} از {{ $stats['config_limit'] }}</div>
                                <div class="text-xs md:text-sm text-gray-500 dark:text-gray-400">باقیمانده</div>
                                @if ($stats['config_limit'] > 0)
                                    <div class="mt-2 w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                        <div class="bg-teal-600 h-2 rounded-full transition-all" style="width: {{ min(($stats['total_configs'] / $stats['config_limit']) * 100, 100) }}%"></div>
                                    </div>
                                @endif
                            @endif
                        </div>
                    </div>
                    <div class="mt-4 md:mt-6 flex flex-col sm:flex-row gap-3 md:gap-4">
                        <a href="{{ route('wallet.charge.form') }}" class="w-full sm:w-auto px-4 py-3 md:py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-center text-sm md:text-base">
                            شارژ کیف پول
                        </a>
                        <a href="{{ route('reseller.configs.create') }}" class="w-full sm:w-auto px-4 py-3 md:py-2 bg-green-600 text-white rounded-md hover:bg-green-700 text-center text-sm md:text-base">
                            ایجاد کانفیگ جدید
                        </a>
                        <a href="{{ route('reseller.configs.index') }}" class="w-full sm:w-auto px-4 py-3 md:py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700 text-center text-sm md:text-base">
                            مشاهده همه کانفیگ‌ها
                        </a>
                        <form action="{{ route('reseller.sync') }}" method="POST" class="w-full sm:w-auto">
                            @csrf
                            <button type="submit" class="w-full px-4 py-3 md:py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 text-center text-sm md:text-base">
                                به‌روزرسانی آمار
                            </button>
                        </form>
                    </div>
                </div>
            @else
                {{-- Traffic-based reseller stats --}}
                <div class="p-3 md:p-6 bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg text-right">
                    <h3 class="text-base md:text-lg font-semibold mb-3 md:mb-4 text-gray-900 dark:text-gray-100">ترافیک و زمان</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 md:gap-4 mb-3 md:mb-4">
                        <div class="bg-blue-50 dark:bg-blue-900 p-3 md:p-4 rounded-lg">
                            <div class="text-xs md:text-sm text-gray-600 dark:text-gray-300">ترافیک کل</div>
                            <div class="text-lg md:text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['traffic_total_gb'] }} GB</div>
                        </div>
                        <div class="bg-red-50 dark:bg-red-900 p-3 md:p-4 rounded-lg">
                            <div class="text-xs md:text-sm text-gray-600 dark:text-gray-300">ترافیک مصرف شده</div>
                            <div class="text-lg md:text-2xl font-bold text-gray-900 dark:text-gray-100">{{ round($stats['traffic_consumed_bytes'] / (1024 * 1024 * 1024), 2) }} GB</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">(استفاده فعلی + مصرف قبلی)</div>
                        </div>
                        <div class="bg-green-50 dark:bg-green-900 p-3 md:p-4 rounded-lg">
                            <div class="text-xs md:text-sm text-gray-600 dark:text-gray-300">ترافیک باقی‌مانده</div>
                            <div class="text-lg md:text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['traffic_remaining_gb'] }} GB</div>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 md:gap-4 mb-3 md:mb-4">
                        <div class="bg-purple-50 dark:bg-purple-900 p-3 md:p-4 rounded-lg">
                            <div class="text-xs md:text-sm text-gray-600 dark:text-gray-300">تاریخ شروع</div>
                            <div class="text-base md:text-lg font-bold text-gray-900 dark:text-gray-100">{{ $reseller->window_start_label }}</div>
                        </div>
                        <div class="bg-yellow-50 dark:bg-yellow-900 p-3 md:p-4 rounded-lg">
                            <div class="text-xs md:text-sm text-gray-600 dark:text-gray-300">تاریخ پایان</div>
                            <div class="text-base md:text-lg font-bold text-gray-900 dark:text-gray-100">{{ $reseller->window_end_label }}</div>
                            @if (! is_null($stats['days_remaining']))
                                <div class="text-xs md:text-sm text-gray-500 dark:text-gray-400">{{ $stats['days_remaining'] }} روز باقی‌مانده</div>
                            @else
                                <div class="text-xs md:text-sm text-gray-500 dark:text-gray-400">بدون محدودیت زمانی</div>
                            @endif
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 md:gap-4">
                        <div class="bg-indigo-50 dark:bg-indigo-900 p-3 md:p-4 rounded-lg">
                            <div class="text-xs md:text-sm text-gray-600 dark:text-gray-300">کانفیگ‌های فعال</div>
                            <div class="text-lg md:text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['active_configs'] }}</div>
                        </div>
                        <div class="bg-pink-50 dark:bg-pink-900 p-3 md:p-4 rounded-lg">
                            <div class="text-xs md:text-sm text-gray-600 dark:text-gray-300">مجموع کانفیگ‌ها</div>
                            <div class="text-lg md:text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['total_configs'] }}</div>
                        </div>
                        <div class="bg-teal-50 dark:bg-teal-900 p-3 md:p-4 rounded-lg">
                            <div class="text-xs md:text-sm text-gray-600 dark:text-gray-300">محدودیت کانفیگ</div>
                            @if ($stats['is_unlimited_limit'])
                                <div class="text-lg md:text-2xl font-bold text-gray-900 dark:text-gray-100">نامحدود</div>
                            @else
                                <div class="text-lg md:text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['configs_remaining'] }} از {{ $stats['config_limit'] }}</div>
                                <div class="text-xs md:text-sm text-gray-500 dark:text-gray-400">باقیمانده</div>
                                @if ($stats['config_limit'] > 0)
                                    <div class="mt-2 w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                        <div class="bg-teal-600 h-2 rounded-full transition-all" style="width: {{ min(($stats['total_configs'] / $stats['config_limit']) * 100, 100) }}%"></div>
                                    </div>
                                @endif
                            @endif
                        </div>
                    </div>
                    <div class="mt-4 md:mt-6 flex flex-col sm:flex-row gap-3 md:gap-4">
                        <a href="{{ route('reseller.configs.create') }}" class="w-full sm:w-auto px-4 py-3 md:py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-center text-sm md:text-base">
                            ایجاد کانفیگ جدید
                        </a>
                        <a href="{{ route('reseller.configs.index') }}" class="w-full sm:w-auto px-4 py-3 md:py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700 text-center text-sm md:text-base">
                            مشاهده همه کانفیگ‌ها
                        </a>
                        <form action="{{ route('reseller.sync') }}" method="POST" class="w-full sm:w-auto">
                            @csrf
                            <button type="submit" class="w-full px-4 py-3 md:py-2 bg-green-600 text-white rounded-md hover:bg-green-700 text-center text-sm md:text-base">
                                به‌روزرسانی آمار
                            </button>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
