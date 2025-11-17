<?php

namespace Tests\Feature;

use App\Models\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_renders_with_cta(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('ریسلر OpenVPN');
        $response->assertSee('شروع به عنوان ریسلر');
    }

    public function test_homepage_shows_waitlist_when_no_data(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('لیست انتظار');
    }

    public function test_homepage_includes_panel_cta_link(): void
    {
        $panel = Panel::factory()->create(['is_active' => true, 'panel_type' => 'eylandoo']);

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee("/register?reseller_type=wallet&primary_panel_id={$panel->id}");
    }
}
