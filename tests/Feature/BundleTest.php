<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\Courier;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Stock;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\SalesOrderService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class BundleTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $warehouse;

    private WarehouseLocation $bin;

    private ProductVariant $kaosM;

    private ProductVariant $kaosL;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::create(['code' => 'JKT01', 'name' => 'Gudang Jakarta', 'is_active' => true]);
        $zone = $this->warehouse->zones()->create(['code' => 'A', 'name' => 'Penyimpanan A', 'type' => 'storage', 'is_active' => true]);
        $rack = $zone->racks()->create(['code' => 'A01', 'levels' => 3, 'is_active' => true]);
        $this->bin = $rack->locations()->create(['warehouse_id' => $this->warehouse->id, 'code' => 'A01-01-01', 'type' => 'bin', 'is_active' => true]);

        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => true, 'is_active' => true]);
        $this->kaosM = $product->variants()->create(['sku' => 'KAOS-M', 'name' => 'M', 'is_active' => true]);
        $this->kaosL = $product->variants()->create(['sku' => 'KAOS-L', 'name' => 'L', 'is_active' => true]);

        // KAOS-M 10 di bin, KAOS-L 5 tanpa bin.
        app(StockService::class)->record(
            ['type' => 'in', 'warehouse_id' => $this->warehouse->id, 'movement_date' => now()->toDateString()],
            [
                ['product_variant_id' => $this->kaosM->id, 'to_location_id' => $this->bin->id, 'qty' => 10],
                ['product_variant_id' => $this->kaosL->id, 'to_location_id' => null, 'qty' => 5],
            ],
        );

        $this->actingAs(User::where('username', 'superadmin')->first());
    }

    /** Paket isi 2 KAOS-M + 1 KAOS-L. */
    private function bundle(): Bundle
    {
        $bundle = Bundle::create(['sku' => 'PAKET-3', 'name' => 'Paket Hemat 3 Kaos', 'price' => 200000, 'is_active' => true]);
        $bundle->items()->createMany([
            ['product_variant_id' => $this->kaosM->id, 'qty' => 2],
            ['product_variant_id' => $this->kaosL->id, 'qty' => 1],
        ]);

        return $bundle;
    }

    private function onHand(ProductVariant $variant): int
    {
        return (int) Stock::where('product_variant_id', $variant->id)->sum('qty');
    }

    public function test_bundle_master_crud_and_validation(): void
    {
        $this->get('/master/bundles')->assertOk();
        $this->get('/master/bundles/create')->assertOk()->assertSee('KAOS-M');

        $this->post('/master/bundles', [
            'sku' => 'kaos-m', 'name' => 'Bentrok', 'items' => [['product_variant_id' => $this->kaosM->id, 'qty' => 1]],
        ])->assertSessionHasErrors('sku');

        $this->post('/master/bundles', [
            'sku' => 'paket-x', 'name' => 'Dobel', 'items' => [
                ['product_variant_id' => $this->kaosM->id, 'qty' => 1],
                ['product_variant_id' => $this->kaosM->id, 'qty' => 2],
            ],
        ])->assertSessionHasErrors('items.1.product_variant_id');

        $this->post('/master/bundles', ['sku' => 'paket-x', 'name' => 'Kosong', 'items' => []])->assertSessionHasErrors('items');

        $this->post('/master/bundles', [
            'sku' => 'paket-3', 'name' => 'Paket Hemat 3 Kaos', 'price' => 200000, 'is_active' => 1, 'items' => [
                ['product_variant_id' => $this->kaosM->id, 'qty' => 2],
                ['product_variant_id' => $this->kaosL->id, 'qty' => 1],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect('/master/bundles');

        $bundle = Bundle::where('sku', 'PAKET-3')->firstOrFail();
        $this->assertCount(2, $bundle->items);
        $this->get("/master/bundles/{$bundle->id}/edit")->assertOk()->assertSee('PAKET-3');

        // Tersedia = min(10 / 2, 5 / 1) = 5 paket.
        $this->assertSame(5, app(SalesOrderService::class)->bundleAvailability($this->warehouse->id, [$bundle->load('items')])[$bundle->id]);
        $this->get('/master/bundles')->assertOk()->assertSee('PAKET-3');

        // SKU varian baru tidak boleh sama dengan SKU paket.
        $this->post('/master/products', [
            'sku' => 'PAKET-3', 'name' => 'Bentrok', 'unit_id' => Unit::first()->id, 'variants' => [['sku' => 'PAKET-3']],
        ])->assertSessionHasErrors('variants.0.sku');

        // Varian yang jadi isi paket tidak bisa ikut terhapus bersama produknya.
        $this->delete('/master/products/'.$this->kaosM->product_id);
        $this->assertNotNull($this->kaosM->fresh());

        $this->delete("/master/bundles/{$bundle->id}")->assertSessionHasNoErrors();
        $this->assertNull($bundle->fresh());
    }

    public function test_order_with_bundle_reserves_picks_and_ships_components(): void
    {
        $bundle = $this->bundle();

        $this->get('/sales/orders/create')->assertOk()->assertSee('PAKET-3');
        $this->post('/sales/orders', [
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'customer_name' => 'Budi',
            'action' => 'confirm',
            'items' => [
                ['bundle_id' => $bundle->id, 'qty' => 2, 'price' => 200000],
                ['product_variant_id' => $this->kaosL->id, 'qty' => 1, 'price' => 70000],
            ],
        ])->assertSessionHasNoErrors();

        $order = SalesOrder::with(['items', 'bundles'])->firstOrFail();
        $this->assertSame('confirmed', $order->status);
        $this->assertCount(1, $order->bundles);
        $this->assertSame(470000.0, $order->subtotal());
        // Isi paket dipecah: KAOS-M 4, KAOS-L 2, ditambah KAOS-L 1 biasa.
        $components = $order->items->whereNotNull('sales_order_bundle_id');
        $this->assertSame(4, $components->firstWhere('product_variant_id', $this->kaosM->id)->qty);
        $this->assertSame(2, $components->firstWhere('product_variant_id', $this->kaosL->id)->qty);

        $service = app(SalesOrderService::class);
        $availability = $service->availability($this->warehouse->id, [$this->kaosM->id, $this->kaosL->id]);
        $this->assertSame(6, $availability[$this->kaosM->id]['available']);
        $this->assertSame(2, $availability[$this->kaosL->id]['available']);
        $this->assertSame(2, $service->bundleAvailability($this->warehouse->id, [$bundle->load('items')])[$bundle->id]);

        // Perubahan isi paket di master tidak mengubah pesanan yang sudah ada.
        $bundle->items()->where('product_variant_id', $this->kaosM->id)->update(['qty' => 3]);
        $this->assertSame(4, $order->items()->where('product_variant_id', $this->kaosM->id)->whereNotNull('sales_order_bundle_id')->value('qty'));

        $this->get("/sales/orders/{$order->id}")->assertOk()->assertSee('PAKET-3')->assertSee('Isi paket');
        $this->get("/sales/orders/{$order->id}/picking")->assertOk()->assertSee('Isi paket PAKET-3');
        $this->get("/sales/orders/{$order->id}/pick-list")->assertOk()->assertSee('Isi paket PAKET-3');

        $this->post("/sales/orders/{$order->id}/pick", ['picks' => $service->suggestPicks($order)])->assertSessionHasNoErrors();
        $this->post("/sales/orders/{$order->id}/pack")->assertSessionHasNoErrors();
        $this->post("/sales/orders/{$order->id}/ship", ['courier_id' => Courier::first()->id, 'tracking_number' => 'SPX1'])->assertSessionHasNoErrors();

        $this->assertSame('shipped', $order->fresh()->status);
        $this->assertSame(6, $this->onHand($this->kaosM));
        $this->assertSame(2, $this->onHand($this->kaosL));

        // Paket yang sudah dipakai pesanan tidak bisa dihapus.
        $this->delete("/master/bundles/{$bundle->id}");
        $this->assertNotNull($bundle->fresh());
    }

    public function test_confirm_fails_when_bundle_components_short_and_edit_keeps_bundle_lines(): void
    {
        $bundle = $this->bundle();

        // 6 paket butuh KAOS-L 6, stok hanya 5.
        $this->post('/sales/orders', [
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'customer_name' => 'Budi',
            'action' => 'confirm',
            'items' => [['bundle_id' => $bundle->id, 'qty' => 6]],
        ])->assertSessionHasErrors();

        $order = SalesOrder::firstOrFail();
        $this->assertSame('draft', $order->status);
        $this->get("/sales/orders/{$order->id}")->assertOk()->assertSee('termasuk isi paket PAKET-3');

        $this->get("/sales/orders/{$order->id}/edit")->assertOk()->assertSee('b:'.$bundle->id);
        $this->put("/sales/orders/{$order->id}", [
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'customer_name' => 'Budi',
            'action' => 'confirm',
            'items' => [['bundle_id' => $bundle->id, 'qty' => 5, 'price' => 180000]],
        ])->assertSessionHasNoErrors();

        $order->refresh()->load(['items', 'bundles']);
        $this->assertSame('confirmed', $order->status);
        $this->assertCount(1, $order->bundles);
        $this->assertSame(10, (int) $order->items->sum(fn ($item) => $item->product_variant_id === $this->kaosM->id ? $item->qty : 0));

        // Paket nonaktif tidak bisa dipakai di pesanan baru.
        $bundle->update(['is_active' => false]);
        $this->post('/sales/orders', [
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'customer_name' => 'Siti',
            'items' => [['bundle_id' => $bundle->id, 'qty' => 1]],
        ])->assertSessionHasErrors('items.0.bundle_id');
    }

    public function test_import_accepts_bundle_sku(): void
    {
        $this->bundle();

        $csv = "no_pesanan;nama_pembeli;sku;qty;harga\n"
            ."B100;Budi;PAKET-3;2;200000\n"
            ."B100;Budi;KAOS-L;1;70000\n";

        $this->post('/sales/orders/import', [
            'warehouse_id' => $this->warehouse->id,
            'confirm' => 1,
            'file' => UploadedFile::fake()->createWithContent('pesanan.csv', $csv),
        ])->assertSessionHasNoErrors();

        $order = SalesOrder::with(['items', 'bundles'])->firstOrFail();
        $this->assertSame('confirmed', $order->status);
        $this->assertCount(1, $order->bundles);
        $this->assertSame(7, (int) $order->items->sum('qty'));
        $this->assertSame(470000.0, $order->subtotal());
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('paket.csv', $content);
    }

    public function test_bundle_import_creates_bundles_with_components(): void
    {
        $csv = "sku_paket;nama_paket;harga;sku_isi;jumlah_isi\n"
            ."paket-3;Paket Hemat 3 Kaos;200000;KAOS-M;2\n"
            ."PAKET-3;;;KAOS-L;\n"
            ."PAKET-M;Paket Kaos M;90000;KAOS-M;1\n";

        $this->get('/master/bundles/import')->assertOk()->assertSee('Impor Produk Paket');
        $this->post('/master/bundles/import', ['file' => $this->csv($csv)])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/master/bundles/import');

        $bundle = Bundle::where('sku', 'PAKET-3')->with('items')->firstOrFail();
        $this->assertSame('Paket Hemat 3 Kaos', $bundle->name);
        $this->assertEquals(200000, $bundle->price);
        $this->assertTrue($bundle->is_active);
        $this->assertSame([[$this->kaosM->id, 2], [$this->kaosL->id, 1]], $bundle->items->map(fn ($i) => [$i->product_variant_id, $i->qty])->all());
        $this->assertSame(2, Bundle::count());

        // Stok virtual langsung ikut isi: KAOS-M 10 / 2 = 5, KAOS-L 5 / 1 = 5.
        $this->assertSame(5, app(SalesOrderService::class)->bundleAvailability($this->warehouse->id, [$bundle])[$bundle->id]);
    }

    public function test_bundle_import_updates_existing_and_replaces_components(): void
    {
        $bundle = $this->bundle();

        $this->post('/master/bundles/import', ['file' => $this->csv("sku_paket,harga,sku_isi,jumlah_isi,aktif\nPAKET-3,250000,KAOS-L,3,Tidak\n")])
            ->assertSessionHasNoErrors();

        $bundle->refresh()->load('items');
        $this->assertSame('Paket Hemat 3 Kaos', $bundle->name, 'Kolom kosong tidak menimpa nilai lama.');
        $this->assertEquals(250000, $bundle->price);
        $this->assertFalse($bundle->is_active);
        $this->assertSame([[$this->kaosL->id, 3]], $bundle->items->map(fn ($i) => [$i->product_variant_id, $i->qty])->all());

        // Tanpa sku_isi: hanya data paket yang berubah, isi tetap.
        $this->post('/master/bundles/import', ['file' => $this->csv("sku_paket,nama_paket\nPAKET-3,Paket Baru\n")])->assertSessionHasNoErrors();
        $this->assertSame('Paket Baru', $bundle->refresh()->name);
        $this->assertSame(1, $bundle->items()->count());
    }

    public function test_bundle_import_rejects_bad_rows_and_saves_nothing(): void
    {
        $csv = "sku_paket,nama_paket,sku_isi,jumlah_isi\n"
            ."PAKET-OK,Paket OK,KAOS-M,1\n"
            ."PAKET-X,Paket X,TIDAK-ADA,1\n"
            ."PAKET-Y,Paket Y,KAOS-M,0\n"
            ."KAOS-L,Bentrok,KAOS-M,1\n"
            ."PAKET-Z,,KAOS-M,1\n"
            ."PAKET-K,Paket Kosong,,\n";

        $this->post('/master/bundles/import', ['file' => $this->csv($csv)])->assertSessionHasErrors('rows');

        $errors = session('errors')->get('rows');
        $this->assertCount(5, $errors);
        $this->assertStringContainsString('TIDAK-ADA tidak ada', $errors[0]);
        $this->assertSame(0, Bundle::count());
    }

    public function test_exported_bundles_can_be_imported_back(): void
    {
        $this->bundle();

        $response = $this->get('/master/bundles?export=xlsx')->assertOk()->assertDownload();
        $path = tempnam(sys_get_temp_dir(), 'exp').'.xlsx';
        copy($response->getFile()->getPathname(), $path);

        Bundle::query()->delete();
        $this->post('/master/bundles/import', ['file' => new UploadedFile($path, 'paket.xlsx', null, null, true)])->assertSessionHasNoErrors();

        $bundle = Bundle::with('items')->sole();
        $this->assertSame('PAKET-3', $bundle->sku);
        $this->assertSame([2, 1], $bundle->items->pluck('qty')->all());
    }

    public function test_stock_page_shows_virtual_bundle_stock(): void
    {
        $this->bundle();
        Bundle::create(['sku' => 'PAKET-OFF', 'name' => 'Paket Lama', 'is_active' => false])
            ->items()->create(['product_variant_id' => $this->kaosM->id, 'qty' => 1]);

        // KAOS-M 10 / 2 = 5, KAOS-L 5 / 1 = 5 → 5 paket.
        $this->get('/inventory/stocks?view=bundle')
            ->assertOk()
            ->assertSee('Paket (Virtual)')
            ->assertSee('PAKET-3')
            ->assertSee('Virtual')
            ->assertSeeInOrder(['PAKET-3', '5', 'paket'])
            ->assertDontSee('PAKET-OFF');

        $this->get('/inventory/stocks?view=bundle&zero=1&warehouse='.$this->warehouse->id)->assertOk()->assertSee('PAKET-OFF')->assertSeeText('Stok Jual');
        $this->get('/inventory/stocks?view=bundle&search=KAOS-L')->assertOk()->assertSee('PAKET-3');
        $this->get('/inventory/stocks?view=bundle&search=TIDAK-ADA')->assertOk()->assertDontSee('PAKET-3');

        $response = $this->get('/inventory/stocks?view=bundle&export=xlsx')->assertOk()->assertDownload();
        $reader = new Reader;
        $reader->open($response->getFile()->getPathname());
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();
        $this->assertSame(['PAKET-3', 'Paket Hemat 3 Kaos', 'Virtual', 'KAOS-M × 2, KAOS-L × 1', 0, 5, 'Aktif'], $rows[1]);
    }
}
