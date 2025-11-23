<?php

namespace Tests\Unit;

use App\Support\StarsefarConfig;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StarsefarConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_min_amount_reads_from_settings_when_available(): void
    {
        Setting::setValue('starsefar_min_amount_toman', '12345');

        $this->assertSame(12345, StarsefarConfig::getMinAmountToman());
    }
}
