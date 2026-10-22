<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_settings_page_shows_cards_for_every_setting_menu(): void
    {
        $admin = User::factory()->create()->assignRole('Super Admin');

        $this->actingAs($admin)->get(route('settings'))
            ->assertOk()
            ->assertSee('Profil Saya')
            ->assertSee('Manajemen User')
            ->assertSee('Role &amp; Permission', false)
            ->assertSee('Pengaturan Marketplace')
            ->assertSee('Lokasi Tetap SKU')
            ->assertSee('Log Aktivitas')
            ->assertSee('Backup Database')
            ->assertSee(route('admin.users.index'))
            ->assertSee(route('marketplace.settings'));
    }

    public function test_settings_page_only_shows_menus_user_can_open(): void
    {
        $staff = User::factory()->create()->assignRole('Staff Gudang');

        $html = $this->actingAs($staff)->get(route('settings'))->assertOk()->getContent();

        $this->assertStringContainsString(route('profile.edit'), $html);
        $this->assertStringNotContainsString('href="'.route('admin.users.index').'"', $html);
        $this->assertStringNotContainsString('href="'.route('admin.backups.index').'"', $html);
    }

    public function test_sidebar_and_user_menu_link_to_settings_page(): void
    {
        $admin = User::factory()->create()->assignRole('Super Admin');

        $html = $this->actingAs($admin)->get(route('admin.users.index'))->assertOk()->getContent();

        // Sidebar: satu menu Pengaturan (aktif di halaman pengaturan turunan) + item di dropdown akun.
        $this->assertGreaterThanOrEqual(3, substr_count($html, 'href="'.route('settings').'"'), 'sidebar + dropdown + breadcrumb');
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('settings'), '#').'"[^>]*>\s*<svg[^>]*>.*?</svg>\s*Pengaturan\s*</a>#s', $html);
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('settings'), '#').'" data-tip="Pengaturan"\s+aria-current="page"#', $html);
        // Menu pengaturan lama tidak lagi muncul sendiri di sidebar.
        $this->assertStringNotContainsString('data-tip="Manajemen User"', $html);
        $this->assertStringNotContainsString('data-tip="Pengaturan Marketplace"', $html);
    }
}
