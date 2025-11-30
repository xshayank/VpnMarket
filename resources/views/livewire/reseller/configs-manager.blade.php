<div class="space-y-4" x-data="{ 
    showQR: false, 
    qrUrl: '',
    panelsData: @js($this->panelsForJs),
    selectedPanelId: @entangle('selectedPanelId'),
    get selectedPanel() {
        if (!this.selectedPanelId) return null;
        return this.panelsData.find(p => String(p.id) === String(this.selectedPanelId)) || null;
    }
}">
    @if ($unsupported ?? false)
        <div class="text-center py-12">
            <div class="text-gray-500 dark:text-gray-400">
                این ویژگی فقط برای ریسلرهای ترافیک‌محور و کیف پول‌محور در دسترس است.
            </div>
        </div>
    @else
        {{-- Flash Messages --}}
        @if (session()->has('success'))
            <div class="bg-green-100 dark:bg-green-800/30 border border-green-400 dark:border-green-600 text-green-700 dark:text-green-300 px-4 py-3 rounded-lg relative text-right" role="alert">
                <span class="block sm:inline">{{ session('success') }}</span>
            </div>
        @endif

        @if (session()->has('error'))
            <div class="bg-red-100 dark:bg-red-800/30 border border-red-400 dark:border-red-600 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg relative text-right" role="alert">
                <span class="block sm:inline">{{ session('error') }}</span>
            </div>
        @endif

        {{-- Compact Stats Header - Marzban Style --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            {{-- Balance / Wallet Card --}}
            @if ($reseller->isWalletBased())
                <div class="bg-gradient-to-br from-blue-500 to-blue-600 dark:from-blue-600 dark:to-blue-700 rounded-xl p-4 text-white shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs text-blue-100 opacity-80">موجودی</p>
                            <p class="text-lg md:text-xl font-bold mt-1">{{ number_format($stats['wallet_balance'] ?? 0) }}</p>
                            <p class="text-xs text-blue-100">تومان</p>
                        </div>
                        <div class="bg-white/20 rounded-full p-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                            </svg>
                        </div>
                    </div>
                </div>
            @else
                <div class="bg-gradient-to-br from-green-500 to-green-600 dark:from-green-600 dark:to-green-700 rounded-xl p-4 text-white shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs text-green-100 opacity-80">ترافیک باقیمانده</p>
                            <p class="text-lg md:text-xl font-bold mt-1">{{ $stats['traffic_remaining_gb'] ?? 0 }}</p>
                            <p class="text-xs text-green-100">GB</p>
                        </div>
                        <div class="bg-white/20 rounded-full p-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                            </svg>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Used Traffic Card --}}
            <div class="bg-gradient-to-br from-purple-500 to-purple-600 dark:from-purple-600 dark:to-purple-700 rounded-xl p-4 text-white shadow-lg">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs text-purple-100 opacity-80">ترافیک مصرفی</p>
                        <p class="text-lg md:text-xl font-bold mt-1">{{ $stats['traffic_consumed_gb'] ?? 0 }}</p>
                        <p class="text-xs text-purple-100">GB</p>
                    </div>
                    <div class="bg-white/20 rounded-full p-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                    </div>
                </div>
            </div>

            {{-- Traffic Price Card --}}
            <div class="bg-gradient-to-br from-amber-500 to-amber-600 dark:from-amber-600 dark:to-amber-700 rounded-xl p-4 text-white shadow-lg">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs text-amber-100 opacity-80">قیمت ترافیک</p>
                        <p class="text-lg md:text-xl font-bold mt-1">{{ number_format($stats['wallet_price_per_gb'] ?? $reseller->getTrafficPricePerGb()) }}</p>
                        <p class="text-xs text-amber-100">تومان/GB</p>
                    </div>
                    <div class="bg-white/20 rounded-full p-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                </div>
            </div>

            {{-- Active Configs Card --}}
            <div class="bg-gradient-to-br from-teal-500 to-teal-600 dark:from-teal-600 dark:to-teal-700 rounded-xl p-4 text-white shadow-lg">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs text-teal-100 opacity-80">کانفیگ فعال</p>
                        <p class="text-lg md:text-xl font-bold mt-1">{{ $stats['active_configs'] ?? 0 }}</p>
                        <p class="text-xs text-teal-100">از {{ $stats['total_configs'] ?? 0 }}</p>
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
            <button wire:click="syncStats" 
                    class="inline-flex items-center px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white text-sm font-medium rounded-lg transition-colors">
                <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" wire:loading.class="animate-spin" wire:target="syncStats">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                بروزرسانی
            </button>
        </div>

        {{-- Users Section --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden">
            {{-- Controls Row --}}
            <div class="p-4 border-b border-gray-200 dark:border-gray-700">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    {{-- Search and Filter --}}
                    <div class="flex flex-col sm:flex-row gap-3 flex-1">
                        {{-- Search Input --}}
                        <div class="relative flex-1 max-w-md">
                            <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                                <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                </svg>
                            </div>
                            <input type="text" 
                                   wire:model.live.debounce.300ms="search"
                                   class="block w-full pr-10 pl-4 py-2.5 text-sm text-gray-900 dark:text-gray-100 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-right"
                                   placeholder="جستجو نام کاربری...">
                        </div>

                        {{-- Tab Filter --}}
                        <div class="flex bg-gray-100 dark:bg-gray-700 rounded-lg p-1">
                            <button wire:click="setStatusFilter('all')" 
                                    class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors {{ $statusFilter === 'all' ? 'bg-white dark:bg-gray-600 text-gray-900 dark:text-white shadow' : 'text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white' }}">
                                همه
                            </button>
                            <button wire:click="setStatusFilter('active')" 
                                    class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors {{ $statusFilter === 'active' ? 'bg-white dark:bg-gray-600 text-gray-900 dark:text-white shadow' : 'text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white' }}">
                                فعال
                            </button>
                            <button wire:click="setStatusFilter('disabled')" 
                                    class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors {{ $statusFilter === 'disabled' ? 'bg-white dark:bg-gray-600 text-gray-900 dark:text-white shadow' : 'text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white' }}">
                                غیرفعال
                            </button>
                            <button wire:click="setStatusFilter('expiring')" 
                                    class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors {{ $statusFilter === 'expiring' ? 'bg-white dark:bg-gray-600 text-gray-900 dark:text-white shadow' : 'text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white' }}">
                                در حال انقضا
                            </button>
                        </div>
                    </div>

                    {{-- Action Buttons --}}
                    <div class="flex gap-2">
                        <button wire:click="$refresh" 
                                class="p-2.5 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors"
                                title="بروزرسانی لیست">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" wire:loading.class="animate-spin" wire:target="$refresh">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                        </button>
                        <button wire:click="openCreateModal" 
                                class="inline-flex items-center px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors shadow-sm">
                            <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                            </svg>
                            ایجاد کاربر
                        </button>
                    </div>
                </div>
            </div>

            {{-- Users Table --}}
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 dark:bg-gray-700/50">
                        <tr class="text-right">
                            <th class="px-4 py-3 text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">نام کاربری</th>
                            <th class="px-4 py-3 text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">وضعیت</th>
                            <th class="px-4 py-3 text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider hidden md:table-cell">انقضا</th>
                            <th class="px-4 py-3 text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">مصرف داده</th>
                            <th class="px-4 py-3 text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">عملیات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($configs as $config)
                            @php
                                $usageBytes = $config->usage_bytes;
                                $settledBytes = $config->getSettledUsageBytes();
                                $totalUsed = $usageBytes + $settledBytes;
                                $limitBytes = $config->traffic_limit_bytes;
                                $usagePercent = $limitBytes > 0 ? min(100, round(($usageBytes / $limitBytes) * 100)) : 0;
                                $daysRemaining = now()->diffInDays($config->expires_at, false);
                            @endphp
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 cursor-pointer transition-colors" 
                                wire:click="openEditModal({{ $config->id }})">
                                {{-- Username (Display prefix only) --}}
                                <td class="px-4 py-4">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-bold text-sm">
                                            {{ mb_substr($config->display_username ?: $config->external_username, 0, 2) }}
                                        </div>
                                        <div class="mr-3">
                                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                                {{ $config->display_username ?: $config->external_username }}
                                            </p>
                                            @if ($config->comment)
                                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ Str::limit($config->comment, 30) }}</p>
                                            @endif
                                        </div>
                                    </div>
                                </td>

                                {{-- Status Badge --}}
                                <td class="px-4 py-4" wire:click.stop>
                                    <div class="flex flex-col gap-1">
                                        @if ($config->status === 'active')
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-800/30 dark:text-green-400">
                                                <span class="w-1.5 h-1.5 rounded-full bg-green-500 ml-1.5 animate-pulse"></span>
                                                فعال
                                            </span>
                                        @elseif ($config->status === 'disabled')
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-800/30 dark:text-red-400">
                                                <span class="w-1.5 h-1.5 rounded-full bg-red-500 ml-1.5"></span>
                                                غیرفعال
                                            </span>
                                        @elseif ($config->status === 'expired')
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-800/30 dark:text-gray-400">
                                                منقضی شده
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-800/30 dark:text-yellow-400">
                                                {{ $config->status }}
                                            </span>
                                        @endif
                                    </div>
                                </td>

                                {{-- Expiry Date --}}
                                <td class="px-4 py-4 hidden md:table-cell">
                                    <div class="text-sm">
                                        @if ($daysRemaining < 0)
                                            <span class="text-red-600 dark:text-red-400 font-medium">منقضی شده</span>
                                        @elseif ($daysRemaining <= 7)
                                            <span class="text-amber-600 dark:text-amber-400 font-medium">{{ $daysRemaining }} روز مانده</span>
                                        @else
                                            <span class="text-gray-700 dark:text-gray-300">{{ $daysRemaining }} روز مانده</span>
                                        @endif
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ $config->expires_at->format('Y-m-d') }}</p>
                                    </div>
                                </td>

                                {{-- Data Usage with Progress Bar --}}
                                <td class="px-4 py-4">
                                    <div class="w-32 md:w-40">
                                        {{-- Progress Bar --}}
                                        <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2 mb-1.5">
                                            <div class="h-2 rounded-full transition-all duration-300 {{ $usagePercent >= 90 ? 'bg-red-500' : ($usagePercent >= 70 ? 'bg-amber-500' : 'bg-blue-500') }}" 
                                                 style="width: {{ $usagePercent }}%"></div>
                                        </div>
                                        {{-- Usage Text --}}
                                        <div class="text-xs text-gray-600 dark:text-gray-400">
                                            <span class="font-medium">{{ round($usageBytes / (1024 * 1024 * 1024), 2) }}</span> / {{ round($limitBytes / (1024 * 1024 * 1024), 2) }} GB
                                        </div>
                                        @if ($settledBytes > 0)
                                            <div class="text-xs text-gray-500 dark:text-gray-500">
                                                کل: {{ round($totalUsed / (1024 * 1024 * 1024), 2) }} GB
                                            </div>
                                        @endif
                                    </div>
                                </td>

                                {{-- Actions --}}
                                <td class="px-4 py-4" wire:click.stop>
                                    <div class="flex items-center gap-1">
                                        {{-- Edit Button --}}
                                        <button wire:click="openEditModal({{ $config->id }})" 
                                                class="p-2 text-gray-600 dark:text-gray-400 hover:text-blue-600 dark:hover:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/30 rounded-lg transition-colors"
                                                title="ویرایش">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                            </svg>
                                        </button>

                                        {{-- Copy Subscription Link --}}
                                        @if ($config->subscription_url)
                                            <button onclick="navigator.clipboard.writeText('{{ $config->subscription_url }}'); alert('لینک کپی شد!');" 
                                                    class="p-2 text-gray-600 dark:text-gray-400 hover:text-green-600 dark:hover:text-green-400 hover:bg-green-50 dark:hover:bg-green-900/30 rounded-lg transition-colors"
                                                    title="کپی لینک">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                                                </svg>
                                            </button>

                                            {{-- QR Code Button --}}
                                            <button @click="showQR = true; qrUrl = '{{ $config->subscription_url }}'" 
                                                    class="p-2 text-gray-600 dark:text-gray-400 hover:text-purple-600 dark:hover:text-purple-400 hover:bg-purple-50 dark:hover:bg-purple-900/30 rounded-lg transition-colors"
                                                    title="QR Code">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/>
                                                </svg>
                                            </button>
                                        @endif

                                        {{-- Toggle Status --}}
                                        <button wire:click="toggleStatus({{ $config->id }})" 
                                                wire:confirm="آیا مطمئن هستید؟"
                                                class="p-2 text-gray-600 dark:text-gray-400 hover:text-amber-600 dark:hover:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-900/30 rounded-lg transition-colors"
                                                title="{{ $config->isActive() ? 'غیرفعال کردن' : 'فعال کردن' }}">
                                            @if ($config->isActive())
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                                                </svg>
                                            @else
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                </svg>
                                            @endif
                                        </button>

                                        {{-- Delete Button --}}
                                        <button wire:click="deleteConfig({{ $config->id }})" 
                                                wire:confirm="آیا از حذف این کانفیگ اطمینان دارید؟"
                                                class="p-2 text-gray-600 dark:text-gray-400 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 rounded-lg transition-colors"
                                                title="حذف">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-12 text-center">
                                    <div class="flex flex-col items-center">
                                        <svg class="w-12 h-12 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>
                                        </svg>
                                        <p class="text-gray-500 dark:text-gray-400 text-sm">هیچ کانفیگی یافت نشد</p>
                                        <button wire:click="openCreateModal" class="mt-4 text-blue-600 dark:text-blue-400 hover:underline text-sm">
                                            اولین کانفیگ خود را ایجاد کنید
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination and Per Page Selector --}}
            <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <span class="text-sm text-gray-600 dark:text-gray-400">نمایش:</span>
                    <select wire:model.live="perPage" class="text-sm border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                        <option value="10">10</option>
                        <option value="20">20</option>
                        <option value="30">30</option>
                    </select>
                </div>
                <div>
                    {{ $configs->links() }}
                </div>
            </div>
        </div>
    @endif

    {{-- Create Config Modal --}}
    @if ($showCreateModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                {{-- Background Blur Overlay --}}
                <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm transition-opacity" wire:click="closeCreateModal"></div>

                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

                {{-- Modal Panel --}}
                <div class="inline-block align-bottom bg-white dark:bg-gray-800 rounded-xl text-right overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                    <div class="px-6 pt-5 pb-4">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">ایجاد کاربر جدید</h3>
                            <button wire:click="closeCreateModal" class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>

                        <form wire:submit="createConfig" class="space-y-4">
                            {{-- Panel Selection --}}
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">انتخاب پنل</label>
                                <select wire:model.live="selectedPanelId" x-model="selectedPanelId"
                                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">-- انتخاب کنید --</option>
                                    @foreach ($this->panels as $panel)
                                        <option value="{{ $panel->id }}">{{ $panel->name }} ({{ $panel->panel_type }})</option>
                                    @endforeach
                                </select>
                                @error('selectedPanelId') <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                {{-- Traffic Limit --}}
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">محدودیت ترافیک (GB)</label>
                                    <input type="number" wire:model="trafficLimitGb" step="0.1" min="0.1"
                                           class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-blue-500 focus:border-blue-500"
                                           placeholder="10">
                                    @error('trafficLimitGb') <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                                </div>

                                {{-- Expires Days --}}
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">مدت اعتبار (روز)</label>
                                    <input type="number" wire:model="expiresDays" min="1"
                                           class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-blue-500 focus:border-blue-500"
                                           placeholder="30">
                                    @error('expiresDays') <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                                </div>
                            </div>

                            {{-- Max Clients for Eylandoo --}}
                            <template x-if="selectedPanel && selectedPanel.panel_type === 'eylandoo'">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">حداکثر کلاینت‌های همزمان</label>
                                    <input type="number" wire:model="maxClients" min="1" max="100"
                                           class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-blue-500 focus:border-blue-500"
                                           placeholder="2">
                                </div>
                            </template>

                            {{-- Comment --}}
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">توضیحات (اختیاری)</label>
                                <input type="text" wire:model="comment" maxlength="200"
                                       class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="توضیحات کوتاه">
                            </div>

                            {{-- Marzneshin Services --}}
                            <template x-if="selectedPanel && selectedPanel.panel_type === 'marzneshin' && selectedPanel.services && selectedPanel.services.length > 0">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">سرویس‌ها</label>
                                    <div class="space-y-2 max-h-32 overflow-y-auto">
                                        <template x-for="service in selectedPanel.services" :key="service.id">
                                            <label class="flex items-center text-sm text-gray-700 dark:text-gray-300">
                                                <input type="checkbox" :value="service.id" wire:model="selectedServiceIds"
                                                       class="w-4 h-4 rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 ml-2">
                                                <span x-text="service.name"></span>
                                            </label>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            {{-- Eylandoo Nodes --}}
                            <template x-if="selectedPanel && selectedPanel.panel_type === 'eylandoo' && selectedPanel.nodes && selectedPanel.nodes.length > 0">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">نودها</label>
                                    <div class="space-y-2 max-h-32 overflow-y-auto">
                                        <template x-for="node in selectedPanel.nodes" :key="node.id">
                                            <label class="flex items-center text-sm text-gray-700 dark:text-gray-300">
                                                <input type="checkbox" :value="node.id" wire:model="selectedNodeIds"
                                                       class="w-4 h-4 rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 ml-2">
                                                <span x-text="node.name"></span>
                                            </label>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            {{-- Actions --}}
                            <div class="flex gap-3 pt-4">
                                <button type="submit" 
                                        class="flex-1 px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors"
                                        wire:loading.attr="disabled"
                                        wire:loading.class="opacity-50 cursor-wait">
                                    <span wire:loading.remove wire:target="createConfig">ایجاد کانفیگ</span>
                                    <span wire:loading wire:target="createConfig">در حال ایجاد...</span>
                                </button>
                                <button type="button" wire:click="closeCreateModal" 
                                        class="px-4 py-2.5 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 font-medium rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                                    انصراف
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Edit Config Modal --}}
    @if ($showEditModal && $editingConfigId)
        @php $editConfig = \App\Models\ResellerConfig::find($editingConfigId); @endphp
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                {{-- Background Blur Overlay --}}
                <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm transition-opacity" wire:click="closeEditModal"></div>

                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

                {{-- Modal Panel --}}
                <div class="inline-block align-bottom bg-white dark:bg-gray-800 rounded-xl text-right overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                    <div class="px-6 pt-5 pb-4">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">ویرایش کاربر</h3>
                            <button wire:click="closeEditModal" class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>

                        @if ($editConfig)
                            {{-- Config Info --}}
                            <div class="mb-4 p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    <strong>نام کاربری:</strong> {{ $editConfig->display_username ?: $editConfig->external_username }}
                                </p>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                                    <strong>مصرف فعلی:</strong> {{ round($editConfig->usage_bytes / (1024 * 1024 * 1024), 2) }} GB
                                </p>
                            </div>

                            <form wire:submit="updateConfig" class="space-y-4">
                                <div class="grid grid-cols-2 gap-4">
                                    {{-- Traffic Limit --}}
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">محدودیت ترافیک (GB)</label>
                                        <input type="number" wire:model="editTrafficLimitGb" step="0.1" min="0.1"
                                               class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-blue-500 focus:border-blue-500">
                                        @error('editTrafficLimitGb') <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                                    </div>

                                    {{-- Expires At --}}
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">تاریخ انقضا</label>
                                        <input type="date" wire:model="editExpiresAt" min="{{ now()->format('Y-m-d') }}"
                                               class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-blue-500 focus:border-blue-500">
                                        @error('editExpiresAt') <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                                    </div>
                                </div>

                                {{-- Max Clients for Eylandoo --}}
                                @if (strtolower(trim($editConfig->panel_type ?? '')) === 'eylandoo')
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">حداکثر کلاینت‌های همزمان</label>
                                        <input type="number" wire:model="editMaxClients" min="1" max="100"
                                               class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-blue-500 focus:border-blue-500">
                                    </div>
                                @endif

                                {{-- Actions --}}
                                <div class="flex gap-3 pt-4">
                                    <button type="submit" 
                                            class="flex-1 px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors"
                                            wire:loading.attr="disabled"
                                            wire:loading.class="opacity-50 cursor-wait">
                                        <span wire:loading.remove wire:target="updateConfig">ذخیره تغییرات</span>
                                        <span wire:loading wire:target="updateConfig">در حال ذخیره...</span>
                                    </button>
                                    <button type="button" wire:click="closeEditModal" 
                                            class="px-4 py-2.5 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 font-medium rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                                        انصراف
                                    </button>
                                </div>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- QR Code Modal --}}
    <div x-show="showQR" 
         x-cloak
         class="fixed inset-0 z-50 overflow-y-auto"
         x-transition:enter="ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm" @click="showQR = false"></div>
            <div class="relative bg-white dark:bg-gray-800 rounded-xl p-6 max-w-sm w-full shadow-xl">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">QR Code</h3>
                    <button @click="showQR = false" class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                <div x-ref="qrContainer" class="flex justify-center bg-white p-4 rounded-lg">
                    {{-- QR will be generated here --}}
                </div>
            </div>
        </div>
    </div>

    {{-- QR Code Script --}}
    @push('scripts')
    <script src="{{ asset('vendor/qrcode.min.js') }}"></script>
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.effect(() => {
                const data = Alpine.store('qrData');
            });
        });
    </script>
    @endpush
</div>
