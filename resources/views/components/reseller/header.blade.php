@props(['title' => null, 'subtitle' => null])

@php
    use Illuminate\Support\Facades\Storage;
    use App\Models\Setting;
    use Illuminate\Support\Facades\Cache;

    // Only fetch site_logo setting, with caching for performance
    $logoPath = Cache::remember('site_logo', 3600, function () {
        return Setting::where('key', 'site_logo')->value('value');
    });
    $reseller = auth()->user()->reseller ?? null;
    
    // Derive page title from route if not provided
    if (!$title) {
        if (request()->routeIs('reseller.dashboard')) {
            $title = __('کاربران');
        } elseif (request()->routeIs('reseller.tickets.*')) {
            $title = __('تیکت‌ها');
        } elseif (request()->routeIs('reseller.api-keys.*')) {
            $title = __('کلیدهای API');
        } elseif (request()->routeIs('reseller.plans.*')) {
            $title = __('پلن‌ها');
        } elseif (request()->routeIs('reseller.configs.*')) {
            $title = __('کانفیگ‌ها');
        } else {
            $title = __('داشبورد ریسلر');
        }
    }
@endphp

@auth
    @if($reseller)
        {{-- Marzban-style Header Bar --}}
        <header 
            x-data="{ mobileMenuOpen: false }" 
            class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 shadow-sm sticky top-0 z-40"
        >
            <div class="max-w-7xl mx-auto px-3 sm:px-4 lg:px-6">
                <div class="flex items-center justify-between h-14 md:h-16">
                    
                    {{-- Left Section: Logo + Title --}}
                    <div class="flex items-center gap-3 min-w-0 flex-shrink-0">
                        {{-- Logo --}}
                        <a href="{{ route('reseller.dashboard') }}" class="flex-shrink-0" aria-label="داشبورد">
                            <img 
                                src="{{ $logoPath ? Storage::url($logoPath) : asset('images/default-logo.png') }}"
                                alt="Logo"
                                class="h-8 w-auto object-contain"
                            >
                        </a>
                        
                        {{-- Title + Subtitle --}}
                        <div class="hidden sm:block border-r border-gray-200 dark:border-gray-600 pr-3 mr-1">
                            <h1 class="text-sm md:text-base font-semibold text-gray-900 dark:text-gray-100 truncate">
                                {{ $title ?? __('کاربران') }}
                            </h1>
                            @if($subtitle)
                                <p class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ $subtitle }}</p>
                            @else
                                <p class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ $reseller->user->name ?? 'ریسلر' }}</p>
                            @endif
                        </div>
                    </div>

                    {{-- Desktop Actions (hidden on mobile) --}}
                    <div class="hidden md:flex items-center gap-1 lg:gap-2">
                        
                        {{-- Refresh Button --}}
                        @if(request()->routeIs('reseller.dashboard'))
                            <button 
                                type="button"
                                onclick="window.location.reload()"
                                class="p-2 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors"
                                title="بروزرسانی"
                                aria-label="بروزرسانی صفحه"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                </svg>
                            </button>
                        @endif

                        {{-- Dashboard Link --}}
                        <a 
                            href="{{ route('reseller.dashboard') }}"
                            class="p-2 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors {{ request()->routeIs('reseller.dashboard') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100' : '' }}"
                            title="داشبورد"
                            aria-label="داشبورد"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                            </svg>
                        </a>

                        {{-- Wallet --}}
                        <a 
                            href="{{ route('wallet.charge.form') }}"
                            class="p-2 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors"
                            title="کیف پول"
                            aria-label="شارژ کیف پول"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                            </svg>
                        </a>

                        {{-- Tickets (if Ticketing module enabled) --}}
                        @if(\Nwidart\Modules\Facades\Module::isEnabled('Ticketing'))
                            <a 
                                href="{{ route('reseller.tickets.index') }}"
                                class="p-2 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors {{ request()->routeIs('reseller.tickets.*') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100' : '' }}"
                                title="تیکت‌ها"
                                aria-label="تیکت‌های پشتیبانی"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/>
                                </svg>
                            </a>
                        @endif

                        {{-- API Keys (if enabled) --}}
                        @if($reseller->api_enabled ?? false)
                            <a 
                                href="{{ route('reseller.api-keys.index') }}"
                                class="p-2 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors {{ request()->routeIs('reseller.api-keys.*') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100' : '' }}"
                                title="کلیدهای API"
                                aria-label="مدیریت کلیدهای API"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                                </svg>
                            </a>
                        @endif

                        {{-- Divider --}}
                        <div class="h-6 w-px bg-gray-200 dark:bg-gray-600 mx-1"></div>

                        {{-- Theme Toggle --}}
                        <button 
                            type="button"
                            x-data="{ darkMode: document.documentElement.classList.contains('dark') }"
                            @click="darkMode = !darkMode; document.documentElement.classList.toggle('dark'); localStorage.setItem('theme', darkMode ? 'dark' : 'light'); document.cookie = 'theme=' + (darkMode ? 'dark' : 'light') + ';path=/;max-age=31536000;SameSite=Lax'"
                            :aria-pressed="darkMode.toString()"
                            class="p-2 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800"
                            title="تغییر تم"
                            aria-label="تغییر تم روشن/تاریک"
                        >
                            {{-- Sun icon (shown in dark mode - click to go light) --}}
                            <svg x-show="darkMode" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                            </svg>
                            {{-- Moon icon (shown in light mode - click to go dark) --}}
                            <svg x-show="!darkMode" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                            </svg>
                        </button>

                        {{-- User Settings Dropdown --}}
                        <x-dropdown align="left" width="48" :contentClasses="'py-1 bg-white dark:bg-gray-800 text-right'">
                            <x-slot name="trigger">
                                <button 
                                    type="button" 
                                    class="flex items-center gap-2 p-2 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors"
                                    aria-label="منوی کاربر"
                                >
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                    </svg>
                                    <span class="hidden lg:inline text-sm font-medium text-gray-700 dark:text-gray-200">{{ Auth::user()->name }}</span>
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                </button>
                            </x-slot>

                            <x-slot name="content">
                                <x-dropdown-link :href="route('profile.edit')">
                                    <span class="flex items-center gap-2">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        </svg>
                                        تنظیمات
                                    </span>
                                </x-dropdown-link>

                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <x-dropdown-link :href="route('logout')"
                                                     onclick="event.preventDefault(); this.closest('form').submit();">
                                        <span class="flex items-center gap-2 text-red-600 dark:text-red-400">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                                            </svg>
                                            خروج
                                        </span>
                                    </x-dropdown-link>
                                </form>
                            </x-slot>
                        </x-dropdown>
                    </div>

                    {{-- Mobile Menu Button --}}
                    <div class="flex md:hidden items-center gap-2">
                        {{-- Mobile Title (visible on small screens) --}}
                        <span class="sm:hidden text-sm font-semibold text-gray-900 dark:text-gray-100 truncate max-w-[120px]">
                            {{ $title ?? __('کاربران') }}
                        </span>
                        
                        <button 
                            type="button"
                            @click="mobileMenuOpen = !mobileMenuOpen"
                            class="p-2 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors"
                            :aria-expanded="mobileMenuOpen"
                            aria-label="منوی موبایل"
                        >
                            {{-- Hamburger icon --}}
                            <svg x-show="!mobileMenuOpen" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                            </svg>
                            {{-- Close icon --}}
                            <svg x-show="mobileMenuOpen" x-cloak class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            {{-- Mobile Menu Dropdown --}}
            <div 
                x-show="mobileMenuOpen" 
                x-cloak
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 -translate-y-2"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 -translate-y-2"
                class="md:hidden border-t border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800"
            >
                <div class="px-3 py-3 space-y-1">
                    {{-- User Info --}}
                    <div class="px-3 py-2 border-b border-gray-200 dark:border-gray-700 mb-2">
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ Auth::user()->name }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ Auth::user()->email }}</p>
                    </div>

                    {{-- Dashboard --}}
                    <a 
                        href="{{ route('reseller.dashboard') }}"
                        class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium rounded-lg transition-colors {{ request()->routeIs('reseller.dashboard') ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700' }}"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                        داشبورد
                    </a>

                    {{-- Wallet --}}
                    <a 
                        href="{{ route('wallet.charge.form') }}"
                        class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium rounded-lg text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                        </svg>
                        کیف پول
                    </a>

                    {{-- Tickets --}}
                    @if(\Nwidart\Modules\Facades\Module::isEnabled('Ticketing'))
                        <a 
                            href="{{ route('reseller.tickets.index') }}"
                            class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium rounded-lg transition-colors {{ request()->routeIs('reseller.tickets.*') ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700' }}"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/>
                            </svg>
                            تیکت‌ها
                        </a>
                    @endif

                    {{-- API Keys --}}
                    @if($reseller->api_enabled ?? false)
                        <a 
                            href="{{ route('reseller.api-keys.index') }}"
                            class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium rounded-lg transition-colors {{ request()->routeIs('reseller.api-keys.*') ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700' }}"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                            </svg>
                            کلیدهای API
                        </a>
                    @endif

                    {{-- Divider --}}
                    <div class="border-t border-gray-200 dark:border-gray-700 my-2"></div>

                    {{-- Settings --}}
                    <a 
                        href="{{ route('profile.edit') }}"
                        class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium rounded-lg text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        تنظیمات
                    </a>

                    {{-- Theme Toggle --}}
                    <button 
                        type="button"
                        x-data="{ darkMode: document.documentElement.classList.contains('dark') }"
                        @click="darkMode = !darkMode; document.documentElement.classList.toggle('dark'); localStorage.setItem('theme', darkMode ? 'dark' : 'light'); document.cookie = 'theme=' + (darkMode ? 'dark' : 'light') + ';path=/;max-age=31536000;SameSite=Lax'"
                        :aria-pressed="darkMode.toString()"
                        class="flex items-center gap-3 w-full px-3 py-2.5 text-sm font-medium rounded-lg text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors text-right focus:outline-none focus:ring-2 focus:ring-blue-500"
                        aria-label="تغییر تم روشن/تاریک"
                    >
                        <svg x-show="darkMode" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                        <svg x-show="!darkMode" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                        </svg>
                        <span x-text="darkMode ? 'حالت روشن' : 'حالت تاریک'"></span>
                    </button>

                    {{-- Refresh (only on dashboard) --}}
                    @if(request()->routeIs('reseller.dashboard'))
                        <button 
                            type="button"
                            onclick="window.location.reload()"
                            class="flex items-center gap-3 w-full px-3 py-2.5 text-sm font-medium rounded-lg text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors text-right"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                            بروزرسانی
                        </button>
                    @endif

                    {{-- Logout --}}
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button 
                            type="submit"
                            class="flex items-center gap-3 w-full px-3 py-2.5 text-sm font-medium rounded-lg text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors text-right"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                            </svg>
                            خروج از حساب
                        </button>
                    </form>
                </div>
            </div>
        </header>
    @endif
@endauth
