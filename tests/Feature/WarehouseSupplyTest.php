<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\ShopeeShop;
use App\Models\Stock;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseTransfer;
use App\Models\WarehouseVariantSetting;
use App\Services\SalesOrderService;
use App\Services\Shopee\ShopeeStockSync;
use App\Services\StockService;
use App\Services\WarehouseSupply;
use App\Support\Cartons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Gudang kecil (fulfilment) + gudang besar (cadangan, stok koli).
 */
class WarehouseSupplyTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $small;

    private Warehouse $big;

    private WarehouseLocation $bin;

    private WarehouseLocation $pallet;

    private ProductVariant $kaos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->big = Warehouse::create(['code' => 'GB01', 'name' => 'Gudang Besar', 'is_active' => true]);
        $this->small = Warehouse::create(['code' => 'GK01', 'name' => 'Gudang Kecil', 'is_active' => true, 'supply_warehouse_id' => $this->big->id, 'supply_stock_percent' => 100]);

        $rack = $this->small->zones()->create(['code' => 'A', 'name' => 'Picking', 'type' => 'picking', 'is_active' => true])
            ->racks()->create(['code' => 'A01', 'levels' => 1, 'is_active' => true]);
        $this->bin = $rack->locations()->create(['warehouse_id' => $this->small->id, 'code' => 'A01-01-01', 'type' => 'bin', 'is_active' => true]);

        $bulk = $this->big->zones()->create(['code' => 'B', 'name' => 'Bulk', 'type' => 'storage', 'is_active' => true])
            ->racks()->create(['code' => 'GB-A', 'levels' => 1, 'is_active' => true]);
        $this->pallet = $bulk->locations()->create(['warehouse_id' => $this->big->id, 'code' => 'GB-A-01-01', 'type' => 'pallet', 'is_active' => true]);

        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => false, 'is_active' => true]);
        $this->kaos = $product->variants()->create(['sku' => 'KAOS', 'is_active' => true, 'pcs_per_carton' => 24]);

        $stock = app(StockService::class);
        $stock->record(['type' => 'in', 'warehouse_id' => $this->small->id, 'movement_date' => now()->toDateString()],
            [['product_variant_id' => $this->kaos->id, 'to_location_id' => $this->bin->id, 'qty' => 5]]);
        $stock->record(['type' => 'in', 'warehouse_id' => $this->big->id, 'movement_date' => now()->toDateString()],
            [['product_variant_id' => $this->kaos->id, 'to_location_id' => $this->pallet->id, 'qty' => 240]]);

        $this->actingAs(User::where('username', 'superadmin')->first());
    }

    private function qty(Warehouse $warehouse): int
    {
        return (int) Stock::where('warehouse_id', $warehouse->id)->where('product_variant_id', $this->kaos->id)->sum('qty');
    }

    private function send(int $qty): WarehouseTransfer
    {
        $this->post('/inventory/transfers', [
            'from_warehouse_id' => $this->big->id,
            'to_warehouse_id' => $this->small->id,
            'transfer_date' => now()->toDateString(),
            'items' => [['product_variant_id' => $this->kaos->id, 'from_location_id' => $this->pallet->id, 'to_location_id' => $this->bin->id, 'qty' => $qty]],
        ])->assertSessionHasNoErrors();

        return WarehouseTransfer::latest('id')->first();
    }

    public function test_carton_label(): void
    {
        $this->assertSame('10 koli', Cartons::label(240, 24));
        $this->assertSame('1 koli + 5 pcs', Cartons::label(29, 24));
        $this->assertNull(Cartons::label(5, 24));
        $this->assertNull(Cartons::label(5, null));
        $this->assertSame(48, Cartons::roundUp(30, 24));
    }

    public function test_sellable_stock_adds_supply_warehouse_with_buffer_and_transit(): void
    {
        $supply = app(WarehouseSupply::class);
        $this->assertSame(245, $supply->sellable($this->small->id, [$this->kaos->id])[$this->kaos->id]['sellable']);

        // Gudang tanpa cadangan tidak berubah.
        $this->assertSame(240, $supply->sellable($this->big->id, [$this->kaos->id])[$this->kaos->id]['sellable']);

        $this->small->update(['supply_stock_percent' => 50]);
        $this->assertSame(125, $supply->sellable($this->small->id, [$this->kaos->id])[$this->kaos->id]['sellable']);

        // Dalam perjalanan tetap dihitung sekali.
        $this->small->update(['supply_stock_percent' => 100]);
        $this->send(48);
        $row = $supply->sellable($this->small->id, [$this->kaos->id])[$this->kaos->id];
        $this->assertSame([5, 192, 48, 245], [$row['own'], $row['supply'], $row['transit'], $row['sellable']]);
    }

    public function test_shopee_stock_push_uses_combined_stock(): void
    {
        $shop = ShopeeShop::create(['environment' => 'test', 'shop_id' => 1, 'shop_name' => 'Toko', 'warehouse_id' => $this->small->id, 'status' => 'connected']);
        $mapping = $shop->mappings()->create(['item_id' => 1, 'model_id' => 0, 'shopee_sku' => 'KAOS', 'product_variant_id' => $this->kaos->id]);

        $levels = app(ShopeeStockSync::class)->levels($shop, collect([$mapping]));
        $this->assertSame(245, $levels($mapping));

        $this->get('/marketplace/products')->assertOk()->assertSee('kecil 5 + besar 240');
    }

    public function test_transfer_send_receive_and_cancel(): void
    {
        $transfer = $this->send(48);
        $this->assertSame('sent', $transfer->status);
        $this->assertSame(192, $this->qty($this->big));
        $this->assertSame(5, $this->qty($this->small));

        $this->get("/inventory/transfers/{$transfer->id}")->assertOk()->assertSee('2 koli');

        $item = $transfer->items()->first();
        $this->post("/inventory/transfers/{$transfer->id}/receive", ['items' => [$item->id => ['received_qty' => 46, 'to_location_id' => $this->bin->id]]])
            ->assertSessionHasNoErrors();
        $this->assertSame('received', $transfer->fresh()->status);
        $this->assertSame(51, $this->qty($this->small));
        $this->assertSame(46, $item->fresh()->received_qty);

        $other = $this->send(24);
        $this->post("/inventory/transfers/{$other->id}/cancel", ['cancel_reason' => 'Batal'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $other->fresh()->status);
        $this->assertSame(192, $this->qty($this->big));
    }

    public function test_order_beyond_small_stock_is_confirmed_but_waits_for_transfer_before_picking(): void
    {
        $service = app(SalesOrderService::class);
        $order = SalesOrder::create(['number' => 'SO-1', 'warehouse_id' => $this->small->id, 'order_date' => now()->toDateString(), 'customer_name' => 'Budi', 'status' => 'draft']);
        $order->items()->create(['product_variant_id' => $this->kaos->id, 'qty' => 20, 'price' => 0]);

        $service->confirm($order);
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame(['KAOS' => 15], $service->shortage($order->fresh())->mapWithKeys(fn ($r) => [$r['sku'] => $r['short']])->all());

        $this->get("/sales/orders/{$order->id}")->assertOk()->assertSee('Menunggu stok dari gudang cadangan');

        try {
            $service->pick($order->fresh(), [$order->items->first()->id => [['warehouse_location_id' => $this->bin->id, 'qty' => 20]]]);
            $this->fail('Picking harus ditolak sebelum transfer diterima.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('gudang cadangan', collect($e->errors())->flatten()->first());
        }

        // Saran isi ulang: kurang 15, dibulatkan ke 1 koli (24).
        $suggestion = app(WarehouseSupply::class)->suggestions($this->small)->first();
        $this->assertTrue($suggestion->urgent);
        $this->assertSame(24, $suggestion->qty);
        $this->get('/notifications')->assertOk()->assertSee('Gudang kecil perlu diisi ulang');

        $transfer = $this->send(24);
        $this->post("/inventory/transfers/{$transfer->id}/receive", ['items' => [$transfer->items->first()->id => ['received_qty' => 24, 'to_location_id' => $this->bin->id]]]);

        $this->assertTrue($service->shortage($order->fresh())->isEmpty());
        $service->pick($order->fresh(), [$order->items->first()->id => [['warehouse_location_id' => $this->bin->id, 'qty' => 20]]]);
        $this->assertSame('picking', $order->fresh()->status);
    }

    public function test_order_beyond_combined_stock_is_rejected(): void
    {
        $order = SalesOrder::create(['number' => 'SO-2', 'warehouse_id' => $this->small->id, 'order_date' => now()->toDateString(), 'customer_name' => 'Budi', 'status' => 'draft']);
        $order->items()->create(['product_variant_id' => $this->kaos->id, 'qty' => 300, 'price' => 0]);

        $this->expectException(ValidationException::class);
        app(SalesOrderService::class)->confirm($order);
    }

    public function test_min_max_suggestion_and_sku_settings_page(): void
    {
        $this->get("/inventory/sku-settings?warehouse={$this->small->id}")->assertOk()->assertSee('KAOS');

        $this->put('/inventory/sku-settings', [
            'warehouse_id' => $this->small->id,
            'settings' => [$this->kaos->id => ['default_location_id' => $this->bin->id, 'min_qty' => 10, 'max_qty' => 50]],
        ])->assertSessionHasNoErrors();

        $setting = WarehouseVariantSetting::where('warehouse_id', $this->small->id)->first();
        $this->assertSame($this->bin->id, $setting->default_location_id);

        // Tersedia 5 <= min 10: isi sampai maks 50 -> butuh 45 -> 2 koli = 48.
        $row = app(WarehouseSupply::class)->suggestions($this->small)->first();
        $this->assertFalse($row->urgent);
        $this->assertSame(48, $row->qty);

        $this->get('/inventory/transfers')->assertOk()->assertSee('Saran Isi Ulang GK01');
        $create = $this->get("/inventory/transfers/create?suggest={$this->small->id}")->assertOk();
        $this->assertSame(48, $create->viewData('prefill')[0]['qty']);
        $this->assertSame((string) $this->pallet->id, $create->viewData('prefill')[0]['from_location_id']);
        $this->assertSame((string) $this->bin->id, $create->viewData('prefill')[0]['to_location_id']);

        $this->put('/inventory/sku-settings', [
            'warehouse_id' => $this->small->id,
            'settings' => [$this->kaos->id => ['min_qty' => 60, 'max_qty' => 50]],
        ])->assertSessionHasErrors("settings.{$this->kaos->id}.max_qty");
    }

    public function test_warehouse_form_links_supply_and_pages_render(): void
    {
        $this->put("/master/warehouses/{$this->big->id}", ['code' => 'GB01', 'name' => 'Gudang Besar', 'is_active' => 1, 'supply_warehouse_id' => $this->small->id])
            ->assertSessionHasErrors('supply_warehouse_id');

        $this->put("/master/warehouses/{$this->small->id}", ['code' => 'GK01', 'name' => 'Gudang Kecil', 'is_active' => 1, 'supply_warehouse_id' => $this->big->id, 'supply_stock_percent' => 90])
            ->assertSessionHasNoErrors();
        $this->assertSame(90, $this->small->fresh()->supply_stock_percent);

        $this->get("/master/warehouses/{$this->small->id}/edit")->assertOk()->assertSee('Gudang Cadangan');
        $this->get('/master/warehouses')->assertOk()->assertSee('Cadangan: GB01');
        $this->get("/inventory/stocks?warehouse={$this->big->id}")->assertOk()->assertSee('10 koli');
        $this->get('/inventory/transfers/create')->assertOk();
        $this->get('/notifications')->assertOk();
    }
}
