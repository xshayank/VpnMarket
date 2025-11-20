<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;
use Tests\TestCase;

class WalletChargingSchedulerTest extends TestCase
{
    public function test_wallet_charge_command_runs_every_minute(): void
    {
        // Even if a higher interval is configured, the scheduler should still run every minute
        config(['billing.wallet.charge_enabled' => true]);
        config(['billing.wallet.charge_frequency_minutes' => 15]);

        $schedule = app(Schedule::class);

        $event = collect($schedule->events())
            ->first(function ($event) {
                return Str::contains($event->command, 'reseller:charge-wallet-hourly');
            });

        $this->assertNotNull($event, 'Wallet charging command should be scheduled');
        $this->assertEquals('* * * * *', $event->expression, 'Wallet charging must run every minute');
    }
}
