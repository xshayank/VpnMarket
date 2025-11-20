# Wallet Hourly Charging Safeguards & Re-Enable Fix - Implementation Summary

## Overview
This implementation adds comprehensive safeguards to prevent double-charging in wallet-based hourly billing and fixes the critical issue where wallet resellers were not being re-enabled after topping up their balance.

## Problems Solved

### 1. Double-Charging Prevention
- **Risk**: Manual repeated execution of `php artisan reseller:charge-wallet-hourly` could charge the same reseller multiple times
- **Risk**: No idempotency guard to prevent charging within a short time window
- **Solution**: Implemented idempotency window (55 minutes default) and cycle tracking

### 2. Wallet Reseller Re-Enable Issue ⭐ **CRITICAL FIX**
- **Problem**: When wallet resellers topped up their balance after suspension, they remained suspended and configs stayed disabled
- **Root Cause**: `WalletResellerReenableService` used database-specific `JSON_EXTRACT` queries that didn't work properly across different database types (SQLite vs MySQL)
- **Root Cause**: Configs without `panel_id` were being skipped entirely
- **Root Cause**: No scheduled auto re-enable for wallet resellers (only traffic-based resellers)
- **Solution**: 
  - Replaced SQL JSON queries with PHP filtering for database compatibility
  - Added handling for configs without `panel_id` (enable locally)
  - Integrated wallet reseller processing into scheduled `ReenableResellerConfigsJob`
  - Improved `WalletResellerReenableService` to clear all suspension metadata

### 3. Operational Safety
- **Missing**: No dry-run capability for testing charges
- **Missing**: No single-reseller manual command
- **Missing**: No structured logging for audit trails
- **Solution**: Added `reseller:charge-wallet-once` command with `--dry-run`, `--force` options and structured logging throughout

## New Features

### Configuration Options
Added to `config/billing.php`:
```php
'wallet' => [
    'hourly_charge_enabled' => env('WALLET_HOURLY_CHARGE_ENABLED', true),
    'charge_idempotency_minutes' => env('WALLET_CHARGE_IDEMPOTENCY_MINUTES', 55),
],
```

### New Command: `reseller:charge-wallet-once`
```bash
# Dry run - see cost estimate without charging
php artisan reseller:charge-wallet-once --reseller=123 --dry-run

# Force charge bypassing idempotency window
php artisan reseller:charge-wallet-once --reseller=123 --force

# Regular single reseller charge
php artisan reseller:charge-wallet-once --reseller=123
```

### Structured Logging Events
- `wallet_charge_cycle_start` - Cycle begins with delta estimation
- `wallet_charge_skipped_recent_snapshot` - Idempotency skip
- `wallet_charge_applied` - Successful charge with full metrics
- `wallet_charge_dry_run` - Dry run estimation
- `wallet_reseller_suspended` - Suspension due to low balance
- `wallet_reseller_reenable_start` - Re-enable process begins
- `wallet_reseller_reenable_complete` - Re-enable process completes

### Snapshot Metadata
Each snapshot now includes:
```json
{
  "cycle_started_at": "2025-11-20T03:00:00Z",
  "cycle_charge_applied": true,
  "delta_bytes": 1073741824,
  "delta_gb": 1.0,
  "cost": 780,
  "price_per_gb": 780
}
```

### Config Metadata for Cycle Tracking
Configs disabled by wallet suspension now include:
```json
{
  "disabled_by_wallet_suspension": true,
  "disabled_by_wallet_suspension_cycle_at": "2025-11-20T03:00:00Z",
  "disabled_by_reseller_id": 123,
  "disabled_at": "2025-11-20T03:15:30Z"
}
```

## How It Works

### Idempotency Protection
1. Before charging, check if the last snapshot was created within the idempotency window (55 minutes default)
2. If yes, skip the charge (unless `--force` flag is used)
3. Log the skip with `wallet_charge_skipped_recent_snapshot`

### Wallet Reseller Re-Enable Flow
1. **Manual Top-Up** (via Starsefar/Tetra98 payment gateways):
   - Payment confirmed → Wallet balance increased
   - Payment controller checks if `status == 'suspended_wallet'` AND `balance > threshold`
   - If yes: Reactivate reseller + call `WalletResellerReenableService`
   - Service finds configs with `disabled_by_wallet_suspension` flag
   - Re-enables configs (remotely if panel exists, locally otherwise)

2. **Scheduled Auto Re-Enable** (every minute):
   - `ReenableResellerConfigsJob` now processes wallet resellers too
   - Finds `suspended_wallet` resellers with `balance > threshold`
   - Reactivates reseller + re-enables configs
   - Logs full audit trail

### Double-Disable Prevention
- Each config stores `disabled_by_wallet_suspension_cycle_at` timestamp
- Before disabling in the same cycle hour, check if already disabled
- Skip if `cycle_at` matches current cycle (prevents duplicate disable events)

## Migration
Database migration adds `meta` JSON column to `reseller_usage_snapshots`:
```php
Schema::table('reseller_usage_snapshots', function (Blueprint $table) {
    $table->json('meta')->nullable()->comment('Cycle info, delta metrics, charge status');
});
```

## Testing
Comprehensive test suite with 21 tests covering:
- ✅ Idempotency (7 tests)
- ✅ Dry-run mode (6 tests)
- ✅ Wallet auto re-enable (3 tests)
- ✅ Existing charging tests (5 tests)

### Test Coverage
```
✓ Idempotency: consecutive runs do not double-charge
✓ Idempotency: forced charge bypasses idempotency window
✓ Idempotency: snapshot stores cycle metadata
✓ Idempotency: feature flag disables charging
✓ Idempotency: only charges wallet-based resellers
✓ Locking: concurrent execution protection
✓ Suspension: configs disabled once per cycle
✓ Dry-run: does not modify balance or create snapshot
✓ Dry-run: shows cost estimate correctly
✓ Dry-run: can be combined with force flag
✓ Single reseller command: requires reseller option
✓ Single reseller command: rejects non-existent reseller
✓ Single reseller command: rejects traffic-based reseller
✓ Wallet reseller auto re-enables when balance recovered
✓ Wallet reseller does not re-enable if balance still below threshold
✓ Wallet reseller re-enable only affects wallet-suspended configs
```

## Deployment Steps

1. **Run migration**:
   ```bash
   php artisan migrate
   ```

2. **Clear caches**:
   ```bash
   php artisan optimize:clear
   ```

3. **Optional: Test dry-run for sample reseller**:
   ```bash
   php artisan reseller:charge-wallet-once --reseller=<id> --dry-run
   ```

4. **Monitor logs** for structured logging events

5. **Update environment variables** (optional):
    ```env
    WALLET_HOURLY_CHARGE_ENABLED=true
    WALLET_CHARGE_IDEMPOTENCY_MINUTES=55
    ```

## Rollback Plan

1. **Disable feature**:
   ```env
   WALLET_HOURLY_CHARGE_ENABLED=false
   ```

2. **Revert code changes** (optional):
   ```bash
   git revert <commit-hash>
   ```

3. **Keep meta column** (harmless) or rollback migration:
   ```bash
   php artisan migrate:rollback --step=1
   ```

## Security & Safety

### Double-Charge Prevention
- ✅ Idempotency window prevents rapid re-execution
- ✅ Cycle tracking in snapshot metadata
- ✅ Structured logs for audit trail

### Data Integrity
- ✅ Dry-run mode never modifies data
- ✅ Multi-panel aggregator verified to never modify `wallet_balance`
- ✅ Only `ChargeWalletResellersHourly` modifies wallet balance
- ✅ Atomic transactions for balance updates

### Re-Enable Safety
- ✅ Only configs with `disabled_by_wallet_suspension` flag are re-enabled
- ✅ Manual disables are preserved
- ✅ Remote panel enable attempted before local status update (when panel exists)
- ✅ Configs without panels can be re-enabled locally

## Monitoring & Observability

### Key Metrics to Monitor
1. **Charge Cycles**: Count of `wallet_charge_applied` events per hour (should be ~1 per hour per wallet reseller)
2. **Idempotency Skips**: Count of `wallet_charge_skipped_recent_snapshot` (high count = potential issue)
3. **Suspensions**: Count of `wallet_reseller_suspended` events
4. **Re-Enables**: Count of `wallet_reseller_reenable_complete` events

### Alerts to Configure
- Alert if `wallet_charge_applied` occurs >2 times per hour for same reseller (possible double-charge)
- Alert if `wallet_reseller_reenable_complete` with `configs_failed` >0 (panel communication issues)

## Follow-Up Tasks (Optional)

1. **Web UI Dashboard**:
   - Show last wallet charge: delta, cost, timestamp
   - Show charge history for reseller

2. **Prometheus Metrics**:
   - Export wallet charge metrics
   - Export re-enable success/failure rates

3. **Admin Tools**:
   - Button to manually trigger re-enable for specific reseller
   - Charge history viewer with filtering

4. **Enhanced Notifications**:
   - Email/SMS when wallet suspended
   - Email/SMS when re-enabled after top-up

## Files Modified/Added

### Modified Files
- `app/Console/Commands/ChargeWalletResellersHourly.php` - Added safeguards
- `app/Models/ResellerUsageSnapshot.php` - Added meta field
- `app/Services/WalletResellerReenableService.php` - Fixed query compatibility
- `Modules/Reseller/Jobs/ReenableResellerConfigsJob.php` - Added wallet reseller processing
- `config/billing.php` - Added wallet charge config
- `routes/console.php` - Added feature flag check

### New Files
- `app/Console/Commands/ChargeWalletResellerOnce.php` - New command
- `database/migrations/2025_11_20_030122_add_meta_to_reseller_usage_snapshots_table.php` - Migration
- `tests/Feature/WalletHourlyChargingIdempotencyTest.php` - Tests (7)
- `tests/Feature/WalletHourlyChargingDryRunTest.php` - Tests (6)
- `tests/Feature/WalletResellerAutoReenableTest.php` - Tests (3)

## Conclusion

This implementation provides comprehensive protection against double-charging while maintaining operational flexibility through dry-run and force modes. The structured logging enables full audit trails and the idempotency mechanisms ensure safe operation even with manual interventions.

**Most importantly**, the wallet reseller re-enable issue is now completely fixed - resellers will automatically be reactivated when their balance is topped up, both through manual payment processing and scheduled background jobs.
