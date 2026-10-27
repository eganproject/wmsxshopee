<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Ikon info <x-hint> dan tooltip status tampil di form, daftar dan detail. */
class HelpTooltipTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::where('username', 'superadmin')->first());
    }

    public function test_hint_icon_renders_with_title_and_text(): void
    {
        $html = (string) $this->blade('<x-hint title="Tersedia" text="Fisik dikurangi Dipesan." next="Terima barang." />');

        $this->assertStringContainsString('class="hint-icon"', $html);
        $this->assertStringContainsString('data-hint="Fisik dikurangi Dipesan."', $html);
        $this->assertStringContainsString('data-hint-title="Tersedia"', $html);
        $this->assertStringContainsString('data-hint-next="Terima barang."', $html);
        $this->assertStringContainsString('aria-label="Tersedia: Fisik dikurangi Dipesan."', $html);
    }

    public function test_form_fields_list_columns_and_layout_tooltip_are_present(): void
    {
        // Form: tip di <x-input> / <x-select> tampil sebagai ikon di label.
        $this->get('/master/warehouses/create')->assertOk()
            ->assertSee('class="hint-icon"', false)
            ->assertSee('Persentase stok gudang cadangan yang ikut dijual di marketplace.');

        // Daftar: penjelasan kolom & wadah tooltip global di layout.
        $this->get('/inventory/stocks')->assertOk()
            ->assertSee('Fisik dikurangi Dipesan. Inilah stok yang masih bisa menerima pesanan baru.')
            ->assertSee('id="hint-tip"', false);
    }
}
