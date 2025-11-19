<?php

use App\Jobs\SendRenewalWalletRemindersJob;
use App\Jobs\SendResellerTrafficTimeRemindersJob;
use App\Models\Setting;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Modules\Reseller\Jobs\ReenableResellerConfigsJob;
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

// DISABLED: Old single-panel jobs - replaced by multi-panel commands below
// Schedule::call(function () {
//     Log::info('Scheduler tick: Running SyncResellerUsageJob');
//     SyncResellerUsageJob::dispatchSync();
//     Log::info('Scheduler tick: SyncResellerUsageJob completed');
// })->everyMinute();

// DISABLED: Old re-enable job - replaced by multi-panel command below
// Schedule::call(function () {
//     Log::info('Scheduler tick: ReenableResellerConfigsJob - dispatching to queue');
//     ReenableResellerConfigsJob::dispatch();
//     Log::info('Scheduler tick: ReenableResellerConfigsJob dispatched successfully (will run async on queue)');
// })->everyMinute();

// Schedule multi-panel reseller usage recalculation
// Runs every minute to aggregate usage across all assigned panels
// Processes all traffic-based and wallet-based resellers
Schedule::command('resellers:recalc-usage --all --chunk=200')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Schedule multi-panel reseller config re-enable
// Runs every minute to quickly re-enable configs when reseller recovers
// Processes configs across all panels with remote-first gating
Schedule::command('resellers:reenable-configs --all --batch=100 --reason=traffic')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Schedule wallet-based reseller config re-enable
// Runs every minute to quickly re-enable configs after wallet recharge
Schedule::command('resellers:reenable-configs --all --batch=100 --reason=wallet')
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
// Runs every hour to charge resellers for traffic usage
// Deducts cost from wallet balance and suspends if balance is too low
Schedule::command('reseller:charge-wallet-hourly')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
