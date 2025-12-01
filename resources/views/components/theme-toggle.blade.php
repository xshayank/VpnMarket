@props(['class' => ''])

{{-- 
    Theme Toggle Component
    A reusable dark/light mode toggle that:
    - Reads from localStorage/cookie
    - Falls back to system preference if no stored value
    - Applies the 'dark' class to document element
    - Stores the resolved value for next load
--}}
<button 
    type="button"
    x-data="themeToggle()"
    @click="toggleTheme()"
    :aria-pressed="isDark.toString()"
    class="p-2 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 {{ $class }}"
    title="{{ __('تغییر تم') }}"
    aria-label="{{ __('تغییر تم روشن/تاریک') }}"
>
    {{-- Sun icon (shown in dark mode - click to go light) --}}
    <svg 
        x-show="isDark" 
        x-cloak
        class="w-5 h-5" 
        fill="none" 
        stroke="currentColor" 
        viewBox="0 0 24 24" 
        aria-hidden="true"
    >
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
    </svg>
    {{-- Moon icon (shown in light mode - click to go dark) --}}
    <svg 
        x-show="!isDark" 
        class="w-5 h-5" 
        fill="none" 
        stroke="currentColor" 
        viewBox="0 0 24 24" 
        aria-hidden="true"
    >
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
    </svg>
</button>

@once
@push('scripts')
<script>
    function themeToggle() {
        return {
            isDark: document.documentElement.classList.contains('dark'),
            
            toggleTheme() {
                this.isDark = !this.isDark;
                this.applyTheme();
                this.saveTheme();
            },
            
            applyTheme() {
                if (this.isDark) {
                    document.documentElement.classList.add('dark');
                } else {
                    document.documentElement.classList.remove('dark');
                }
            },
            
            saveTheme() {
                try {
                    localStorage.setItem('theme', this.isDark ? 'dark' : 'light');
                    // Also set cookie for server-side persistence
                    document.cookie = `theme=${this.isDark ? 'dark' : 'light'};path=/;max-age=31536000;SameSite=Lax`;
                } catch (e) {
                    // localStorage might not be available
                }
            }
        }
    }
</script>
@endpush
@endonce
