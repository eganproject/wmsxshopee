<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Navigation;
use App\Support\ReportsMenu;
use App\Support\SettingsMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Sidebar dengan grup yang bisa dilipat (Penjualan, Inventori, Master Data, Marketplace), menu
 * Laporan tunggal yang membuka halaman hub, dan halaman yang pindah jadi tab/tombol/Pengaturan.
 * Semua route lama tetap ada; tidak ada halaman yang hilang.
 */
class SidebarNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function superAdmin(): User
    {
        return User::where('username', 'superadmin')->first();
    }

    public function test_every_menu_route_exists_and_old_sidebar_pages_are_still_reachable(): void
    {
        $routes = collect(Navigation::entries())
            ->flatMap(fn ($entry) => $entry['type'] === 'link' ? [$entry['route']] : array_column($entry['items'], 'route'))
            ->merge(array_column(ReportsMenu::items(), 'route'))
            ->merge(collect(SettingsMenu::groups())->flatMap(fn ($g) => array_column($g['items'], 'route')));

        foreach ($routes as $name) {
            $this->assertTrue(Route::has($name), "Route {$name} di menu tidak ada.");
        }

        // 32 halaman sidebar lama semuanya masih tercantum di salah satu daftar menu.
        $old = [
            'dashboard', 'notifications.index', 'master.warehouses.index', 'master.products.index', 'master.couriers.index', 'master.suppliers.index',
            'sales.orders.index', 'sales.qc.index', 'sales.handovers.index', 'sales.returns.index',
            'inventory.receipts.index', 'inventory.stocks.index', 'inventory.sku-settings.index', 'inventory.transfers.index', 'inventory.adjustments.index',
            'inventory.damaged.index', 'inventory.counts.index', 'labels.products', 'labels.locations', 'mobile.app',
            'reports.stock-locations', 'reports.min-stock', 'reports.reorder', 'reports.movements', 'reports.moving', 'reports.balance', 'reports.runout', 'reports.lost-items',
            'marketplace.shops', 'marketplace.channel-products', 'marketplace.products', 'marketplace.sync-logs', 'settings',
        ];
        $this->assertSame([], array_values(array_diff($old, $routes->all())));
    }

    public function test_sidebar_renders_groups_and_hides_tab_pages(): void
    {
        $html = $this->actingAs($this->superAdmin())->get(route('inventory.stocks.index'))->assertOk()->getContent();

        foreach (['penjualan', 'inventori', 'master', 'marketplace'] as $key) {
            $this->assertStringContainsString('data-group="'.$key.'"', $html);
        }
        // Grup aktif (Inventori) terbuka dari server, grup lain menunggu status tersimpan (x-cloak).
        $this->assertMatchesRegularExpression('#data-group="inventori".*?<ul x-show="isOpen\(\'inventori\'\)"[^>]*>#s', $html);
        $this->assertDoesNotMatchRegularExpression('#data-group="inventori"[^>]*>.*?<ul x-show="isOpen\(\'inventori\'\)"[^>]*x-cloak#s', $html);
        $this->assertMatchesRegularExpression('#<ul x-show="isOpen\(\'penjualan\'\)"[^>]*x-cloak#', $html);

        // Menu tunggal tetap memakai data-tip + aria-current (dipakai tooltip mode ikon).
        $this->assertStringContainsString('data-tip="Laporan"', $html);
        $this->assertStringContainsString('href="'.route('inventory.stocks.index').'" aria-current="page"', $html);

        // Halaman yang jadi tab/tombol tidak lagi punya menu sidebar, tapi tab-nya ada di halaman Stok.
        $this->assertStringNotContainsString('data-tip="Notifikasi"', $html);
        $this->assertStringNotContainsString('data-tip="Cetak Label"', $html);
        $this->assertStringNotContainsString('data-tip="Aplikasi Android"', $html);
        $this->assertStringNotContainsString('>SKU per Gudang</span>', $html);
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('inventory.sku-settings.index'), '#').'"[^>]*>\s*<svg.*?SKU per Gudang#s', $html);
        $this->assertStringNotContainsString('>Integrasi<', $html);
        $this->assertStringNotContainsString('>Administrasi<', $html);
    }

    public function test_groups_without_permission_disappear_for_staff(): void
    {
        $staff = User::factory()->create()->assignRole('Staff Gudang');

        $html = $this->actingAs($staff)->get(route('sales.qc.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-group="penjualan"', $html);
        $this->assertStringContainsString('data-group="inventori"', $html);
        $this->assertStringContainsString('data-group="master"', $html);
        $this->assertStringNotContainsString('data-group="marketplace"', $html);
        $this->assertStringNotContainsString('data-tip="Laporan"', $html);
        // Grup aktif = Penjualan (QC Kemas).
        $this->assertMatchesRegularExpression('#<ul x-show="isOpen\(\'penjualan\'\)"(?![^>]*x-cloak)#', $html);
        $this->assertMatchesRegularExpression('#<ul x-show="isOpen\(\'inventori\'\)"[^>]*x-cloak#', $html);
    }

    public function test_reports_index_lists_every_report_and_report_pages_link_back(): void
    {
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->get(route('reports.index'))->assertOk();
        foreach (ReportsMenu::items() as $item) {
            $response->assertSee($item['label'])->assertSee('href="'.route($item['route']).'"', false);
        }
        $this->assertCount(8, ReportsMenu::items());

        $this->actingAs($admin)->get(route('reports.min-stock'))->assertOk()
            ->assertSee('href="'.route('reports.index').'"', false)
            ->assertSee('data-tip="Laporan"', false);

        $staff = User::factory()->create()->assignRole('Staff Gudang');
        $this->actingAs($staff)->get(route('reports.index'))->assertForbidden();
    }

    public function test_moved_pages_keep_their_entry_points(): void
    {
        $admin = $this->superAdmin();

        // Cetak Label: tombol di Produk dan Gudang & Lokasi; sidebar menandai menu induknya.
        $this->actingAs($admin)->get(route('master.products.index'))->assertOk()->assertSee('href="'.route('labels.products').'"', false);
        $this->actingAs($admin)->get(route('master.warehouses.index'))->assertOk()->assertSee('href="'.route('labels.locations').'"', false);
        $this->actingAs($admin)->get(route('labels.products'))->assertOk()
            ->assertSee('href="'.route('master.products.index').'" aria-current="page"', false);

        // Aplikasi Android: kartu di Pengaturan, menu Pengaturan aktif di halamannya.
        $this->actingAs($admin)->get(route('settings'))->assertOk()->assertSee('Aplikasi Android')->assertSee('href="'.route('mobile.app').'"', false);
        $this->actingAs($admin)->get(route('mobile.app'))->assertOk()->assertSee('data-tip="Pengaturan" aria-current="page"', false);

        // SKU per Gudang: tab di halaman Stok, menu Stok aktif.
        $this->actingAs($admin)->get(route('inventory.sku-settings.index'))->assertOk()
            ->assertSee('href="'.route('inventory.stocks.index').'" aria-current="page"', false);

        // Notifikasi tetap terbuka lewat ikon bel.
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertSee('href="'.route('notifications.index').'"', false);
    }

    public function test_global_search_still_knows_every_page(): void
    {
        $html = $this->actingAs($this->superAdmin())->get(route('dashboard'))->assertOk()->getContent();

        // Daftar halaman dikirim lewat Js::from (tanda kutip jadi \u0022).
        foreach (['Notifikasi', 'Kartu Stok', 'SKU per Gudang', 'Cetak Label Produk', 'Aplikasi Android', 'Saran Pembelian', 'Produk Channel'] as $title) {
            $this->assertStringContainsString('\u0022title\u0022:\u0022'.$title.'\u0022', $html, "{$title} hilang dari pencarian global.");
        }
    }
}
