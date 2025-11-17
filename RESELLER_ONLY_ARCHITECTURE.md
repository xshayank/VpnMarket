# Reseller-Only Architecture Implementation

## Overview

This implementation transforms the VpnMarket system from a mixed user/reseller model to a **reseller-only architecture**. Every new registration creates a reseller account (either wallet-based or traffic-based) that starts in a suspended state until the first minimum top-up threshold is met.

## Key Changes

### 1. User Registration

All new users are automatically created as resellers with the following workflow:

1. **Registration Form** (`/register`):
   - User selects reseller type: `wallet` or `traffic`
   - User selects a primary panel from active panels
   - For Eylandoo panels: optional node selection
   - For Marzneshin panels: optional service selection

2. **Initial Status**:
   - Wallet-based resellers: `suspended_wallet`
   - Traffic-based resellers: `suspended_traffic`

3. **Redirect**: After registration, users are redirected to `/wallet/charge` with appropriate messaging

### 2. Reseller Types

#### Wallet-Based Resellers
- Type: `wallet`
- Billing: Pre-paid wallet balance in تومان
- First top-up minimum: 150,000 تومان (configurable via `MIN_FIRST_WALLET_TOPUP`)
- Subsequent top-up minimum: 50,000 تومان (configurable via `MIN_WALLET_TOPUP`)
- Config limit: 1000 configs (configurable)

#### Traffic-Based Resellers
- Type: `traffic`
- Billing: Pre-purchased traffic in GB
- First purchase minimum: 250 GB (configurable via `MIN_FIRST_TRAFFIC_TOPUP_GB`)
- Subsequent purchase minimum: 50 GB (configurable via `MIN_TRAFFIC_TOPUP_GB`)
- Price: 750 تومان/GB (configurable via `TRAFFIC_RESELLER_GB_RATE`)
- Config limit: Unlimited

### 3. Database Schema

#### New Reseller Fields
```php
- primary_panel_id: Foreign key to panels table
- max_configs: Integer (nullable, 1000 for wallet, null for traffic)
- meta: JSON (stores allowed node/service IDs and suspension metadata)
```

#### New Reseller Status Values
- `suspended_wallet`: Wallet reseller awaiting first top-up
- `suspended_traffic`: Traffic reseller awaiting first traffic purchase
- `suspended_other`: Other suspension reasons
- `disabled`: Administratively disabled

#### Panel Enhancements
```php
- registration_default_node_ids: JSON array of default Eylandoo node IDs
- registration_default_service_ids: JSON array of default Marzneshin service IDs
```

### 4. Wallet Charge Flow

The wallet charge page (`/wallet/charge`) now supports two modes:

#### Wallet Mode
- Displays current wallet balance in تومان
- Input field for amount in تومان
- Validates minimum threshold (150k first, 50k subsequent)
- Creates transaction with type `deposit` and metadata `charge_mode: wallet`

#### Traffic Mode
- Displays current traffic total and used in GB
- Input field for GB amount
- Calculates price dynamically: `GB × rate`
- Validates minimum threshold (250GB first, 50GB subsequent)
- Creates transaction with type `deposit` and metadata `charge_mode: traffic`, `traffic_gb: X`

### 5. Payment Processing & Activation

**Note**: Payment handler integration is pending completion. The flow should be:

1. Admin approves transaction via Filament
2. Transaction status changes to `completed`
3. Credit wallet/traffic based on charge_mode
4. Check if reseller is suspended and threshold is met
5. If yes:
   - Change status to `active`
   - Dispatch `ReenableResellerConfigsJob`
   - Log `reseller_activated_from_suspension` event

### 6. Config Re-enabling

`ReenableResellerConfigsJob` handles automatic re-enabling of configs:

1. Finds all configs disabled due to wallet/traffic suspension
2. For Eylandoo panels: **Remote-first gating**
   - Attempts remote enable via API first
   - Only updates local status if remote enable succeeds
3. For other panels: Updates local status directly
4. Clears suspension metadata from config meta field

### 7. Legacy User Migration

Command: `php artisan reseller:convert-legacy-users`

Options:
- `--dry-run`: Preview changes without applying them
- `--force`: Skip confirmation prompt

Behavior:
- Finds all users without reseller records
- Creates wallet-based reseller for each
- Sets `wallet_balance` from `user.balance`
- Sets status to `active` if balance ≥ 150,000, else `suspended_wallet`
- Assigns default active panel
- Logs conversion with metadata

## Configuration

### Environment Variables

Add to `.env`:

```bash
# Wallet-based reseller thresholds
MIN_FIRST_WALLET_TOPUP=150000
MIN_WALLET_TOPUP=50000
TRAFFIC_RESELLER_GB_RATE=750

# Traffic-based reseller thresholds  
MIN_FIRST_TRAFFIC_TOPUP_GB=250
MIN_TRAFFIC_TOPUP_GB=50
```

### Config File

The `config/billing.php` file provides structured access:

```php
config('billing.reseller.first_topup.wallet_min')        // 150000
config('billing.reseller.first_topup.traffic_min_gb')    // 250
config('billing.reseller.min_topup.wallet')              // 50000
config('billing.reseller.min_topup.traffic_gb')          // 50
config('billing.reseller.traffic.price_per_gb')          // 750
config('billing.reseller.config_limits.wallet')          // 1000
config('billing.reseller.config_limits.traffic')         // null (unlimited)
```

## Model Methods

### Reseller Model

New helper methods:
```php
$reseller->isSuspendedTraffic()           // Check traffic suspension
$reseller->isSuspendedOther()             // Check other suspension
$reseller->isAnySuspended()               // Check any suspension type
$reseller->primaryPanel()                 // Get primary panel relationship
$reseller->getEffectiveConfigLimit()      // Get effective config limit based on type
```

### Panel Model

New methods:
```php
$panel->getRegistrationDefaultNodeIds()      // Get default Eylandoo nodes
$panel->getRegistrationDefaultServiceIds()   // Get default Marzneshin services
```

## Logging Events

Structured logging throughout the flow:

### Registration
- `registration_start`
- `reseller_created`
- `registration_complete`

### Top-Up
- `topup_initiated_wallet` / `topup_initiated_traffic`
- `topup_success_wallet` / `topup_success_traffic` (pending payment handler)

### Activation
- `reseller_activated_from_suspension` (pending payment handler)

### Config Re-enable
- `config_reenable_job_started`
- `config_reenable_job_processing`
- `eylandoo_remote_enable_success` / `eylandoo_remote_enable_failed`
- `config_reenable_success` / `config_reenable_failed`
- `config_reenable_job_result`

### Legacy Migration
- `legacy_user_converted`

## Deployment Steps

### 1. Pre-Deployment

```bash
# Backup database
mysqldump -u user -p vpnmarket_db > backup_$(date +%Y%m%d_%H%M%S).sql

# Ensure at least one active panel exists
php artisan tinker
>>> \App\Models\Panel::where('is_active', true)->exists()
```

### 2. Deploy Code

```bash
git pull origin main
composer install --no-dev --optimize-autoloader
npm install && npm run build
```

### 3. Run Migrations

```bash
php artisan migrate --force
```

### 4. Convert Legacy Users (Optional)

```bash
# Dry run first to preview
php artisan reseller:convert-legacy-users --dry-run

# If satisfied, run actual conversion
php artisan reseller:convert-legacy-users
```

### 5. Clear Caches

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 6. Restart Services

```bash
php artisan queue:restart
# Restart web server (e.g., nginx, php-fpm)
```

## Testing Checklist

### Manual Testing

#### Wallet Reseller Registration
- [ ] Register with wallet type
- [ ] Verify status is `suspended_wallet`
- [ ] Verify redirected to wallet charge page
- [ ] Attempt charge < 150,000 → should be rejected
- [ ] Charge exactly 150,000 → should be accepted
- [ ] After admin approval, status should become `active`
- [ ] Disabled configs should be re-enabled

#### Traffic Reseller Registration
- [ ] Register with traffic type
- [ ] Verify status is `suspended_traffic`
- [ ] Verify redirected to wallet charge page (traffic mode)
- [ ] Attempt purchase < 250 GB → should be rejected
- [ ] Purchase exactly 250 GB → should be accepted
- [ ] Price calculation shows: 250 × 750 = 187,500 تومان
- [ ] After admin approval, traffic credited and status becomes `active`

#### Panel Node/Service Selection
- [ ] Register with Eylandoo panel type
- [ ] Verify node selection displayed
- [ ] Select subset of nodes
- [ ] Verify `eylandoo_allowed_node_ids` saved correctly
- [ ] Register with Marzneshin panel type
- [ ] Verify service selection displayed
- [ ] Select subset of services
- [ ] Verify `marzneshin_allowed_service_ids` saved correctly

#### Legacy User Conversion
- [ ] Create user without reseller record
- [ ] Set balance = 100,000
- [ ] Run conversion command
- [ ] Verify reseller created with type=wallet, status=suspended_wallet
- [ ] Create another user with balance = 200,000
- [ ] Run conversion
- [ ] Verify status=active for this one

## Backward Compatibility

### Maintained Functionality
- Existing wallet-based resellers continue to work
- User balance field still exists for backward compatibility
- All existing panel configurations preserved

### Breaking Changes
- New registrations can no longer create "normal users"
- Registration form requires reseller_type and panel selection
- Dashboard shows suspended status until first top-up

## Security Considerations

### Input Validation
- Server-side validation of reseller_type (wallet/traffic only)
- Panel selection must be from active panels
- Node/service selections must be subset of panel defaults
- Amount/GB validation enforces minimum thresholds

### Transaction Safety
- Atomic operations with DB transactions
- Row locking during credit operations
- Idempotent transaction callbacks (prevents double-credit)
- Metadata tracking for audit trail

### Remote API Calls
- Eylandoo remote enable uses remote-first gating
- Failures logged but don't crash job
- Retry logic via queue system

## Troubleshooting

### Issue: User registered but stuck in suspended state

**Check**:
1. Verify transaction was approved in admin panel
2. Check transaction metadata has correct charge_mode
3. Check logs for `topup_success_*` events
4. Manually update status if needed:
   ```php
   $reseller->update(['status' => 'active']);
   ```

### Issue: Configs not re-enabled after activation

**Check**:
1. Verify `ReenableResellerConfigsJob` was dispatched
2. Check queue is running: `php artisan queue:work`
3. Check logs for `config_reenable_*` events
4. For Eylandoo: verify API credentials and connectivity

### Issue: Legacy conversion failed for some users

**Check**:
1. Review logs for specific error messages
2. Verify users don't already have reseller records
3. Ensure default panel exists and is active
4. Re-run for failed users individually

## Future Enhancements

- Admin UI for managing panel registration defaults
- Bulk operations for reseller activation
- Advanced traffic pricing tiers
- Multi-panel assignment at registration
- Real-time usage analytics improvements
- Automated testing suite

## Support

For issues or questions, please:
1. Check logs in `storage/logs/laravel.log`
2. Review this documentation
3. Contact development team

---

**Version**: 1.0.0
**Last Updated**: 2025-11-17
**Status**: Core implementation complete, payment handler integration pending
