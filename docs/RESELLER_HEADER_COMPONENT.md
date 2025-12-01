# Reseller Header Component (Marzban Style)

This document describes the new Marzban-style header component for reseller pages.

## Overview

A new reusable header component has been created at `resources/views/components/reseller/header.blade.php` that provides a modern, Marzban-inspired header design for all reseller pages. The component replaces the previous dark gradient navigation bar with a clean, light header bar matching the Marzban panel aesthetic.

## Features

### Visual Design
- **Light header bar** with subtle shadow (`shadow-sm`)
- **Compact height** (h-14 on mobile, h-16 on desktop)
- **Rounded action buttons** with hover states
- **Heroicons/Filament-style iconography**
- **Sticky positioning** for easy access while scrolling

### Layout Structure

**Left Section:**
- App logo/brand linking to reseller dashboard
- Page title (dynamically determined from current route)
- Subtitle showing reseller name

**Right Section (Desktop):**
- Refresh button (icon-only, shown on dashboard)
- Dashboard link
- Wallet link
- Tickets link (if Ticketing module enabled)
- API Keys link (if API enabled for reseller)
- Theme toggle (light/dark)
- User profile dropdown with Settings and Logout

**Mobile:**
- Compact view with hamburger menu
- Full dropdown menu listing all actions
- Touch-friendly button sizes

### Responsive Behavior
- On screens smaller than `md` (768px), desktop actions collapse into a kebab/hamburger menu
- Mobile menu includes all navigation items with larger touch targets
- Title shown on small screens with truncation

### RTL/LTR Support
- Component respects the document's `dir` attribute
- Uses Tailwind directional utilities for proper spacing

## Usage

The component is automatically included via `partials/reseller-nav.blade.php` for all pages using the `x-app-layout` when the user is a reseller.

### Props

```blade
<x-reseller.header 
    :title="$customTitle"      {{-- Optional: Override auto-detected title --}}
    :subtitle="$customSubtitle" {{-- Optional: Override reseller name subtitle --}}
/>
```

If `title` is not provided, it will be auto-detected based on the current route:
- `reseller.dashboard` → "کاربران"
- `reseller.tickets.*` → "تیکت‌ها"
- `reseller.api-keys.*` → "کلیدهای API"
- `reseller.plans.*` → "پلن‌ها"
- `reseller.configs.*` → "کانفیگ‌ها"
- Default → "داشبورد ریسلر"

## Key Actions

| Action | Desktop | Mobile | Notes |
|--------|---------|--------|-------|
| Refresh | Icon button | Dropdown item | Only shown on dashboard |
| Dashboard | Icon link | Dropdown link | Active state shown |
| Wallet | Icon link | Dropdown link | Always available |
| Tickets | Icon link | Dropdown link | Only if Ticketing module enabled |
| API Keys | Icon link | Dropdown link | Only if `api_enabled` on reseller |
| Theme Toggle | Icon button | Dropdown button | Persists to localStorage |
| Settings | Dropdown | Dropdown link | Links to profile.edit |
| Logout | Dropdown | Dropdown button | Form submission |

## Accessibility

- All interactive elements have `aria-label` attributes
- Icons include `aria-hidden="true"`
- Mobile menu uses `aria-expanded` state
- Keyboard navigation supported for dropdowns
- Focus indicators on all buttons/links

## Theme Toggle Implementation

The theme toggle button uses Alpine.js to:
1. Check current dark mode state from `document.documentElement.classList`
2. Toggle the `dark` class on the HTML element
3. Persist preference to `localStorage` with key `theme`

## Technical Details

### File Locations
- Component: `resources/views/components/reseller/header.blade.php`
- Partial: `resources/views/partials/reseller-nav.blade.php` (includes component)
- Layout: `resources/views/layouts/app.blade.php` (conditionally hides extra header for resellers)

### Dependencies
- **Alpine.js**: For mobile menu toggle and theme switching (bundled with Livewire)
- **Tailwind CSS**: For all styling
- **Heroicons**: For all icons (inline SVG)

### No External CSS
The component uses only Tailwind utilities to avoid any CSP violations. No external CSS imports are required.

## Styling Classes Reference

Header container:
```
bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 shadow-sm sticky top-0 z-40
```

Icon buttons:
```
p-2 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 
hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors
```

Active state:
```
bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100
```

Mobile menu items:
```
flex items-center gap-3 px-3 py-2.5 text-sm font-medium rounded-lg transition-colors
```

## Reuse Guidance

To reuse this header component on other pages:

1. Include via the partial:
```blade
@include('partials.reseller-nav')
```

2. Or directly use the component with custom props:
```blade
<x-reseller.header title="Custom Page Title" subtitle="Custom subtitle" />
```

The component will only render if the authenticated user has an associated reseller record.

## Migration Notes

Pages using the old header slot pattern (`<x-slot name="header">`) will no longer see the duplicate header for reseller users, as the layout now conditionally hides it when the new component is active.
