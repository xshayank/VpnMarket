{{-- 
    Theme Initialization Script
    This script runs immediately in <head> BEFORE CSS loads to prevent flash of unstyled content (FOUC).
    It reads stored theme preference or system preference and applies the 'dark' class synchronously.
--}}
<script>
(function() {
    // Get stored theme from localStorage or cookie
    function getStoredTheme() {
        try {
            var stored = localStorage.getItem('theme');
            if (stored === 'dark' || stored === 'light') {
                return stored;
            }
        } catch (e) {
            // localStorage might not be available
        }
        
        // Fallback to cookie
        var match = document.cookie.match(/(?:^|; )theme=([^;]*)/);
        if (match && (match[1] === 'dark' || match[1] === 'light')) {
            return match[1];
        }
        
        return null;
    }
    
    // Get system preference
    function getSystemPreference() {
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            return 'dark';
        }
        return 'light';
    }
    
    // Determine and apply theme
    var storedTheme = getStoredTheme();
    var theme = storedTheme || getSystemPreference();
    
    // Apply theme immediately
    if (theme === 'dark') {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }
    
    // If no stored preference, store the resolved value
    if (!storedTheme) {
        try {
            localStorage.setItem('theme', theme);
            document.cookie = 'theme=' + theme + ';path=/;max-age=31536000;SameSite=Lax';
        } catch (e) {
            // localStorage might not be available
        }
    }
})();
</script>
