<?php

use App\Jobs\SendRenewalWalletRemindersJob;
use App\Jobs\SendResellerTrafficTimeRemindersJob;
use App\Models\Setting;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Modules\Reseller\Jobs\SyncResellerUsageJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule email reminders based on settings
Schedule::call(function () {
    $autoRemindEnabled = Setting::get('email.auto_remind_renewal_wallet') === 'true';
    if ($autoRemindEnabled) {
        SendRenewalWalletRemindersJob::dispatch();
    }
})->daily()->at('09:00');

Schedule::call(function () {
    $autoRemindEnabled = Setting::get('email.auto_remind_reseller_traffic_time') === 'true';
    if ($autoRemindEnabled) {
        SendResellerTrafficTimeRemindersJob::dispatch();
    }
})->hourly();

// Schedule reseller usage sync job
// Runs every minute and executes synchronously (no queue worker needed)
// This ensures reliable execution and immediate updates to reseller aggregates
Schedule::call(function () {
    Log::info('Scheduler tick: Running SyncResellerUsageJob');
    SyncResellerUsageJob::dispatchSync();
    Log::info('Scheduler tick: SyncResellerUsageJob completed');
})->everyMinute();

// Schedule reseller config re-enable job
// Runs every minute to quickly re-enable configs when reseller recovers
// Uses a command to find eligible resellers and queue jobs with proper parameters
Schedule::command('reseller:reenable-wallet-disabled')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Schedule reseller time window enforcement command
// Runs every 5 minutes to enforce time limits on resellers
// Suspends resellers whose window_ends_at has passed
// Reactivates resellers whose window_ends_at has been extended beyond now
// Uses a command instead of a job to avoid dependency on queue workers
// Can be disabled via SCHEDULE_ENFORCE_RESELLER_WINDOWS=false
if (env('SCHEDULE_ENFORCE_RESELLER_WINDOWS', true)) {
    Schedule::command('reseller:enforce-time-windows')
        ->everyFiveMinutes()
        ->withoutOverlapping()
        ->onOneServer()
        ->runInBackground();
}

// Schedule wallet-based reseller charging
// Runs every minute to charge resellers for traffic usage
// Deducts cost from wallet balance and suspends if balance is too low
// Can be disabled via WALLET_CHARGE_ENABLED=false
if (config('billing.wallet.charge_enabled', true)) {
    Schedule::command('reseller:charge-wallet-hourly')
        ->everyMinute()
        // Limit the overlap mutex TTL so a killed/failed run doesn't block the scheduler
        // for 24 hours (the default). Ten minutes keeps retries frequent while avoiding
        // concurrent executions.
        ->withoutOverlapping(10)
        ->evenInMaintenanceMode()
        ->onOneServer()
        ->runInBackground();
}
