# Reseller Panel UI Overhaul - Marzban Style

This document describes the UI changes implemented for the reseller panel (`/reseller`) to match the Marzban-style layout and interactions.

## Overview

The reseller dashboard has been redesigned with a modern, Marzban-inspired UI that provides:

- Compact stats header with visual cards
- Integrated users/configs management with table view
- Modal-based create/edit functionality with blur backdrop
- Enhanced filtering, search, and pagination controls
- QR code generation for subscription URLs
- Full interactivity with Livewire-powered buttons

## Key Features

### 1. Compact Stats Header

Four gradient-styled cards displayed horizontally at the top:
- **Balance/Traffic Remaining**: Shows wallet balance (wallet-based) or remaining traffic (traffic-based)
- **Used Traffic**: Total traffic consumed across all configs
- **Traffic Price**: Price per GB for the reseller
- **Active Configs**: Count of active configs out of total

Header action buttons:
- **Refresh Button**: Reloads stats from the server
- **Charge Wallet**: Link to wallet charge form

### 2. Users Section

#### Controls Row
- **Search Input**: Debounced search (300ms) by username prefix or comment (case-insensitive)
- **Tab Filters**: All, Active, Disabled, Expiring Soon (7 days)
- **Refresh Button**: Reload the list without page refresh
- **Create User Button**: Opens the create modal

All filter and action buttons use `type="button"` to prevent unintended form submissions.

#### Table Columns
- **Username**: Displays only the prefix (not full `panel_username`) using `getDisplayUsernameAttribute()`
- **Status**: Badge with Active/Disabled state and animated indicator
- **Expiry Date**: Shows days remaining with color-coded warnings
- **Data Usage**: Horizontal progress bar with percentage and total usage
  - Blue (<80% usage)
  - Amber (80-95% usage)
  - Red (>95% usage)

#### Row Actions
All row actions include loading states and use `type="button"` to prevent form submits:
- **Edit** (pencil icon): Opens edit modal
- **Copy Link** (chain icon): Copies subscription URL to clipboard
- **QR Code** (grid icon): Opens QR code modal with generated QR
- **Toggle Status**: Enable/disable config with confirmation
- **Delete** (trash icon): Removes config with confirmation

### 3. Modal Behavior

Both Create and Edit modals feature:
- **Blur backdrop**: `backdrop-blur-sm` effect with `bg-gray-900/60` overlay
- **Centered positioning**: Responsive on all screen sizes
- **Smooth transitions**: Using Tailwind's transition classes
- **Form validation**: Server-side validation with error display
- **Loading states**: Visual feedback during form submission
- **Close on backdrop click**: Click outside modal to close it

#### Edit Modal Features
- Displays config info (username prefix and current usage)
- Copy subscription link button
- QR code button (opens QR modal)
- Form fields for traffic limit, expiry date, and max clients (Eylandoo panels)

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

### 5. Search and Filters

- **Search**: Debounced (300ms) search that matches:
  - `external_username` containing the search term
  - `username_prefix` containing the search term
  - `comment` containing the search term
- Search term is preserved across pagination and tab switches via URL query parameters

### 6. Pagination and Tabs

- **Items Per Page**: Selector offering 10/20/30 options
- **Tab Filters**:
  - All: Shows all configs
  - Active: Only status='active'
  - Disabled: Only status='disabled'
  - Expiring Soon: Active configs expiring within 7 days
- Changing tab or per-page resets pagination to page 1

### 7. QR Code Modal

- Generates QR code on-the-fly using QRCode.js library
- Shows subscription URL as QR code with white background
- Includes "Copy Link" button below QR code
- Backdrop blur effect for modal overlay
- Click outside to close

## Components

### Livewire Component

`App\Livewire\Reseller\ConfigsManager` handles:
- Paginated config listing with search/filter
- Modal state management (create/edit)
- CRUD operations for configs
- Stats loading and refresh
- URL-synced search, filter, and pagination parameters

### Livewire Methods

All buttons are wired to these Livewire methods:
- `setStatusFilter($status)` - Tab filtering
- `openCreateModal()` / `closeCreateModal()` - Create modal
- `openEditModal($configId)` / `closeEditModal()` - Edit modal
- `syncStats()` - Refresh stats
- `toggleStatus($configId)` - Enable/disable config
- `deleteConfig($configId)` - Remove config
- `createConfig()` - Form submission for create
- `updateConfig()` - Form submission for edit

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
- **Button type safety**: All non-submit buttons use `type="button"`
- **Loading indicators**: Spinner animations during Livewire actions

## File Changes

- `Modules/Reseller/resources/views/dashboard.blade.php` - Updated to use Livewire component
- `app/Livewire/Reseller/ConfigsManager.php` - Livewire component with full CRUD
- `resources/views/livewire/reseller/configs-manager.blade.php` - Livewire view with modals
- `resources/views/components/progress-bar.blade.php` - Reusable progress bar

## Dependencies

- **Livewire 3.x**: For reactive UI components
- **Alpine.js**: For client-side interactivity (bundled with Livewire)
- **Tailwind CSS**: For styling (already in project)
- **QRCode.js**: For QR code generation (`/vendor/qrcode.min.js`)

## Testing

Tests are available in:
- `tests/Feature/ResellerConfigsManagerTest.php` - Basic component tests
- `tests/Feature/ResellerConfigsManagerButtonsTest.php` - Comprehensive button/action tests

Run tests with:
```bash
./vendor/bin/pest tests/Feature/ResellerConfigsManager*
```
