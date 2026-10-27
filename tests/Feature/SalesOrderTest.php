<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SavedFilter;
use App\Models\ShopeeShop;
use App\Models\Stock;
use App\Models\StockLedger;
use App\Models\TiktokShop;
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
        $this->assertSame('picking', $order->fresh()->status);

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

    public function test_bulk_pick_uses_suggested_bins_without_double_allocation(): void
    {
        $this->post('/sales/orders', $this->payload(['action' => 'confirm', 'items' => [['product_variant_id' => $this->variant->id, 'qty' => 5]]]));
        $this->post('/sales/orders', $this->payload(['external_number' => 'ORD-2', 'action' => 'confirm', 'items' => [['product_variant_id' => $this->variant->id, 'qty' => 4], ['product_variant_id' => $this->other->id, 'qty' => 2]]]));
        $this->post('/sales/orders', $this->payload(['external_number' => 'ORD-3', 'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]]]));
        [$first, $second, $draft] = SalesOrder::orderBy('id')->with('items')->get()->all();

        $this->get('/sales/orders')->assertOk()->assertSee('Tandai Sedang Diambil');

        $this->from('/sales/orders')->post('/sales/orders/pick', ['orders' => [$first->id, $second->id, $draft->id]])
            ->assertRedirect('/sales/orders')
            ->assertSessionHas('success', fn ($message) => str_contains($message, '2 pesanan ditandai Sedang Diambil') && str_contains($message, $draft->number));

        $this->assertSame('picking', $first->fresh()->status);
        $this->assertSame('picking', $second->fresh()->status);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertNotNull($first->fresh()->picking_at);

        // Tab dan badge memakai nama Sedang Diambil; tautan lama ?status=picked diarahkan ke status baru.
        $this->get('/sales/orders?status=picking')->assertOk()->assertSee('Sedang Diambil')->assertDontSee('Sudah Diambil')->assertSee($first->number);
        $this->get('/sales/orders?status=picked&warehouse='.$this->warehouse->id)->assertRedirect('/sales/orders?status=picking&warehouse='.$this->warehouse->id);

        // Pesanan pertama ambil 5 dari bin A (terbanyak); bin A tinggal 1 bebas, jadi pesanan kedua ambil 4 dari bin B.
        $picks = fn (SalesOrder $order) => $order->items()->with('picks')->get()->flatMap->picks
            ->map(fn ($pick) => [$pick->warehouse_location_id, $pick->qty])->sort()->values()->all();
        $this->assertSame([[$this->binA->id, 5]], $picks($first));
        $this->assertEqualsCanonicalizing([[null, 2], [$this->binB->id, 4]], $picks($second));
    }

    public function test_bulk_pick_reports_shortage_and_marketplace_cancel_without_blocking_others(): void
    {
        $this->post('/sales/orders', $this->payload(['action' => 'confirm', 'items' => [['product_variant_id' => $this->variant->id, 'qty' => 3]]]));
        $this->post('/sales/orders', $this->payload(['external_number' => 'ORD-2', 'action' => 'confirm', 'items' => [['product_variant_id' => $this->other->id, 'qty' => 2]]]));
        $this->post('/sales/orders', $this->payload(['external_number' => 'ORD-3', 'action' => 'confirm', 'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]]]));
        [$ok, $short, $cancelling] = SalesOrder::orderBy('id')->get()->all();

        // KAOS-L hilang dari gudang setelah dikonfirmasi: stok bebas tidak cukup untuk picking.
        Stock::where('product_variant_id', $this->other->id)->update(['qty' => 1]);

        $shop = ShopeeShop::create(['environment' => 'test', 'shop_id' => 555, 'shop_name' => 'Toko Uji', 'warehouse_id' => $this->warehouse->id, 'is_active' => true, 'status' => 'connected']);
        $cancelling->forceFill(['shopee_shop_id' => $shop->id, 'shopee_order_status' => 'IN_CANCEL'])->save();
        $this->assertFalse($cancelling->fresh()->canBulkPick());

        $this->from('/sales/orders')->post('/sales/orders/pick', ['orders' => [$ok->id, $short->id, $cancelling->id]])
            ->assertSessionHas('success')
            ->assertSessionHas('pick_failures', fn ($failures) => array_keys($failures) === [$short->number, $cancelling->number]
                && str_contains($failures[$short->number], 'KAOS-L') && str_contains($failures[$cancelling->number], 'pembatalan'));

        $this->assertSame('picking', $ok->fresh()->status);
        $this->assertSame('confirmed', $short->fresh()->status);
        $this->assertSame('confirmed', $cancelling->fresh()->status);
        $this->assertSame(0, $short->items()->withCount('picks')->get()->sum('picks_count'));

        $this->get('/sales/orders')->assertSee('2 pesanan belum bisa ditandai Sedang Diambil');

        // Semua gagal: pesan error, bukan sukses.
        $this->from('/sales/orders')->post('/sales/orders/pick', ['orders' => [$short->id]])->assertSessionHas('error');
        $this->post('/sales/orders/pick', [])->assertSessionHasErrors('orders');
    }

    /** Pesanan sederhana untuk tes filter daftar; atribut lain lewat $attributes. */
    private function listOrder(string $number, array $attributes = []): SalesOrder
    {
        $order = SalesOrder::create([
            'number' => $number, 'status' => 'confirmed', 'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(), 'customer_name' => 'Pembeli '.$number, 'delivery_type' => 'courier',
            'created_by' => auth()->id(),
        ]);
        $order->forceFill($attributes)->save();

        return $order;
    }

    public function test_completed_tab_holds_shipped_orders_finished_in_marketplace(): void
    {
        $shopee = ShopeeShop::create(['environment' => 'test', 'shop_id' => 778, 'shop_name' => 'Toko Oranye', 'warehouse_id' => $this->warehouse->id, 'status' => 'connected']);
        $tiktok = TiktokShop::create(['shop_id' => '749500001', 'shop_name' => 'Toko Hitam', 'region' => 'ID', 'warehouse_id' => $this->warehouse->id, 'status' => 'connected']);

        $this->listOrder('SO-DONE-SHP', ['status' => 'shipped', 'shopee_shop_id' => $shopee->id, 'shopee_order_status' => 'COMPLETED']);
        $this->listOrder('SO-DONE-TT', ['status' => 'shipped', 'tiktok_shop_id' => $tiktok->id, 'tiktok_order_status' => 'COMPLETED']);
        // Sampai di pembeli (menunggu konfirmasi terima) juga masuk Selesai.
        $this->listOrder('SO-ARR-SHP', ['status' => 'shipped', 'shopee_shop_id' => $shopee->id, 'shopee_order_status' => 'TO_CONFIRM_RECEIVE']);
        $this->listOrder('SO-ARR-TT', ['status' => 'shipped', 'tiktok_shop_id' => $tiktok->id, 'tiktok_order_status' => 'DELIVERED']);
        $this->listOrder('SO-OTW-SHP', ['status' => 'shipped', 'shopee_shop_id' => $shopee->id, 'shopee_order_status' => 'SHIPPED']);
        $this->listOrder('SO-OTW-TT', ['status' => 'shipped', 'tiktok_shop_id' => $tiktok->id, 'tiktok_order_status' => 'IN_TRANSIT']);
        $this->listOrder('SO-NOSTAT', ['status' => 'shipped', 'shopee_shop_id' => $shopee->id]);
        $this->listOrder('SO-MANUAL', ['status' => 'shipped']);
        // Selesai di Shopee tapi gudang belum mengirim: tetap di tabnya (dan muncul di Tidak sinkron).
        $this->listOrder('SO-AHEAD', ['status' => 'packed', 'shopee_shop_id' => $shopee->id, 'shopee_order_status' => 'COMPLETED']);

        $this->get(route('sales.orders.index', ['status' => 'completed']))->assertOk()
            ->assertSee('SO-DONE-SHP')->assertSee('SO-DONE-TT')->assertSee('SO-ARR-SHP')->assertSee('SO-ARR-TT')
            ->assertDontSee('SO-OTW-SHP')->assertDontSee('SO-OTW-TT')->assertDontSee('SO-NOSTAT')->assertDontSee('SO-MANUAL')->assertDontSee('SO-AHEAD');

        $this->get(route('sales.orders.index', ['status' => 'shipped']))->assertOk()
            ->assertSee('SO-OTW-SHP')->assertSee('SO-OTW-TT')->assertSee('SO-NOSTAT')->assertSee('SO-MANUAL')
            ->assertDontSee('SO-DONE-SHP')->assertDontSee('SO-DONE-TT')->assertDontSee('SO-ARR-SHP')->assertDontSee('SO-ARR-TT')->assertDontSee('SO-AHEAD');

        // Jumlah tab: Selesai dipisah dari Dikirim, total Semua tetap.
        $this->get(route('sales.orders.index'))->assertOk()
            ->assertViewHas('counts', fn ($counts) => $counts['completed'] === 4 && $counts['shipped'] === 4 && $counts->sum() === 9);

        $this->get(route('dashboard'))->assertOk()
            ->assertViewHas('orders', fn ($orders) => $orders['counts']['completed'] === 4 && $orders['counts']['shipped'] === 4);
    }

    public function test_order_list_filters_platform_shop_and_pasted_numbers(): void
    {
        $shop = ShopeeShop::create(['environment' => 'test', 'shop_id' => 777, 'shop_name' => 'Toko Oranye', 'warehouse_id' => $this->warehouse->id, 'status' => 'connected']);
        $this->listOrder('SO-A', ['channel_name' => 'Toko Oranye', 'shopee_shop_id' => $shop->id, 'tracking_number' => 'RESI-1']);
        $this->listOrder('SO-B', ['channel_name' => 'Toko Oranye', 'shopee_shop_id' => $shop->id, 'tracking_number' => 'RESI-2']);
        $this->listOrder('SO-C', ['channel_name' => 'Offline', 'tracking_number' => 'RESI-3']);

        $this->get(route('sales.orders.index', ['platform' => 'shopee']))
            ->assertOk()->assertSee('SO-A')->assertSee('SO-B')->assertDontSee('SO-C')
            ->assertSee('Toko Oranye')->assertDontSee('title="Offline"', false);

        $this->get(route('sales.orders.index', ['platform' => 'manual']))
            ->assertOk()->assertSee('SO-C')->assertDontSee('SO-A');

        $this->get(route('sales.orders.index', ['channel' => 'Toko Oranye']))
            ->assertOk()->assertSee('SO-A')->assertDontSee('SO-C');

        // Banyak resi ditempel satu per baris: cocok persis, bukan sebagian.
        $this->get(route('sales.orders.index', ['search_by' => 'tracking', 'search' => "RESI-1\nRESI-3\n"]))
            ->assertOk()->assertSee('SO-A')->assertSee('SO-C')->assertDontSee('SO-B')->assertSee('Mencari 2 nomor sekaligus');

        $this->get(route('sales.orders.index', ['search' => 'SO-A, SO-B']))
            ->assertOk()->assertSee('SO-A')->assertSee('SO-B')->assertDontSee('SO-C');

        $this->get(route('sales.orders.index', ['search_by' => 'customer', 'search' => 'Pembeli SO-C']))
            ->assertOk()->assertSee('SO-C')->assertDontSee('>SO-A<', false);
    }

    public function test_order_list_sorts_by_deadline_and_filters_by_deadline_date(): void
    {
        $this->listOrder('SO-LATER', ['shopee_ship_by' => now()->addDays(2)]);
        $this->listOrder('SO-SOON', ['tiktok_ship_by' => now()->endOfDay()->subMinutes(5)]);
        $this->listOrder('SO-NONE');

        $this->get(route('sales.orders.index', ['sort' => 'deadline']))
            ->assertOk()->assertSeeInOrder(['SO-SOON', 'SO-LATER', 'SO-NONE']);

        $today = now()->toDateString();
        $this->get(route('sales.orders.index', ['date_by' => 'deadline', 'from' => $today, 'to' => $today]))
            ->assertOk()->assertSee('SO-SOON')->assertDontSee('SO-LATER')->assertDontSee('SO-NONE');
    }

    public function test_order_list_per_page_choice(): void
    {
        foreach (range(1, 25) as $i) {
            $this->listOrder(sprintf('SO-P%02d', $i));
        }

        $this->get(route('sales.orders.index'))->assertOk()->assertSee('SO-P25')->assertDontSee('SO-P01')->assertSee('Total 25');
        $this->get(route('sales.orders.index', ['per_page' => 50]))->assertOk()->assertSee('SO-P01');
        // Nilai di luar pilihan kembali ke 20.
        $this->get(route('sales.orders.index', ['per_page' => 5000]))->assertOk()->assertDontSee('SO-P01');

        // Pilihan jumlah per halaman ada di atas daftar (di form filter), bukan di bawah, dan tetap muncul walau data sedikit.
        $this->get(route('sales.orders.index', ['search' => 'SO-P01']))->assertOk()
            ->assertSeeInOrder(['id="order-per-page"', 'name="per_page"', '<table'], false);
        // Kosong = bawaan 20 per halaman.
        $this->get(route('sales.orders.index', ['per_page' => '']))->assertOk()->assertDontSee('SO-P01');
    }

    public function test_saved_order_filters_are_private_and_reusable(): void
    {
        $this->listOrder('SO-SAVE', ['channel_name' => 'Toko Biru']);

        $this->post(route('sales.orders.saved-filters.store'), ['name' => 'Toko Biru urut kirim', 'query' => 'channel=Toko+Biru&sort=deadline&page=3&foo=bar'])
            ->assertRedirect(route('sales.orders.index', ['channel' => 'Toko Biru', 'sort' => 'deadline']));

        $saved = SavedFilter::sole();
        $this->assertSame(['channel' => 'Toko Biru', 'sort' => 'deadline'], $saved->query);

        // Nama sama menimpa, tidak menambah.
        $this->post(route('sales.orders.saved-filters.store'), ['name' => 'Toko Biru urut kirim', 'query' => 'channel=Toko+Biru']);
        $this->assertSame(1, SavedFilter::count());
        $this->assertSame(['channel' => 'Toko Biru'], $saved->fresh()->query);

        // Tanpa filter apa pun tidak disimpan.
        $this->post(route('sales.orders.saved-filters.store'), ['name' => 'Kosong', 'query' => 'page=2'])->assertSessionHasErrors('name');

        $this->get(route('sales.orders.index'))->assertOk()->assertSee('Toko Biru urut kirim');

        // User lain tidak melihat dan tidak bisa menghapusnya.
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('Super Admin');
        $this->actingAs($other)->get(route('sales.orders.index'))->assertOk()->assertDontSee('Toko Biru urut kirim');
        $this->actingAs($other)->delete(route('sales.orders.saved-filters.destroy', $saved))->assertNotFound();

        $this->actingAs(User::where('username', 'superadmin')->first())
            ->delete(route('sales.orders.saved-filters.destroy', $saved))->assertRedirect();
        $this->assertSame(0, SavedFilter::count());
    }

    public function test_order_history_panel_shows_activity(): void
    {
        $order = $this->listOrder('SO-HIST');

        $this->get(route('sales.orders.history', $order))
            ->assertOk()->assertSee('SO-HIST')->assertSee('Buka detail pesanan')->assertSee('Riwayat');

        $this->get(route('sales.orders.index'))->assertOk()->assertSee('title="Riwayat operasi"', false);
    }

    public function test_order_list_shows_created_and_completed_time_instead_of_buyer(): void
    {
        $this->listOrder('SO-OPEN', ['ordered_at' => '2026-10-20 09:15:00', 'customer_name' => 'Rahasia Pembeli']);
        $this->listOrder('SO-DONE', ['status' => 'shipped', 'shipped_at' => '2026-10-21 16:40:00']);

        $this->get(route('sales.orders.index'))->assertOk()
            ->assertSee('20/10/2026 09:15')
            ->assertSee('21/10/2026 16:40')
            ->assertSee('title="Waktu selesai serah terima"', false)
            ->assertDontSee('Rahasia Pembeli');

        // Belum serah terima: waktu selesai tidak muncul.
        $this->get(route('sales.orders.index', ['status' => 'confirmed']))->assertOk()
            ->assertSee('title="Waktu pesanan dibuat"', false)->assertDontSee('title="Waktu selesai serah terima"', false);
    }

    public function test_order_list_filters_by_courier(): void
    {
        $jnt = Courier::create(['code' => 'JNTX', 'name' => 'J&T Uji', 'is_active' => true]);
        $sicepat = Courier::create(['code' => 'SCPX', 'name' => 'SiCepat Uji', 'is_active' => true]);
        $this->listOrder('SO-JNT', ['courier_id' => $jnt->id]);
        $this->listOrder('SO-SCP', ['courier_id' => $sicepat->id]);
        $this->listOrder('SO-NOC');

        $this->get(route('sales.orders.index'))->assertOk()
            ->assertSee('J&amp;T Uji', false)->assertSee('SiCepat Uji')->assertSee('Tanpa kurir');

        $this->get(route('sales.orders.index', ['courier' => $jnt->id]))->assertOk()
            ->assertSee('SO-JNT')->assertDontSee('SO-SCP')->assertDontSee('SO-NOC');

        $this->get(route('sales.orders.index', ['courier' => 'none']))->assertOk()
            ->assertSee('SO-NOC')->assertDontSee('SO-JNT');
    }

    public function test_order_list_filters_by_label_printed(): void
    {
        $shopee = ShopeeShop::create(['environment' => 'test', 'shop_id' => 779, 'shop_name' => 'Toko Resi', 'warehouse_id' => $this->warehouse->id, 'status' => 'connected']);
        $tiktok = TiktokShop::create(['shop_id' => '749500002', 'shop_name' => 'Toko Resi TT', 'region' => 'ID', 'warehouse_id' => $this->warehouse->id, 'status' => 'connected']);

        // Siap dicetak tapi belum dicetak.
        $this->listOrder('SO-SHP-READY', ['shopee_shop_id' => $shopee->id, 'shopee_order_status' => 'PROCESSED']);
        $this->listOrder('SO-TT-READY', ['tiktok_shop_id' => $tiktok->id, 'tiktok_order_status' => 'AWAITING_COLLECTION', 'tiktok_package_id' => 'PKG1']);
        $this->listOrder('SO-MAN-READY', ['tracking_number' => 'JNE1']);
        // Sudah dicetak.
        $this->listOrder('SO-PRINTED', ['shopee_shop_id' => $shopee->id, 'shopee_order_status' => 'PROCESSED', 'shipping_label_printed_at' => now(), 'shipping_label_print_count' => 1]);
        // Label belum bisa dicetak / tidak perlu: bukan "belum cetak resi".
        $this->listOrder('SO-SHP-WAIT', ['shopee_shop_id' => $shopee->id, 'shopee_order_status' => 'READY_TO_SHIP']);
        $this->listOrder('SO-TT-WAIT', ['tiktok_shop_id' => $tiktok->id, 'tiktok_order_status' => 'AWAITING_SHIPMENT', 'tiktok_package_id' => 'PKG2']);
        $this->listOrder('SO-MAN-WAIT');
        $this->listOrder('SO-SELF', ['delivery_type' => 'self_pickup', 'tracking_number' => 'X1']);
        $this->listOrder('SO-GONE', ['status' => 'shipped', 'tracking_number' => 'JNE2']);

        $this->get(route('sales.orders.index', ['label' => 'unprinted']))->assertOk()
            ->assertSee('SO-SHP-READY')->assertSee('SO-TT-READY')->assertSee('SO-MAN-READY')
            ->assertDontSee('SO-PRINTED')->assertDontSee('SO-SHP-WAIT')->assertDontSee('SO-TT-WAIT')->assertDontSee('SO-MAN-WAIT')->assertDontSee('SO-SELF')->assertDontSee('SO-GONE');

        $this->get(route('sales.orders.index', ['label' => 'printed']))->assertOk()
            ->assertSee('SO-PRINTED')->assertDontSee('SO-SHP-READY')->assertDontSee('SO-MAN-READY');

        // Badge per baris di daftar: belum dicetak hanya untuk pesanan yang labelnya siap.
        $page = $this->get(route('sales.orders.index'))->assertOk();
        $this->assertSame(3, substr_count($page->getContent(), 'data-testid="label-unprinted"'));
        $this->assertSame(1, substr_count($page->getContent(), 'data-testid="label-printed"'));
    }
}
