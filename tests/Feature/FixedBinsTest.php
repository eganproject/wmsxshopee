<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseVariantLocation;
use App\Models\WarehouseVariantSetting;
use App\Services\FixedBins;
use App\Services\SalesOrderService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lokasi tetap SKU dengan beberapa bin berurutan (utama lalu cadangan).
 */
class FixedBinsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $small;

    private Warehouse $big;

    private WarehouseLocation $main;

    private WarehouseLocation $backup;

    private WarehouseLocation $other;

    private WarehouseLocation $pallet;

    private ProductVariant $kaos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->big = Warehouse::create(['code' => 'GB01', 'name' => 'Gudang Besar', 'is_active' => true]);
        $this->small = Warehouse::create(['code' => 'GK01', 'name' => 'Gudang Kecil', 'is_active' => true, 'supply_warehouse_id' => $this->big->id, 'supply_stock_percent' => 100]);

        $rack = $this->small->zones()->create(['code' => 'A', 'name' => 'Picking', 'type' => 'picking', 'is_active' => true])
            ->racks()->create(['code' => 'A01', 'levels' => 1, 'is_active' => true]);
        $this->main = $rack->locations()->create(['warehouse_id' => $this->small->id, 'code' => 'A01-01-01', 'type' => 'bin', 'is_active' => true]);
        $this->backup = $rack->locations()->create(['warehouse_id' => $this->small->id, 'code' => 'A01-01-02', 'type' => 'bin', 'is_active' => true]);
        $this->other = $rack->locations()->create(['warehouse_id' => $this->small->id, 'code' => 'A01-01-03', 'type' => 'bin', 'is_active' => true]);

        $bulk = $this->big->zones()->create(['code' => 'B', 'name' => 'Bulk', 'type' => 'storage', 'is_active' => true])
            ->racks()->create(['code' => 'GB-A', 'levels' => 1, 'is_active' => true]);
        $this->pallet = $bulk->locations()->create(['warehouse_id' => $this->big->id, 'code' => 'GB-A-01-01', 'type' => 'pallet', 'is_active' => true]);

        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => false, 'is_active' => true]);
        $this->kaos = $product->variants()->create(['sku' => 'KAOS', 'is_active' => true]);

        $this->actingAs(User::where('username', 'superadmin')->first());
    }

    private function stockIn(Warehouse $warehouse, WarehouseLocation $location, int $qty): void
    {
        app(StockService::class)->record(['type' => 'in', 'warehouse_id' => $warehouse->id, 'movement_date' => now()->toDateString()],
            [['product_variant_id' => $this->kaos->id, 'to_location_id' => $location->id, 'qty' => $qty]]);
    }

    private function saveBins(array $bins, array $extra = []): void
    {
        $this->put('/inventory/sku-settings', [
            'warehouse_id' => $this->small->id,
            'settings' => [$this->kaos->id => ['bins' => $bins, ...$extra]],
        ])->assertSessionHasNoErrors();
    }

    public function test_settings_page_saves_ordered_bins_and_keeps_main_bin_as_default(): void
    {
        $this->saveBins([
            ['location_id' => $this->backup->id, 'capacity' => ''],
            ['location_id' => $this->main->id, 'capacity' => 30],
        ]);

        $rows = WarehouseVariantLocation::where('product_variant_id', $this->kaos->id)->orderBy('priority')->get();
        $this->assertSame([$this->backup->id, $this->main->id], $rows->pluck('warehouse_location_id')->all());
        $this->assertSame([null, 30], $rows->pluck('capacity')->all());
        $this->assertSame($this->backup->id, WarehouseVariantSetting::where('product_variant_id', $this->kaos->id)->value('default_location_id'));

        $this->get("/inventory/sku-settings?warehouse={$this->small->id}")->assertOk()->assertSee('Tambah bin cadangan');

        // Semua bin dihapus: pengaturan ikut terhapus.
        $this->saveBins([]);
        $this->assertSame(0, WarehouseVariantLocation::count());
        $this->assertSame(0, WarehouseVariantSetting::count());
    }

    public function test_same_bin_twice_is_rejected(): void
    {
        $this->put('/inventory/sku-settings', [
            'warehouse_id' => $this->small->id,
            'settings' => [$this->kaos->id => ['bins' => [['location_id' => $this->main->id], ['location_id' => $this->main->id]]]],
        ])->assertSessionHasErrors("settings.{$this->kaos->id}.bins");
    }

    public function test_split_fills_main_bin_up_to_its_room_then_backup(): void
    {
        $this->stockIn($this->small, $this->main, 20);
        $this->saveBins([['location_id' => $this->main->id, 'capacity' => 50], ['location_id' => $this->backup->id]]);

        $fixed = app(FixedBins::class);
        $bins = $fixed->for([$this->small->id])[$this->small->id.':'.$this->kaos->id];
        $this->assertSame(30, $bins[0]['room']);
        $this->assertNull($bins[1]['room']);

        $this->assertSame([
            ['location_id' => $this->main->id, 'qty' => 30],
            ['location_id' => $this->backup->id, 'qty' => 70],
        ], $fixed->split($bins, 100));
        $this->assertSame([['location_id' => $this->main->id, 'qty' => 10]], $fixed->split($bins, 10));

        // Data form penerimaan: bin utama + sisa ruang, lalu cadangan tanpa batas.
        $form = $this->get('/inventory/receipts/create')->assertOk();
        $this->assertContains([$this->small->id, $this->kaos->id, [[$this->main->id, 30], [$this->backup->id, null]]], $form->viewData('defaults'));
    }

    public function test_refill_transfer_is_split_between_main_and_backup_bins(): void
    {
        $this->stockIn($this->small, $this->main, 5);
        $this->stockIn($this->big, $this->pallet, 200);
        $this->saveBins([['location_id' => $this->main->id, 'capacity' => 25], ['location_id' => $this->backup->id]], ['min_qty' => 10, 'max_qty' => 50]);

        // Butuh 45: bin utama muat 20, sisanya 25 ke bin cadangan.
        $prefill = $this->get("/inventory/transfers/create?suggest={$this->small->id}")->assertOk()->viewData('prefill');
        $this->assertSame([[(string) $this->main->id, 20], [(string) $this->backup->id, 25]], collect($prefill)->map(fn ($r) => [$r['to_location_id'], $r['qty']])->all());

        // Dua baris dari bin asal yang sama ke bin tujuan berbeda boleh disimpan.
        $this->post('/inventory/transfers', [
            'from_warehouse_id' => $this->big->id,
            'to_warehouse_id' => $this->small->id,
            'transfer_date' => now()->toDateString(),
            'items' => collect($prefill)->map(fn ($r) => [...$r, 'notes' => ''])->all(),
        ])->assertSessionHasNoErrors();
    }

    public function test_picking_takes_main_bin_first_then_backup(): void
    {
        $this->stockIn($this->small, $this->other, 100);
        $this->stockIn($this->small, $this->backup, 50);
        $this->stockIn($this->small, $this->main, 8);
        $this->saveBins([['location_id' => $this->main->id], ['location_id' => $this->backup->id]]);

        $order = SalesOrder::create(['number' => 'SO-1', 'warehouse_id' => $this->small->id, 'order_date' => now()->toDateString(), 'customer_name' => 'Budi', 'status' => 'draft']);
        $item = $order->items()->create(['product_variant_id' => $this->kaos->id, 'qty' => 12, 'price' => 0]);
        app(SalesOrderService::class)->confirm($order);

        $this->assertSame([
            ['warehouse_location_id' => $this->main->id, 'qty' => 8],
            ['warehouse_location_id' => $this->backup->id, 'qty' => 4],
        ], app(SalesOrderService::class)->suggestPicks($order->fresh())[$item->id]);
    }

    public function test_picking_without_fixed_bins_still_prefers_largest_bin(): void
    {
        $this->stockIn($this->small, $this->main, 8);
        $this->stockIn($this->small, $this->other, 100);

        $order = SalesOrder::create(['number' => 'SO-1', 'warehouse_id' => $this->small->id, 'order_date' => now()->toDateString(), 'customer_name' => 'Budi', 'status' => 'draft']);
        $item = $order->items()->create(['product_variant_id' => $this->kaos->id, 'qty' => 12, 'price' => 0]);
        app(SalesOrderService::class)->confirm($order);

        $this->assertSame([['warehouse_location_id' => $this->other->id, 'qty' => 12]], app(SalesOrderService::class)->suggestPicks($order->fresh())[$item->id]);
    }
}
