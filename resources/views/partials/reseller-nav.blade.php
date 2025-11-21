@auth
    @if(Auth::user() && Auth::user()->reseller)
        <div class="bg-gradient-to-r from-gray-800 to-gray-900 dark:from-gray-900 dark:to-black border-b border-gray-700">
            <div class="max-w-7xl mx-auto px-2 sm:px-4 md:px-6 lg:px-8">
                <nav class="flex items-center justify-between gap-3 py-2 md:py-3">
                    <div class="flex items-center flex-1 space-x-1 space-x-reverse overflow-x-auto scrollbar-hide">
                    {{-- Reseller Dashboard --}}
                    <a href="{{ route('reseller.dashboard') }}"
                       class="flex items-center px-2 md:px-3 py-2 text-xs md:text-sm font-medium rounded-lg transition-colors duration-150 whitespace-nowrap
                              {{ request()->routeIs('reseller.dashboard') 
                                  ? 'bg-gray-700 text-white' 
                                  : 'text-gray-100 hover:bg-gray-700 hover:text-white' }}"
                       title="داشبورد ریسلر">
                        <svg class="w-4 h-4 md:w-5 md:h-5 ml-1 md:ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                  d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                           <span>داشبورد</span>
                    </a>

                    @if(Auth::user()->reseller && method_exists(Auth::user()->reseller, 'isPlanBased') && Auth::user()->reseller->isPlanBased())
                        {{-- Plans (Plan-based resellers) --}}
                        <a href="{{ route('reseller.plans.index') }}" 
                           class="flex items-center px-2 md:px-3 py-2 text-xs md:text-sm font-medium rounded-lg transition-colors duration-150 whitespace-nowrap
                                  {{ request()->routeIs('reseller.plans.*') 
                                      ? 'bg-gray-700 text-white' 
                                      : 'text-gray-100 hover:bg-gray-700 hover:text-white' }}"
                           title="پلن‌ها">
                            <svg class="w-4 h-4 md:w-5 md:h-5 ml-1 md:ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                      d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                            </svg>
                            <span>پلن‌ها</span>
                        </a>

                        {{-- Bulk Orders --}}
                        <a href="{{ route('reseller.plans.index') }}" 
                           class="flex items-center px-2 md:px-3 py-2 text-xs md:text-sm font-medium rounded-lg transition-colors duration-150 whitespace-nowrap
                                  text-gray-100 hover:bg-gray-700 hover:text-white"
                           title="سفارشات انبوه">
                            <svg class="w-4 h-4 md:w-5 md:h-5 ml-1 md:ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                      d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
                            </svg>
                            <span>سفارشات</span>
                        </a>
                    @else
                        {{-- Configs (Traffic-based resellers) --}}
                        <a href="{{ route('reseller.configs.index') }}" 
                           class="flex items-center px-2 md:px-3 py-2 text-xs md:text-sm font-medium rounded-lg transition-colors duration-150 whitespace-nowrap
                                  {{ request()->routeIs('reseller.configs.*') 
                                      ? 'bg-gray-700 text-white' 
                                      : 'text-gray-100 hover:bg-gray-700 hover:text-white' }}"
                           title="کانفیگ‌ها">
                            <svg class="w-4 h-4 md:w-5 md:h-5 ml-1 md:ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                      d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                      d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                            <span>کانفیگ‌ها</span>
                        </a>
                    @endif

                    {{-- Wallet --}}
                    <a href="{{ route('wallet.charge.form') }}" 
                       class="flex items-center px-2 md:px-3 py-2 text-xs md:text-sm font-medium rounded-lg transition-colors duration-150 whitespace-nowrap
                              text-gray-100 hover:bg-gray-700 hover:text-white"
                       title="کیف پول">
                        <svg class="w-4 h-4 md:w-5 md:h-5 ml-1 md:ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                  d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                        </svg>
                        <span>کیف پول</span>
                    </a>

                    @if(\Nwidart\Modules\Facades\Module::isEnabled('Ticketing'))
                        {{-- Tickets --}}
                        <a href="{{ route('reseller.tickets.index') }}" 
                           class="flex items-center px-2 md:px-3 py-2 text-xs md:text-sm font-medium rounded-lg transition-colors duration-150 whitespace-nowrap
                                  {{ request()->routeIs('reseller.tickets.*') 
                                      ? 'bg-gray-700 text-white' 
                                      : 'text-gray-100 hover:bg-gray-700 hover:text-white' }}"
                           title="تیکت‌ها">
                            <svg class="w-4 h-4 md:w-5 md:h-5 ml-1 md:ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                      d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/>
                            </svg>
                            <span>تیکت‌ها</span>
                        </a>
                    @endif
                    </div>

                    <div class="flex items-center flex-shrink-0 text-gray-100 space-x-2 space-x-reverse">
                        <span class="hidden sm:block text-sm md:text-base font-medium">{{ Auth::user()->name }}</span>
                        <x-dropdown align="left" width="48" :contentClasses="'py-1 bg-white dark:bg-gray-800 text-right'">
                            <x-slot name="trigger">
                                <button class="inline-flex items-center px-2 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-100 bg-gray-800 hover:bg-gray-700 focus:outline-none transition ease-in-out duration-150">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A9 9 0 1118.88 6.196 9 9 0 015.12 17.804z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                    <svg class="ms-1 w-4 h-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.25a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </x-slot>

                            <x-slot name="content">
                                <x-dropdown-link :href="route('profile.edit')">
                                    پروفایل
                                </x-dropdown-link>

                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <x-dropdown-link :href="route('logout')"
                                                     onclick="event.preventDefault(); this.closest('form').submit();">
                                        خروج از حساب
                                    </x-dropdown-link>
                                </form>
                            </x-slot>
                        </x-dropdown>
                    </div>
                </nav>
            </div>
        </div>
    @endif
@endauth
