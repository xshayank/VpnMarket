# Reseller Panel UI Overhaul - Marzban Style

This document describes the UI changes implemented for the reseller panel (`/reseller`) to match the Marzban-style layout and interactions.

## Overview

The reseller dashboard has been redesigned with a modern, Marzban-inspired UI that provides:

- Compact stats header with visual cards
- Integrated users/configs management with table view
- Modal-based create/edit functionality with blur backdrop
- Enhanced filtering, search, and pagination controls

## Key Features

### 1. Compact Stats Header

Four gradient-styled cards displayed horizontally at the top:
- **Balance/Traffic Remaining**: Shows wallet balance (wallet-based) or remaining traffic (traffic-based)
- **Used Traffic**: Total traffic consumed across all configs
- **Traffic Price**: Price per GB for the reseller
- **Active Configs**: Count of active configs out of total

### 2. Users Section

#### Controls Row
- **Search Input**: Filter by username prefix or comment
- **Tab Filters**: All, Active, Disabled, Expiring Soon
- **Refresh Button**: Reload the list without page refresh
- **Create User Button**: Opens the create modal

#### Table Columns
- **Username**: Displays only the prefix (not full `panel_username`) using `getDisplayUsernameAttribute()`
- **Status**: Badge with Active/Disabled state and animated indicator
- **Expiry Date**: Shows days remaining with color-coded warnings
- **Data Usage**: Horizontal progress bar with percentage and total usage

#### Row Actions
- **Edit** (pencil icon): Opens edit modal
- **Copy Link** (chain icon): Copies subscription URL to clipboard
- **QR Code** (grid icon): Opens QR code modal
- **Toggle Status**: Enable/disable config
- **Delete** (trash icon): Removes config with confirmation

### 3. Modal Behavior

Both Create and Edit modals feature:
- **Blur backdrop**: `backdrop-blur-sm` effect behind the modal
- **Centered positioning**: Responsive on all screen sizes
- **Smooth transitions**: Using Tailwind's transition classes
- **Form validation**: Client and server-side validation
- **Loading states**: Visual feedback during form submission

### 4. Username Display Rule

**Important**: The UI always shows the sanitized prefix (from `username_prefix` column or extracted from `external_username`), NOT the full `panel_username` that's stored in the VPN panel.

This is achieved through the `getDisplayUsernameAttribute()` accessor in the `ResellerConfig` model:

```php
public function getDisplayUsernameAttribute(): string
{
    // Priority: username_prefix > extracted from external_username > external_username
    if ($this->username_prefix !== null && $this->username_prefix !== '') {
        return $this->username_prefix;
    }
    // Fallback: extract prefix from external_username
    // ...
}
```

### 5. Pagination and Tabs

- **Items Per Page**: Selector offering 10/20/30 options
- **Tab Filters**:
  - All: Shows all configs
  - Active: Only status='active'
  - Disabled: Only status='disabled'
  - Expiring Soon: Active configs expiring within 7 days

## Components

### Livewire Component

`App\Livewire\Reseller\ConfigsManager` handles:
- Paginated config listing with search/filter
- Modal state management (create/edit)
- CRUD operations for configs
- Stats loading and refresh

### Progress Bar Component

`resources/views/components/progress-bar.blade.php` is a reusable component:

```blade
<x-progress-bar 
    :used="$usageBytes" 
    :limit="$limitBytes" 
    :settled="$settledBytes" 
    unit="GB" 
/>
```

Props:
- `used`: Current usage value
- `limit`: Maximum limit
- `settled`: Previously settled/reset usage
- `showText`: Display usage text below bar
- `showTotal`: Show total (used + settled)
- `size`: sm/md/lg
- `unit`: Display unit (default: GB)
- `precision`: Decimal places (default: 2)

## Styling

The UI uses Tailwind CSS with:
- **Gradient cards**: `bg-gradient-to-br from-{color}-500 to-{color}-600`
- **Dark mode support**: All elements have dark mode variants
- **Responsive design**: Mobile-first with breakpoints at sm/md/lg
- **RTL support**: `dir="rtl"` with proper text alignment

## File Changes

- `Modules/Reseller/resources/views/dashboard.blade.php` - Updated to use Livewire component
- `app/Livewire/Reseller/ConfigsManager.php` - New Livewire component
- `resources/views/livewire/reseller/configs-manager.blade.php` - Livewire view
- `resources/views/components/progress-bar.blade.php` - Reusable progress bar

## Dependencies

- **Livewire 3.x**: For reactive UI components
- **Alpine.js**: For client-side interactivity (bundled with Livewire)
- **Tailwind CSS**: For styling (already in project)
- **QRCode.js**: For QR code generation (`/vendor/qrcode.min.js`)
