# Wallet Billing System Documentation

## Overview

The wallet billing system charges wallet-based resellers for their traffic usage. This document explains the immediate billing trigger functionality and how charges are calculated.

## Immediate Billing Trigger

### Purpose

When resellers perform actions in the panel that affect traffic usage (such as editing configs, resetting traffic, or refreshing stats), the billing logic is triggered immediately rather than waiting for the scheduled hourly charge command.

### Trigger Points

The wallet charging service is triggered immediately after the following panel actions:

1. **Config Creation** (`create_config`) - After a new config is provisioned
2. **Config Edit** (`edit_config`) - After config settings are updated
3. **Traffic Reset** (`reset_traffic`) - After a config's traffic counter is reset
4. **Stats Refresh** (`refresh_configs`) - After the reseller manually refreshes their stats/dashboard

### How It Works

1. When a reseller performs one of the above actions in the `ConfigsManager` Livewire component
2. After the action completes successfully, `triggerImmediateWalletCharge()` is called
3. The `WalletChargingService::chargeFromPanel()` method calculates any traffic delta since the last snapshot
4. If there's new usage above the minimum threshold, a charge is applied and a snapshot is created
5. The snapshot records the source as `panel:{action}` for audit purposes

### Idempotency

The charging service ensures that double-charging cannot occur:

- Each charge creates a snapshot recording the total bytes at that point
- Subsequent charges only bill the delta (new usage since last snapshot)
- If there's no new usage, the charge is skipped with status `no_usage_delta`
- If new usage is below the minimum threshold, the charge is skipped with status `below_minimum_delta`

### Error Handling

The immediate billing trigger is designed to be non-blocking:

- If the charging service throws an exception, it's logged but doesn't fail the panel action
- The reseller can still complete their intended action (edit, reset, etc.)
- Errors are logged with context for debugging

## Scheduled Hourly Charging

The `reseller:charge-wallet-hourly` command runs on a schedule and processes all wallet-based resellers:

```bash
php artisan reseller:charge-wallet-hourly
```

### Command Features

- Uses the same `WalletChargingService` as the panel triggers
- Records source as `command` in snapshots
- Processes all wallet-based resellers in a single run
- Includes proper error handling per reseller

### Single Reseller Command

For debugging or manual operations:

```bash
# Charge a specific reseller
php artisan reseller:charge-wallet-once --reseller=123

# Preview charge without applying (dry run)
php artisan reseller:charge-wallet-once --reseller=123 --dry-run
```

## Config Naming Support

The billing system supports both legacy and new config naming patterns:

### Legacy Configs
- May have `username_prefix` as NULL
- Use `panel_username` or `external_username` for identification
- Still included in traffic calculations

### New Naming System Configs
- Have explicit `username_prefix` stored
- Panel username may include suffix (e.g., `userprefix_a1b2c3`)
- Fully supported in all traffic calculations

### Traffic Calculation

All configs are included regardless of naming pattern:

```php
// From WalletChargingService::calculateTotalUsageBytes()
$reseller->configs()->get()->sum(function ($config) {
    $usageBytes = $config->usage_bytes ?? 0;
    $settledUsageBytes = (int) data_get($config->meta, 'settled_usage_bytes', 0);
    return $usageBytes + $settledUsageBytes;
});
```

## Configuration

Key configuration options in `config/billing.php`:

```php
'wallet' => [
    // Price per GB for wallet-based resellers (in تومان)
    'price_per_gb' => env('WALLET_PRICE_PER_GB', 780),
    
    // Suspension threshold - when balance drops below this
    'suspension_threshold' => env('WALLET_SUSPENSION_THRESHOLD', -1000),
    
    // Enable/disable wallet charging
    'charge_enabled' => env('WALLET_CHARGE_ENABLED', true),
    
    // Minimum delta before applying a charge
    'minimum_delta_bytes_to_charge' => env('WALLET_MINIMUM_DELTA_BYTES_TO_CHARGE', 5242880), // 5 MB
],
```

## Logging

All charging operations are logged at INFO level:

- `wallet_charge_panel_triggered` - Panel action initiated charge
- `wallet_charge_panel_applied` - Charge successfully applied from panel
- `wallet_charge_calculation` - Details of charge calculation
- `wallet_charge_applied` - Charge applied to wallet
- `wallet_charge_skip_no_delta` - Charge skipped due to no new usage
- `wallet_charge_skip_below_threshold` - Charge skipped due to usage below minimum

Errors are logged at ERROR level with full context.

## Service Architecture

### WalletChargingService

Located at `app/Services/Reseller/WalletChargingService.php`

Key methods:

- `chargeForReseller(Reseller $reseller, ?Carbon $referenceTime, bool $dryRun, ?string $source)` - Main charging logic
- `chargeFromPanel(Reseller $reseller, string $action)` - Convenience method for panel triggers
- `calculateTotalUsageBytes(Reseller $reseller)` - Calculate total usage including settled bytes

### Integration Points

The service is integrated into:

1. `ChargeWalletResellersHourly` command - For scheduled billing
2. `ChargeWalletResellerOnce` command - For single-reseller operations
3. `ConfigsManager` Livewire component - For immediate panel triggers

## Testing

Tests are located in:

- `tests/Unit/WalletChargingServiceTest.php` - Unit tests for the service
- `tests/Feature/WalletChargingLivewireIntegrationTest.php` - Integration tests for panel triggers
- `tests/Feature/WalletChargingCommandServiceTest.php` - Tests for command behavior

Run tests with:

```bash
php artisan test tests/Unit/WalletChargingServiceTest.php
php artisan test tests/Feature/WalletChargingLivewireIntegrationTest.php
php artisan test tests/Feature/WalletChargingCommandServiceTest.php
```
