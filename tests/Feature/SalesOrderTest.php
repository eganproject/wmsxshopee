<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Stock;
use App\Models\StockLedger;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\SalesOrderService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class SalesOrderTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $warehouse;

    private WarehouseLocation $binA;

    private WarehouseLocation $binB;

    private ProductVariant $variant;

    private ProductVariant $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::create(['code' => 'JKT01', 'name' => 'Gudang Jakarta', 'is_active' => true]);
        $zone = $this->warehouse->zones()->create(['code' => 'A', 'name' => 'Penyimpanan A', 'type' => 'storage', 'is_active' => true]);
        $rack = $zone->racks()->create(['code' => 'A01', 'levels' => 3, 'is_active' => true]);
        $this->binA = $rack->locations()->create(['warehouse_id' => $this->warehouse->id, 'code' => 'A01-01-01', 'type' => 'bin', 'is_active' => true]);
        $this->binB = $rack->locations()->create(['warehouse_id' => $this->warehouse->id, 'code' => 'A01-01-02', 'type' => 'bin', 'is_active' => true]);

        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => true, 'is_active' => true]);
        $this->variant = $product->variants()->create(['sku' => 'KAOS-M', 'name' => 'M', 'barcode' => '8991001', 'is_active' => true]);
        $this->other = $product->variants()->create(['sku' => 'KAOS-L', 'name' => 'L', 'is_active' => true]);

        // Stok awal: 6 di bin A, 4 di bin B untuk KAOS-M; 5 tanpa bin untuk KAOS-L.
        app(StockService::class)->record(
            ['type' => 'in', 'warehouse_id' => $this->warehouse->id, 'movement_date' => now()->toDateString()],
            [
                ['product_variant_id' => $this->variant->id, 'to_location_id' => $this->binA->id, 'qty' => 6],
                ['product_variant_id' => $this->variant->id, 'to_location_id' => $this->binB->id, 'qty' => 4],
                ['product_variant_id' => $this->other->id, 'to_location_id' => null, 'qty' => 5],
            ],
        );

        $this->actingAs(User::where('username', 'superadmin')->first());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'channel_name' => 'Shopee Toko A',
            'external_number' => 'ORD-1',
            'customer_name' => 'Budi',
            'items' => [
                ['product_variant_id' => $this->variant->id, 'qty' => 8, 'price' => 75000],
                ['product_variant_id' => $this->other->id, 'qty' => 2, 'price' => 70000],
            ],
            ...$overrides,
        ];
    }

    private function onHand(ProductVariant $variant, ?WarehouseLocation $location): int
    {
        return (int) Stock::where('product_variant_id', $variant->id)
            ->where('warehouse_location_id', $location?->id)
            ->value('qty');
    }

    public function test_full_flow_reserves_picks_and_ships_stock(): void
    {
        $this->get('/sales/orders/create')->assertOk();
        $this->post('/sales/orders', $this->payload(['action' => 'confirm']))->assertSessionHasNoErrors();

        $order = SalesOrder::firstOrFail();
        $this->assertSame('SO'.now()->format('ym').'-001', $order->number);
        $this->assertSame('confirmed', $order->status);
        $this->get("/sales/orders/{$order->id}")->assertOk()->assertSee('SO'.now()->format('ym').'-001');
        $this->get("/sales/orders/{$order->id}/pick-list")->assertOk()->assertSee('A01-01-01');

        // Konfirmasi tidak mengubah stok fisik, hanya mencadangkan.
        $this->assertSame(6, $this->onHand($this->variant, $this->binA));

        // Usulan picking: bin dengan stok terbanyak dulu (A=6, lalu B=2), KAOS-L dari tanpa bin.
        $this->get("/sales/orders/{$order->id}/picking")->assertOk();
        [$itemM, $itemL] = $order->items->all();
        $this->post("/sales/orders/{$order->id}/pick", ['picks' => [
            $itemM->id => [['warehouse_location_id' => $this->binA->id, 'qty' => 6], ['warehouse_location_id' => $this->binB->id, 'qty' => 2]],
            $itemL->id => [['warehouse_location_id' => '', 'qty' => 2]],
        ]])->assertSessionHasNoErrors();
        $this->assertSame('picked', $order->fresh()->status);

        $this->post("/sales/orders/{$order->id}/pack")->assertSessionHasNoErrors();
        $this->post("/sales/orders/{$order->id}/ship", ['courier_id' => Courier::first()->id, 'tracking_number' => 'JNE123'])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('shipped', $order->status);
        $this->assertSame('JNE123', $order->tracking_number);
        $this->assertSame(0, $this->onHand($this->variant, $this->binA));
        $this->assertSame(2, $this->onHand($this->variant, $this->binB));
        $this->assertSame(3, $this->onHand($this->other, null));
        $this->assertSame(10, (int) StockLedger::where('stock_movement_id', $order->stock_movement_id)->sum('qty_out'));
        $this->assertSame($order->number, $order->movement->reference);
    }

    public function test_self_pickup_direct_sale_skips_courier_and_hands_to_customer(): void
    {
        $this->post('/sales/orders', $this->payload([
            'action' => 'confirm',
            'channel_name' => 'WhatsApp',
            'external_number' => '',
            'delivery_type' => 'self_pickup',
            'courier_id' => Courier::first()->id,
            'shipping_fee' => 15000,
            'discount' => 20000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 2, 'price' => 75000]],
        ]))->assertSessionHasNoErrors();

        $order = SalesOrder::firstOrFail();
        $this->assertTrue($order->isSelfPickup());
        $this->assertNull($order->courier_id);
        $this->assertSame(0.0, (float) $order->shipping_fee);
        $this->assertSame(130000.0, $order->grandTotal());
        $this->assertFalse($order->canArrangeShipment());

        $this->get("/sales/orders/{$order->id}")->assertOk()->assertSee('Diambil Pembeli')->assertSee('Lunas')->assertDontSee('Atur Pengiriman');

        [$item] = $order->items->all();
        $this->post("/sales/orders/{$order->id}/pick", ['picks' => [
            $item->id => [['warehouse_location_id' => $this->binA->id, 'qty' => 2]],
        ]])->assertSessionHasNoErrors();
        $this->post("/sales/orders/{$order->id}/pack")->assertSessionHasNoErrors();
        $this->get("/sales/orders/{$order->id}")->assertSee('Serahkan ke Pembeli');

        $this->post("/sales/orders/{$order->id}/hand-to-customer")->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('shipped', $order->status);
        $this->assertNull($order->tracking_number);
        $this->assertSame(4, $this->onHand($this->variant, $this->binA));
    }

    public function test_hand_to_customer_rejects_courier_orders(): void
    {
        $this->post('/sales/orders', $this->payload(['action' => 'confirm']))->assertSessionHasNoErrors();

        $this->post('/sales/orders/'.SalesOrder::firstOrFail()->id.'/hand-to-customer')->assertForbidden();
    }

    public function test_confirm_fails_when_reserved_by_other_orders(): void
    {
        $this->post('/sales/orders', $this->payload(['action' => 'confirm', 'items' => [['product_variant_id' => $this->variant->id, 'qty' => 7]]]));
        $this->post('/sales/orders', $this->payload(['external_number' => 'ORD-2', 'action' => 'confirm', 'items' => [['product_variant_id' => $this->variant->id, 'qty' => 4]]]))
            ->assertSessionHasErrors();

        $second = SalesOrder::where('external_number', 'ORD-2')->firstOrFail();
        $this->assertSame('draft', $second->status);

        // Batalkan pesanan pertama: cadangan lepas, pesanan kedua bisa dikonfirmasi.
        $first = SalesOrder::where('external_number', 'ORD-1')->firstOrFail();
        $this->post("/sales/orders/{$first->id}/cancel", ['cancel_reason' => 'Dibatalkan pembeli'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $first->fresh()->status);

        $this->post("/sales/orders/{$second->id}/confirm")->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $second->fresh()->status);
    }

    public function test_pick_rejects_wrong_total_and_bins_allocated_to_other_orders(): void
    {
        $this->post('/sales/orders', $this->payload(['action' => 'confirm', 'items' => [['product_variant_id' => $this->variant->id, 'qty' => 5]]]));
        $this->post('/sales/orders', $this->payload(['external_number' => 'ORD-2', 'action' => 'confirm', 'items' => [['product_variant_id' => $this->variant->id, 'qty' => 3]]]));
        [$first, $second] = SalesOrder::orderBy('id')->with('items')->get()->all();

        $this->post("/sales/orders/{$first->id}/pick", ['picks' => [$first->items[0]->id => [['warehouse_location_id' => $this->binA->id, 'qty' => 4]]]])
            ->assertSessionHasErrors("picks.{$first->items[0]->id}");

        $this->post("/sales/orders/{$first->id}/pick", ['picks' => [$first->items[0]->id => [['warehouse_location_id' => $this->binA->id, 'qty' => 5]]]])
            ->assertSessionHasNoErrors();

        // Bin A tinggal 1 bebas; usulan untuk pesanan kedua memakai bin B.
        $suggestion = app(SalesOrderService::class)->suggestPicks($second);
        $this->assertSame([['warehouse_location_id' => $this->binB->id, 'qty' => 3]], $suggestion[$second->items[0]->id]);

        $this->post("/sales/orders/{$second->id}/pick", ['picks' => [$second->items[0]->id => [['warehouse_location_id' => $this->binA->id, 'qty' => 3]]]])
            ->assertSessionHasErrors("picks.{$second->items[0]->id}");
    }

    public function test_duplicate_marketplace_number_rejected_and_only_draft_editable(): void
    {
        $this->post('/sales/orders', $this->payload(['action' => 'draft']))->assertSessionHasNoErrors();
        $this->post('/sales/orders', $this->payload(['action' => 'draft']))->assertSessionHasErrors('external_number');

        $order = SalesOrder::firstOrFail();
        $this->get("/sales/orders/{$order->id}/edit")->assertOk();
        $this->post("/sales/orders/{$order->id}/confirm");
        $this->get("/sales/orders/{$order->id}/edit")->assertRedirect("/sales/orders/{$order->id}");
        $this->put("/sales/orders/{$order->id}", $this->payload())->assertForbidden();

        $this->post("/sales/orders/{$order->id}/reopen")->assertRedirect("/sales/orders/{$order->id}/edit");
        $this->assertSame('draft', $order->fresh()->status);
    }

    public function test_csv_import_groups_rows_and_skips_existing(): void
    {
        $csv = "no_pesanan;tanggal;toko;nama_pembeli;kurir;sku;qty;harga\n"
            .'A100;'.now()->format('d/m/Y').";Shopee Toko A;Budi;JNE;KAOS-M;2;75000\n"
            .'A100;'.now()->format('d/m/Y').";Shopee Toko A;Budi;JNE;8991001;1;75000\n"
            ."A101;;Shopee Toko A;Siti;;KAOS-L;9;70000\n"
            .";;WhatsApp;Andi;;KAOS-L;1;\n";

        $upload = fn () => $this->post('/sales/orders/import', [
            'warehouse_id' => $this->warehouse->id,
            'confirm' => 1,
            'file' => UploadedFile::fake()->createWithContent('pesanan.csv', $csv),
        ]);

        $upload()->assertSessionHasNoErrors();
        $this->assertSame(3, SalesOrder::count());
        $a100 = SalesOrder::where('external_number', 'A100')->firstOrFail();
        $this->assertSame(3, (int) $a100->items()->sum('qty'));
        $this->assertSame('confirmed', $a100->status);
        $this->assertSame(Courier::where('code', 'JNE')->value('id') ?? $a100->courier_id, $a100->courier_id);
        // A101 butuh 9 KAOS-L padahal stok 5: tetap draft.
        $this->assertSame('draft', SalesOrder::where('external_number', 'A101')->value('status'));

        $upload()->assertSessionHasNoErrors();
        $this->assertSame(4, SalesOrder::count(), 'Baris tanpa no. pesanan selalu jadi pesanan baru; yang bernomor dilewati.');
        $this->assertCount(2, session('import')['skipped']);
    }

    public function test_import_with_bad_rows_creates_nothing(): void
    {
        $csv = "nama_pembeli,sku,qty\nBudi,KAOS-M,2\nSiti,TIDAK-ADA,1\nAndi,KAOS-L,0\n";

        $this->post('/sales/orders/import', [
            'warehouse_id' => $this->warehouse->id,
            'file' => UploadedFile::fake()->createWithContent('pesanan.csv', $csv),
        ])->assertSessionHasErrors('rows');

        $this->assertSame(0, SalesOrder::count());
    }

    public function test_xlsx_import_reads_date_cells(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'so').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Nama Pembeli', 'SKU', 'Qty', 'Tanggal', 'No Pesanan']));
        $writer->addRow(Row::fromValues(['Budi', 'KAOS-M', 2, new \DateTimeImmutable('2026-10-01'), 'X1']));
        $writer->close();

        $this->post('/sales/orders/import', [
            'warehouse_id' => $this->warehouse->id,
            'file' => new UploadedFile($path, 'pesanan.xlsx', null, null, true),
        ])->assertSessionHasNoErrors();

        $order = SalesOrder::firstOrFail();
        $this->assertSame('2026-10-01', $order->order_date->toDateString());
        $this->assertSame('X1', $order->external_number);
        $this->assertSame('draft', $order->status);
    }

    public function test_import_templates_download(): void
    {
        $this->get('/sales/orders/import')->assertOk();
        $this->get('/sales/orders/import/template.csv')->assertOk()->assertDownload('template-impor-pesanan.csv');
        $this->get('/sales/orders/import/template.xlsx')->assertOk()->assertDownload('template-impor-pesanan.xlsx');
    }

    public function test_staff_without_permission_cannot_create(): void
    {
        $staff = User::create(['name' => 'Staff', 'username' => 'staff', 'email' => 'staff@wms.local', 'password' => 'password', 'is_active' => true]);
        $staff->assignRole('Staff Gudang');

        $this->actingAs($staff);
        $this->get('/sales/orders')->assertOk();
        $this->get('/sales/orders/create')->assertForbidden();
    }
}
