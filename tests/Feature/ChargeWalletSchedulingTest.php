<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;

it('schedules wallet charge command every minute when enabled', function () {
    Config::set('billing.wallet.charge_enabled', true);

    Artisan::call('schedule:list', ['--name' => 'reseller:charge-wallet-hourly']);

    expect(Artisan::output())->toContain('Every minute');
});
