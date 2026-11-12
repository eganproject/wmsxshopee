<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseVariantSetting;
use App\Services\AlertService;
use App\Support\ReorderSuggestions;
use App\Support\StockLevels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stok minimum per gudang: angka di SKU per Gudang, kosong = Master Produk
 * (hanya untuk gudang yang memegang SKU itu).
 */
class MinStockPerWarehouseTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $jakarta;

    private Warehouse $bandung;

    private Warehouse $surabaya;

    private ProductVariant $kaos;

    private ProductVariant $topi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jakarta = Warehouse::create(['code' => 'JKT01', 'name' => 'Gudang Jakarta', 'is_active' => true]);
        $this->bandung = Warehouse::create(['code' => 'BDG01', 'name' => 'Gudang Bandung', 'is_active' => true]);
        $this->surabaya = Warehouse::create(['code' => 'SBY01', 'name' => 'Gudang Surabaya', 'is_active' => true]);

        $unit = Unit::where('code', 'PCS')->value('id');
        $this->kaos = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => $unit, 'has_variants' => false, 'is_active' => true])
            ->variants()->create(['sku' => 'KAOS', 'min_stock' => 10, 'is_active' => true]);
        $this->topi = Product::create(['sku' => 'TOPI', 'name' => 'Topi', 'unit_id' => $unit, 'has_variants' => false, 'is_active' => true])
            ->variants()->create(['sku' => 'TOPI', 'is_active' => true]);

        // Kaos: Jakarta 8, Bandung 30, Surabaya tidak pernah menyimpan kaos.
        Stock::create(['product_variant_id' => $this->kaos->id, 'warehouse_id' => $this->jakarta->id, 'qty' => 8]);
        Stock::create(['product_variant_id' => $this->kaos->id, 'warehouse_id' => $this->bandung->id, 'qty' => 30]);
    }

    private function rows(?int $warehouseId = null): array
    {
        return StockLevels::minStockQuery($warehouseId)->toBase()->get()
            ->map(fn ($row) => [$row->sku, $row->warehouse_code, (int) $row->min_stock, (int) $row->available, (bool) $row->min_from_master])
            ->all();
    }

    public function test_master_minimum_applies_per_warehouse_that_holds_the_sku(): void
    {
        $this->actingAs(User::where('username', 'superadmin')->first());

        // Jakarta 8 <= 10 menipis, Bandung 30 aman, Surabaya tidak ikut (tidak memegang kaos).
        $this->assertSame([
            ['KAOS', 'JKT01', 10, 8, true],
            ['KAOS', 'BDG01', 10, 30, true],
        ], $this->rows());

        $below = StockLevels::belowMinimum(StockLevels::minStockQuery())->toBase()->get();
        $this->assertSame(['JKT01'], $below->pluck('warehouse_code')->all());
    }

    public function test_warehouse_setting_overrides_master_minimum(): void
    {
        $this->actingAs(User::where('username', 'superadmin')->first());

        WarehouseVariantSetting::create(['warehouse_id' => $this->jakarta->id, 'product_variant_id' => $this->kaos->id, 'min_qty' => 5]);
        WarehouseVariantSetting::create(['warehouse_id' => $this->bandung->id, 'product_variant_id' => $this->kaos->id, 'min_qty' => 40]);
        // Topi belum punya minimum di Master Produk, tapi Surabaya mengaturnya sendiri.
        WarehouseVariantSetting::create(['warehouse_id' => $this->surabaya->id, 'product_variant_id' => $this->topi->id, 'min_qty' => 3]);

        $below = StockLevels::belowMinimum(StockLevels::minStockQuery())->toBase()->get()
            ->map(fn ($row) => "{$row->sku}@{$row->warehouse_code}")->sort()->values()->all();
        $this->assertSame(['KAOS@BDG01', 'TOPI@SBY01'], $below);

        $this->assertSame([['KAOS', 'BDG01', 40, 30, false]], $this->rows($this->bandung->id));

        // Notifikasi: satu baris per gudang dengan kunci gudang-varian.
        $groups = app(AlertService::class)->groups(User::where('username', 'superadmin')->first());
        $this->assertEqualsCanonicalizing(
            ["{$this->bandung->id}-{$this->kaos->id}", "{$this->surabaya->id}-{$this->topi->id}"],
            $groups['low_stock']['keys'],
        );

        $this->get(route('reports.min-stock'))->assertOk()->assertSee('BDG01')->assertSee('SBY01')->assertDontSee('JKT01</td>', false);
        $this->get('/notifications')->assertOk()->assertSee('BDG01 · Kaos Polos');
    }

    public function test_reorder_suggestions_are_per_warehouse(): void
    {
        $this->actingAs(User::where('username', 'superadmin')->first());

        WarehouseVariantSetting::create(['warehouse_id' => $this->bandung->id, 'product_variant_id' => $this->kaos->id, 'min_qty' => 40]);

        $rows = ReorderSuggestions::fromConfig(30, 0)->rows()->keyBy('warehouse_code');

        // Tanpa penjualan: saran = minimum gudang - tersedia.
        $this->assertSame(['BDG01', 'JKT01'], $rows->keys()->all());
        $this->assertSame(10, $rows['BDG01']->suggested);
        $this->assertSame(2, $rows['JKT01']->suggested);

        $only = ReorderSuggestions::fromConfig(30, 0)->rows($this->jakarta->id);
        $this->assertSame(['JKT01'], $only->pluck('warehouse_code')->all());
    }

    public function test_limited_user_only_sees_own_warehouse_rows(): void
    {
        $user = User::factory()->create()->assignRole('Staff Gudang');
        $user->syncWarehouseAccess(false, [$this->bandung->id]);
        $this->actingAs($user->fresh());

        $this->assertSame([['KAOS', 'BDG01', 10, 30, true]], $this->rows());
    }

    public function test_sku_per_warehouse_page_moved_to_inventory_menu(): void
    {
        $this->actingAs(User::where('username', 'superadmin')->first());

        $this->get(route('inventory.sku-settings.index', ['warehouse' => $this->jakarta->id]))->assertOk()
            ->assertSee('SKU per Gudang')->assertDontSee('Lokasi Tetap SKU')
            // Minimum Master Produk tampil sebagai placeholder kolom Min.
            ->assertSee('placeholder="10"', false);

        $this->get(route('settings'))->assertOk()->assertDontSee('Bin langganan tiap SKU');
    }
}
