<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Reseller;
use App\Models\User;
use App\Support\PaymentMethodConfig;
use App\Support\Tetra98Config;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WalletChargePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        PaymentMethodConfig::clearCache();
        Tetra98Config::clearCache();
    }

    public function test_wallet_charge_page_renders_enabled_methods(): void
    {
        $user = User::factory()->create();
        Reseller::factory()->walletBased()->for($user)->create();

        Setting::setValue('payment_card_to_card_enabled', 'true');
        Setting::setValue('starsefar_enabled', 'true');
        Setting::setValue('payment.tetra98.enabled', 'true');
        Setting::setValue('payment.tetra98.api_key', 'test-api-key');

        PaymentMethodConfig::clearCache();
        Tetra98Config::clearCache();

        $response = $this->actingAs($user)->get(route('wallet.charge.form'));

        $response->assertOk();
        $response->assertSee('id="amount"', false);
        $response->assertSee('id="starsefar-amount"', false);
        $response->assertSee('id="tetra98-amount"', false);
        $response->assertViewHas('availableMethods', ['card', 'starsefar', 'tetra98']);
        $response->assertViewHas('defaultMethod', 'card');
    }

    public function test_wallet_charge_page_displays_warning_when_no_methods_available(): void
    {
        $user = User::factory()->create();
        Reseller::factory()->walletBased()->for($user)->create();

        Setting::setValue('payment_card_to_card_enabled', 'false');
        Setting::setValue('starsefar_enabled', 'false');
        Setting::setValue('payment.tetra98.enabled', 'false');
        Setting::setValue('payment.tetra98.api_key', null);

        PaymentMethodConfig::clearCache();
        Tetra98Config::clearCache();

        $response = $this->actingAs($user)->get(route('wallet.charge.form'));

        $response->assertOk();
        $response->assertSee('در حال حاضر هیچ روش پرداختی فعال نیست. لطفاً بعداً دوباره تلاش کنید.');
        $response->assertViewHas('availableMethods', []);
        $response->assertViewHas('defaultMethod', null);
    }

    public function test_starsefar_helper_is_bound_to_disable_condition(): void
    {
        $user = User::factory()->create();
        Reseller::factory()->walletBased()->for($user)->create();

        Setting::setValue('payment_card_to_card_enabled', 'true');
        Setting::setValue('starsefar_enabled', 'true');
        Setting::setValue('starsefar_min_amount_toman', '150000');
        Setting::setValue('payment.tetra98.enabled', 'false');

        PaymentMethodConfig::clearCache();
        Tetra98Config::clearCache();

        $response = $this->actingAs($user)->get(route('wallet.charge.form'));

        $response->assertSee('حداقل پرداخت برای استفاده از استارز', false);
        $response->assertSee('x-show="starsefarRequirementNotMet()"', false);
        $response->assertSee(':disabled="isInitiating || starsefarRequirementNotMet()"', false);
    }
}

