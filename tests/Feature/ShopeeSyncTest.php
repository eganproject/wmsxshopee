<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\Courier;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\ShopeeSetting;
use App\Models\ShopeeShop;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AlertService;
use App\Services\MarketplaceOrderRelink;
use App\Services\SalesOrderService;
use App\Services\Shopee\ShopeeStockSync;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class ShopeeSyncTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $warehouse;

    private ShopeeShop $shop;

    private ProductVariant $kaosM;

    private ProductVariant $kaosL;

    private Bundle $bundle;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        ShopeeSetting::create(['environment' => 'test', 'test_partner_id' => 1000001, 'test_partner_key' => 'rahasia-test']);

        $this->warehouse = Warehouse::create(['code' => 'JKT01', 'name' => 'Gudang Jakarta', 'is_active' => true]);
        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => true, 'is_active' => true]);
        $this->kaosM = $product->variants()->create(['sku' => 'KAOS-M', 'name' => 'M', 'is_active' => true]);
        $this->kaosL = $product->variants()->create(['sku' => 'KAOS-L', 'name' => 'L', 'is_active' => true]);
        $this->bundle = Bundle::create(['sku' => 'PAKET-2', 'name' => 'Paket M+L', 'price' => 1, 'is_active' => true]);
        $this->bundle->items()->createMany([
            ['product_variant_id' => $this->kaosM->id, 'qty' => 1],
            ['product_variant_id' => $this->kaosL->id, 'qty' => 1],
        ]);

        app(StockService::class)->record(['type' => 'in', 'warehouse_id' => $this->warehouse->id, 'movement_date' => now()->toDateString()], [
            ['product_variant_id' => $this->kaosM->id, 'to_location_id' => null, 'qty' => 10],
            ['product_variant_id' => $this->kaosL->id, 'to_location_id' => null, 'qty' => 4],
        ]);

        $this->shop = ShopeeShop::create([
            'environment' => 'test', 'shop_id' => 555, 'shop_name' => 'Toko Uji', 'warehouse_id' => $this->warehouse->id,
            'status' => 'connected', 'access_token' => 'akses', 'refresh_token' => 'refresh', 'access_token_expires_at' => now()->addHours(3),
        ]);
        $this->shop->mappings()->createMany([
            ['item_id' => 11, 'model_id' => 101, 'shopee_sku' => 'KAOS-M', 'product_variant_id' => $this->kaosM->id],
            ['item_id' => 11, 'model_id' => 102, 'shopee_sku' => 'KAOS-L', 'product_variant_id' => $this->kaosL->id],
            ['item_id' => 22, 'model_id' => 0, 'shopee_sku' => 'PAKET-2', 'bundle_id' => $this->bundle->id],
        ]);
    }

    /** @return array<string, mixed> */
    private function order(string $sn, string $status, array $items, array $extra = []): array
    {
        return [
            'order_sn' => $sn, 'order_status' => $status, 'create_time' => now()->getTimestamp(),
            'buyer_username' => 'budi', 'recipient_address' => ['name' => 'Budi', 'phone' => '0812', 'full_address' => 'Jl. Merdeka 1, Bandung'],
            'shipping_carrier' => 'SPX Standard', 'package_list' => [['package_number' => 'PKG-'.$sn]],
            'item_list' => $items, ...$extra,
        ];
    }

    private function line(int $itemId, int $modelId, int $qty, string $sku = ''): array
    {
        return ['item_id' => $itemId, 'model_id' => $modelId, 'model_sku' => $sku, 'item_name' => 'Kaos', 'model_name' => '', 'model_quantity_purchased' => $qty, 'model_discounted_price' => 75000];
    }

    /** @param list<array<string, mixed>> $orders */
    private function fakeOrders(array $orders, array $more = []): void
    {
        Http::fake([
            '*/order/get_order_list*' => Http::response(['error' => '', 'response' => ['order_list' => array_map(fn ($o) => ['order_sn' => $o['order_sn']], $orders), 'more' => false]]),
            '*/order/get_order_detail*' => Http::response(['error' => '', 'response' => ['order_list' => $orders]]),
            '*/logistics/get_tracking_number*' => Http::response(['error' => '', 'response' => ['tracking_number' => 'SPXID001']]),
            ...$more,
            '*/payment/get_escrow_detail*' => Http::response(['error' => '', 'response' => ['order_income' => $this->income(129000)]]),
        ]);
    }

    /** @return array<string, mixed> */
    private function income(int $escrow): array
    {
        return [
            'escrow_amount' => $escrow, 'original_price' => 150000, 'seller_discount' => 10000, 'voucher_from_seller' => 5000,
            'shopee_discount' => 3000, 'voucher_from_shopee' => 2000, 'commission_fee' => 6750, 'service_fee' => 4500,
            'seller_transaction_fee' => 2750, 'buyer_paid_shipping_fee' => 12000, 'actual_shipping_fee' => 12000, 'shopee_shipping_rebate' => 0,
            'items' => [['item_id' => 11, 'item_name' => 'Kaos Polos', 'model_sku' => 'KAOS-M', 'model_name' => 'M', 'quantity_purchased' => 2,
                'original_price' => 150000, 'discounted_price' => 140000, 'discount_from_voucher_seller' => 5000]],
        ];
    }

    private function available(ProductVariant $variant): int
    {
        return app(SalesOrderService::class)->availability($this->warehouse->id, [$variant->id])[$variant->id]['available'];
    }

    public function test_ready_to_ship_orders_are_created_and_reserve_stock(): void
    {
        $this->fakeOrders([
            $this->order('A1', 'READY_TO_SHIP', [$this->line(11, 101, 2), $this->line(22, 0, 1)]),
            $this->order('A2', 'UNPAID', [$this->line(11, 101, 1)]),
            $this->order('A3', 'COMPLETED', [$this->line(11, 101, 1)]),
        ]);

        $this->artisan('shopee:sync --only=orders')->assertSuccessful();

        $order = SalesOrder::where('external_number', 'A1')->firstOrFail();
        $this->assertSame(['confirmed', $this->shop->id, 'READY_TO_SHIP', 'Shopee · Toko Uji', 'Budi'], [$order->status, $order->shopee_shop_id, $order->shopee_order_status, $order->channel_name, $order->customer_name]);
        $this->assertSame(Courier::where('code', 'SPX')->value('id'), $order->courier_id);
        $this->assertCount(1, $order->bundles);
        $this->assertSame(10 - 3, $this->available($this->kaosM));
        $this->assertSame(4 - 1, $this->available($this->kaosL));
        $this->assertNull($order->shopee_package_number, 'Pesanan yang tidak dipecah tidak menyimpan package_number.');
        $this->assertSame(1, SalesOrder::count(), 'Pesanan belum bayar / sudah selesai tidak dibuat.');
        $this->assertNotNull($this->shop->fresh()->orders_synced_until);

        // Sinkron ulang tidak menggandakan pesanan.
        $this->artisan('shopee:sync --only=orders')->assertSuccessful();
        $this->assertSame(1, SalesOrder::count());
    }

    public function test_split_order_keeps_its_package_number(): void
    {
        $this->fakeOrders([
            $this->order('A9', 'READY_TO_SHIP', [$this->line(11, 101, 1)], ['split_up' => true, 'package_list' => [['package_number' => 'PKG-A9-1'], ['package_number' => 'PKG-A9-2']]]),
        ]);

        $this->artisan('shopee:sync --only=orders')->assertSuccessful();

        $this->assertSame('PKG-A9-1', SalesOrder::where('external_number', 'A9')->value('shopee_package_number'));
    }

    public function test_income_breakdown_is_stored_and_finalised_when_completed(): void
    {
        $this->actingAs(User::where('username', 'superadmin')->first());
        $this->fakeOrders([$this->order('I1', 'READY_TO_SHIP', [$this->line(11, 101, 2)])]);
        $this->artisan('shopee:sync --only=orders')->assertSuccessful();

        $order = SalesOrder::where('external_number', 'I1')->firstOrFail();
        $this->assertSame('129000.00', $order->shopee_escrow_amount);
        $this->assertFalse($order->shopee_income_final);
        $this->assertSame(6750, $order->shopee_income['commission_fee']);

        $this->get("/sales/orders/{$order->id}")->assertOk()
            ->assertSee('Rincian Pendapatan Shopee')->assertSee('Biaya administrasi')->assertSee('Voucher toko')
            ->assertSee('Rp 129.000')->assertSee('Perkiraan')->assertSee('Voucher Shopee');

        // Sinkron berikutnya tidak memanggil escrow lagi sampai pesanan Selesai.
        $this->artisan('shopee:sync --only=orders')->assertSuccessful();
        Http::assertSentCount(5);

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*/order/get_order_list*' => Http::response(['error' => '', 'response' => ['order_list' => [['order_sn' => 'I1']], 'more' => false]]),
            '*/order/get_order_detail*' => Http::response(['error' => '', 'response' => ['order_list' => [$this->order('I1', 'COMPLETED', [$this->line(11, 101, 2)])]]]),
            '*/payment/get_escrow_detail*' => Http::response(['error' => '', 'response' => ['order_income' => $this->income(127500)]]),
            '*/logistics/get_tracking_number*' => Http::response(['error' => '', 'response' => ['tracking_number' => 'SPXID002']]),
        ]);
        $this->artisan('shopee:sync --only=orders')->assertSuccessful();

        $order->refresh();
        $this->assertTrue($order->shopee_income_final);
        $this->assertSame('127500.00', $order->shopee_escrow_amount);
        $this->get("/sales/orders/{$order->id}")->assertSee('Final')->assertDontSee('Perkiraan');
    }

    public function test_income_is_only_visible_with_permission(): void
    {
        $this->fakeOrders([$this->order('J1', 'READY_TO_SHIP', [$this->line(11, 101, 1)])]);
        $this->artisan('shopee:sync --only=orders')->assertSuccessful();
        $order = SalesOrder::where('external_number', 'J1')->firstOrFail();

        $staff = User::factory()->create(['username' => 'admin2', 'is_active' => true]);
        $staff->assignRole('Admin');
        $this->actingAs($staff);

        $this->get("/sales/orders/{$order->id}")->assertOk()->assertDontSee('Rincian Pendapatan Shopee')->assertDontSee('Rp 129.000');
        $header = $this->xlsxHeader('/sales/orders?export=xlsx');
        $this->assertNotContains('Dana Diterima Shopee', $header);
        $this->assertArrayNotHasKey('shopee_escrow_amount', $order->toArray());

        $staff->givePermissionTo('sales.income');
        $this->actingAs($staff->fresh());
        $this->get("/sales/orders/{$order->id}")->assertSee('Rincian Pendapatan Shopee')->assertSee('Rp 129.000');
        $this->assertContains('Dana Diterima Shopee', $this->xlsxHeader('/sales/orders?export=xlsx'));
    }

    /** @return list<mixed> */
    private function xlsxHeader(string $url): array
    {
        $reader = new Reader;
        $reader->open($this->get($url)->assertOk()->getFile()->getPathname());
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $header = $row->toArray();
                break 2;
            }
        }
        $reader->close();

        return $header ?? [];
    }

    public function test_unmapped_sku_keeps_order_as_draft_until_mapped(): void
    {
        $this->fakeOrders([$this->order('B1', 'READY_TO_SHIP', [$this->line(33, 301, 1, 'TOPI-X')])]);

        $this->artisan('shopee:sync --only=orders')->assertSuccessful();

        $order = SalesOrder::where('external_number', 'B1')->firstOrFail();
        $this->assertSame('draft', $order->status);
        $this->assertStringContainsString('TOPI-X', $order->shopee_sync_error);
        $mapping = $this->shop->mappings()->where('item_id', 33)->firstOrFail();
        $this->assertFalse($mapping->isMapped(), 'Item baru didaftarkan ke tabel pemetaan.');

        $mapping->update(['product_variant_id' => $this->kaosL->id]);
        $this->artisan('shopee:sync --only=orders')->assertSuccessful();

        $order->refresh();
        $this->assertSame('confirmed', $order->status);
        $this->assertNull($order->shopee_sync_error);
        $this->assertSame(1, SalesOrder::count());
    }

    public function test_unmapped_order_is_linked_when_product_with_same_sku_is_added(): void
    {
        $this->fakeOrders([$this->order('B2', 'READY_TO_SHIP', [$this->line(33, 301, 2, 'TOPI-X'), $this->line(11, 101, 1)])]);
        $this->artisan('shopee:sync --only=orders')->assertSuccessful();

        $order = SalesOrder::where('external_number', 'B2')->firstOrFail();
        $this->assertSame('draft', $order->status);
        $this->assertSame(['TOPI-X'], $order->unmapped_skus);

        // Produk dengan SKU yang sama dibuat di Optima: pesanan terhubung di akhir request.
        $topi = Product::create(['sku' => 'TOPI', 'name' => 'Topi', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => false, 'is_active' => true])
            ->variants()->create(['sku' => 'TOPI-X', 'name' => 'Topi', 'is_active' => true]);
        app(StockService::class)->record(['type' => 'in', 'warehouse_id' => $this->warehouse->id, 'movement_date' => now()->toDateString()], [
            ['product_variant_id' => $topi->id, 'to_location_id' => null, 'qty' => 5],
        ]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->app->terminate();

        $order->refresh();
        $this->assertSame('confirmed', $order->status);
        $this->assertNull($order->shopee_sync_error);
        $this->assertSame($topi->id, $this->shop->mappings()->where('item_id', 33)->value('product_variant_id'));
        $this->assertSame(2, (int) $order->items->firstWhere('product_variant_id', $topi->id)->qty);
        $this->assertSame(1, SalesOrder::count());
    }

    public function test_new_sku_or_mapping_schedules_relink_but_unrelated_edits_do_not(): void
    {
        $relink = $this->mock(MarketplaceOrderRelink::class);
        $relink->shouldReceive('queue')->times(3);

        $this->kaosM->update(['name' => 'M (ganti nama)']); // tanpa ganti SKU: tidak dijadwalkan
        $this->kaosM->update(['sku' => 'KAOS-M2']);
        Bundle::create(['sku' => 'PAKET-3', 'name' => 'Paket 3', 'price' => 1, 'is_active' => true]);
        $this->shop->mappings()->create(['item_id' => 44, 'model_id' => 0, 'shopee_sku' => 'X']); // belum dipetakan
        $this->shop->mappings()->where('item_id', 44)->first()->update(['product_variant_id' => $this->kaosL->id]);
    }

    public function test_resync_button_links_order_after_mapping(): void
    {
        $this->actingAs(User::where('username', 'superadmin')->first());
        $this->fakeOrders([$this->order('B3', 'READY_TO_SHIP', [$this->line(33, 301, 1, 'TOPI-Y')])]);
        $this->artisan('shopee:sync --only=orders')->assertSuccessful();

        $order = SalesOrder::where('external_number', 'B3')->firstOrFail();
        $this->get("/sales/orders/{$order->id}")->assertOk()->assertSee('Sinkronkan ulang')->assertSee('Buka Pemetaan SKU');

        $this->post("/sales/orders/{$order->id}/resync")->assertSessionHas('error', fn ($m) => str_contains($m, 'masih draft') && str_contains($m, 'TOPI-Y'));

        $this->shop->mappings()->where('item_id', 33)->firstOrFail()->updateQuietly(['product_variant_id' => $this->kaosL->id]);
        $this->post("/sales/orders/{$order->id}/resync")->assertSessionHas('success');

        $this->assertSame('confirmed', $order->refresh()->status);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'get_order_detail') && str_contains($r['order_sn_list'], 'B3'));
    }

    public function test_resync_falls_back_to_saved_items_when_shopee_is_unreachable(): void
    {
        $this->actingAs(User::where('username', 'superadmin')->first());
        $this->fakeOrders([$this->order('B4', 'READY_TO_SHIP', [$this->line(33, 301, 1, 'TOPI-Z')])]);
        $this->artisan('shopee:sync --only=orders')->assertSuccessful();
        $order = SalesOrder::where('external_number', 'B4')->firstOrFail();

        $this->shop->mappings()->where('item_id', 33)->firstOrFail()->updateQuietly(['product_variant_id' => $this->kaosM->id]);
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['error' => 'error_server', 'message' => 'Sedang gangguan'], 500)]);

        $this->post("/sales/orders/{$order->id}/resync")->assertSessionHas('success');
        $this->assertSame('confirmed', $order->refresh()->status);
    }

    public function test_manually_edited_draft_is_not_relinked_automatically(): void
    {
        $this->fakeOrders([$this->order('B5', 'READY_TO_SHIP', [$this->line(33, 301, 1, 'TOPI-W')])]);
        $this->artisan('shopee:sync --only=orders')->assertSuccessful();
        $order = SalesOrder::where('external_number', 'B5')->firstOrFail();
        $this->assertNotNull($order->unmapped_skus);

        $order->update(['unmapped_skus' => null]); // seperti setelah disimpan dari form Edit
        $this->shop->mappings()->where('item_id', 33)->firstOrFail()->update(['product_variant_id' => $this->kaosL->id]);
        $this->app->terminate();

        $this->assertSame('draft', $order->refresh()->status);
    }

    public function test_short_stock_keeps_draft_with_reason(): void
    {
        $this->fakeOrders([$this->order('C1', 'READY_TO_SHIP', [$this->line(11, 102, 9)])]);

        $this->artisan('shopee:sync --only=orders')->assertSuccessful();

        $order = SalesOrder::where('external_number', 'C1')->firstOrFail();
        $this->assertSame('draft', $order->status);
        $this->assertStringContainsString('KAOS-L tidak cukup', $order->shopee_sync_error);
    }

    public function test_cancelled_in_shopee_releases_reservation_and_tracking_is_fetched(): void
    {
        $this->fakeOrders([$this->order('D1', 'READY_TO_SHIP', [$this->line(11, 101, 2)]), $this->order('D2', 'READY_TO_SHIP', [$this->line(11, 101, 1)])]);
        $this->artisan('shopee:sync --only=orders')->assertSuccessful();
        $this->assertSame(7, $this->available($this->kaosM));

        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->fakeOrders([
            $this->order('D1', 'CANCELLED', [$this->line(11, 101, 2)], ['cancel_reason' => 'BUYER_REQUEST']),
            $this->order('D2', 'PROCESSED', [$this->line(11, 101, 1)]),
        ]);
        $this->artisan('shopee:sync --only=orders')->assertSuccessful();

        $d1 = SalesOrder::where('external_number', 'D1')->first();
        $this->assertSame('cancelled', $d1->status);
        $this->assertStringContainsString('BUYER_REQUEST', $d1->cancel_reason);
        $this->assertSame(9, $this->available($this->kaosM));
        $this->assertSame('SPXID001', SalesOrder::where('external_number', 'D2')->value('tracking_number'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'get_tracking_number') && $r['order_sn'] === 'D2' && ! isset($r['package_number']));
    }

    private function resync(array $orders): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->fakeOrders($orders);
        $this->artisan('shopee:sync --only=orders')->assertSuccessful();
    }

    public function test_shipped_in_shopee_ships_order_automatically_and_takes_stock_out(): void
    {
        $this->fakeOrders([$this->order('E1', 'READY_TO_SHIP', [$this->line(11, 101, 2)])]);
        $this->artisan('shopee:sync --only=orders')->assertSuccessful();
        $this->assertSame(8, $this->available($this->kaosM));

        $this->resync([$this->order('E1', 'SHIPPED', [$this->line(11, 101, 2)])]);

        $order = SalesOrder::where('external_number', 'E1')->firstOrFail();
        $this->assertSame('shipped', $order->status);
        $this->assertTrue($order->shipped_automatically);
        $this->assertNotNull($order->shopee_shipped_at);
        $this->assertNotNull($order->stock_movement_id);
        $this->assertNull($order->shopee_sync_error);
        $this->assertSame(8, $this->available($this->kaosM));
        $this->assertSame(8, (int) Stock::where('product_variant_id', $this->kaosM->id)->sum('qty'), 'Stok fisik berkurang 2.');

        // Sinkron berikutnya (Selesai) tidak mengirim dua kali.
        $this->resync([$this->order('E1', 'COMPLETED', [$this->line(11, 101, 2)])]);
        $this->assertSame(1, StockMovement::where('reference', $order->number)->count());
        $this->assertSame('COMPLETED', $order->fresh()->shopee_order_status);
    }

    public function test_handed_over_order_is_flagged_until_courier_scan_reaches_shopee(): void
    {
        $this->fakeOrders([$this->order('F1', 'PROCESSED', [$this->line(11, 101, 1)])]);
        $this->artisan('shopee:sync --only=orders')->assertSuccessful();

        $order = SalesOrder::where('external_number', 'F1')->firstOrFail();
        $service = app(SalesOrderService::class);
        $service->pick($order, $service->suggestPicks($order));
        $service->pack($order);
        $service->ship($order, [], null);
        SalesOrder::whereKey($order->id)->update(['shipped_at' => now()->subHours(30)]);

        $order->refresh();
        $this->assertTrue($order->awaitingCourierScan());
        $this->assertTrue($order->courierScanOverdue());
        $this->assertSame([$order->id], SalesOrder::shopeeFilter('courier_overdue')->pluck('id')->all());

        $admin = User::where('email', 'superadmin@wms.local')->firstOrFail();
        $this->actingAs($admin)->get(route('sales.orders.index', ['shopee' => 'courier_pending']))
            ->assertOk()->assertSee('Belum discan kurir')->assertSee($order->number);
        $this->assertSame(1, app(AlertService::class)->groups($admin)['courier_pending']['count']);

        $this->resync([$this->order('F1', 'SHIPPED', [$this->line(11, 101, 1)])]);

        $order->refresh();
        $this->assertFalse($order->awaitingCourierScan());
        $this->assertNotNull($order->shopee_shipped_at);
        $this->assertFalse($order->shipped_automatically);
        $this->actingAs($admin)->get(route('sales.orders.index'))->assertOk()->assertSee('Diterima kurir')->assertSee('Shopee: Dikirim');
    }

    public function test_shipped_in_shopee_without_stock_is_flagged(): void
    {
        $this->fakeOrders([$this->order('G1', 'READY_TO_SHIP', [$this->line(11, 102, 9)])]);
        $this->artisan('shopee:sync --only=orders')->assertSuccessful();
        $this->assertSame('draft', SalesOrder::where('external_number', 'G1')->value('status'));

        $this->resync([$this->order('G1', 'SHIPPED', [$this->line(11, 102, 9)])]);

        $order = SalesOrder::where('external_number', 'G1')->firstOrFail();
        $this->assertSame('draft', $order->status);
        $this->assertTrue($order->shopeeAhead());
        $this->assertStringContainsString('Sudah dikirim di Shopee', $order->shopee_sync_error);
        $this->assertSame([$order->id], SalesOrder::shopeeFilter('shopee_ahead')->pluck('id')->all());
    }

    public function test_stock_push_sends_available_stock_including_bundles(): void
    {
        // Pesanan terkonfirmasi mencadangkan 3 KAOS-M.
        $this->fakeOrders([$this->order('E1', 'READY_TO_SHIP', [$this->line(11, 101, 3)])], [
            '*/product/update_stock*' => Http::response(['error' => '', 'response' => ['success_list' => [], 'failure_list' => []]]),
        ]);

        $this->artisan('shopee:sync --only=orders --only=stock')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'update_stock') && $r['item_id'] === 11
            && $r['stock_list'] === [
                ['model_id' => 101, 'seller_stock' => [['stock' => 7]]],
                ['model_id' => 102, 'seller_stock' => [['stock' => 4]]],
            ]);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'update_stock') && $r['item_id'] === 22
            && $r['stock_list'] === [['model_id' => 0, 'seller_stock' => [['stock' => 4]]]]);
        $this->assertNotNull($this->shop->fresh()->stock_pushed_at);
        $this->assertSame([7, 4, 4], $this->shop->mappings()->orderBy('id')->pluck('stock_pushed')->all());
        $this->assertSame(0, $this->shop->mappings()->whereNotNull('stock_error')->count());
    }

    public function test_stock_push_records_rejected_models_per_sku(): void
    {
        Http::fake([
            '*/product/update_stock*' => Http::sequence()
                ->push(['error' => '', 'response' => ['success_list' => [['model_id' => 101]], 'failure_list' => [['model_id' => 102, 'failed_reason' => 'Model sudah dihapus']]]])
                ->push(['error' => 'error_auth', 'message' => 'Item dibekukan'], 200),
        ]);

        app(ShopeeStockSync::class)->push($this->shop);

        $rows = $this->shop->mappings()->get()->keyBy('model_id');
        $this->assertSame(10, $rows[101]->stock_pushed);
        $this->assertNull($rows[101]->stock_error);
        $this->assertNull($rows[102]->stock_pushed);
        $this->assertSame('Model sudah dihapus', $rows[102]->stock_error);
        $this->assertNotNull($rows[0]->stock_error);
    }

    public function test_product_status_page_lists_linked_and_unlinked_skus(): void
    {
        $this->actingAs(User::where('username', 'superadmin')->first());
        $kaosXl = $this->kaosM->product->variants()->create(['sku' => 'KAOS-XL', 'name' => 'XL', 'is_active' => true]);
        $this->shop->mappings()->where('model_id', 102)->update(['stock_error' => 'Model sudah dihapus']);
        $this->shop->mappings()->where('model_id', 101)->update(['stock_pushed' => 7, 'stock_pushed_at' => now()]);

        $this->get('/shopee/products')->assertRedirect(route('marketplace.products', ['platform' => 'shopee']));
        $this->get('/marketplace/products')->assertOk()
            ->assertSee('KAOS-M')->assertSee('KAOS-XL')->assertSee('Toko Uji')
            ->assertSeeText('Stok dikirim 7')->assertSee('Model sudah dihapus');

        $this->get('/marketplace/products?status=unlinked')->assertOk()->assertSee('KAOS-XL');
        $unlinked = $this->get('/marketplace/products?status=unlinked')->viewData('rows')->pluck('sku')->all();
        $this->assertSame(['KAOS-XL'], $unlinked);
        $this->assertSame(['KAOS-L', 'KAOS-M'], $this->get('/marketplace/products?status=linked')->viewData('rows')->pluck('sku')->all());
        $this->assertSame(['KAOS-L'], $this->get('/marketplace/products?status=error')->viewData('rows')->pluck('sku')->all());
        $this->assertSame(['PAKET-2'], $this->get('/marketplace/products?type=bundle&status=linked')->viewData('rows')->pluck('sku')->all());

        // Toko lain belum punya pemetaan apa pun: semua SKU belum terhubung di toko itu.
        $other = ShopeeShop::create(['environment' => 'test', 'shop_id' => 777, 'shop_name' => 'Toko Dua', 'warehouse_id' => $this->warehouse->id, 'status' => 'connected']);
        $this->assertSame(['KAOS-L', 'KAOS-M', 'KAOS-XL'], $this->get("/marketplace/products?shop=shopee-{$other->id}&status=unlinked")->viewData('rows')->pluck('sku')->all());
        $this->assertNotNull($kaosXl);

        $this->get('/marketplace/products?export=xlsx')->assertOk()->assertDownload();
    }

    public function test_return_is_pulled_as_draft_for_shipped_order(): void
    {
        $order = SalesOrder::create([
            'number' => 'SO2610-900', 'status' => 'shipped', 'warehouse_id' => $this->warehouse->id, 'order_date' => now()->toDateString(),
            'channel_name' => 'Shopee · Toko Uji', 'external_number' => 'F1', 'shopee_shop_id' => $this->shop->id, 'customer_name' => 'Budi',
        ]);
        app(SalesOrderService::class)->fillLines($order, [['product_variant_id' => $this->kaosM->id, 'qty' => 2, 'price' => 1, 'notes' => null]], [['bundle_id' => $this->bundle->id, 'qty' => 1, 'price' => 1, 'notes' => null]]);

        Http::fake(['*/returns/get_return_list*' => Http::response(['error' => '', 'response' => ['more' => false, 'return' => [
            ['return_sn' => 'R1', 'order_sn' => 'F1', 'status' => 'REQUESTED', 'reason' => 'ITEM_DAMAGED', 'create_time' => now()->getTimestamp(), 'tracking_number' => 'RTN01', 'user' => ['username' => 'budi'],
                'item' => [['item_id' => 11, 'model_id' => 101, 'amount' => 1], ['item_id' => 22, 'model_id' => 0, 'amount' => 1]]],
            ['return_sn' => 'R2', 'order_sn' => 'F1', 'status' => 'REQUESTED', 'needs_logistics' => false, 'item' => [['item_id' => 11, 'model_id' => 101, 'amount' => 1]]],
            ['return_sn' => 'R3', 'order_sn' => 'TIDAK-ADA', 'status' => 'REQUESTED', 'item' => [['item_id' => 11, 'model_id' => 101, 'amount' => 1]]],
        ]]])]);

        $this->artisan('shopee:sync --only=returns')->assertSuccessful();

        $return = SalesReturn::with('items')->sole();
        $this->assertSame(['draft', $order->id, 'R1', 'RTN01', 'REQUESTED'], [$return->status, $return->sales_order_id, $return->external_return_number, $return->tracking_number, $return->shopee_return_status]);
        $this->assertSame([[$this->kaosM->id, 1, 'damaged'], [$this->kaosM->id, 1, 'damaged'], [$this->kaosL->id, 1, 'damaged']],
            $return->items->sortBy('id')->map(fn ($i) => [$i->product_variant_id, $i->qty_good, $i->reason])->values()->all());

        // Retur yang belum selesai diperbarui lewat get_return_detail (status lama ikut berubah).
        $this->travel(2)->hours();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*/returns/get_return_list*' => Http::response(['error' => '', 'response' => ['more' => false, 'return' => []]])]);
        Http::fake(['*/returns/get_return_detail*' => Http::response(['error' => '', 'response' => ['return_sn' => 'R1', 'status' => 'CANCELLED', 'tracking_number' => 'RTN01']])]);
        $this->artisan('shopee:sync --only=returns')->assertSuccessful();
        $this->assertSame(1, SalesReturn::count());
        $this->assertSame('CANCELLED', $return->fresh()->shopee_return_status);
    }

    public function test_webhook_verifies_signature_and_syncs_order(): void
    {
        $this->fakeOrders([$this->order('G1', 'READY_TO_SHIP', [$this->line(11, 101, 1)])]);
        $body = json_encode(['shop_id' => 555, 'code' => 3, 'timestamp' => now()->getTimestamp(), 'data' => ['ordersn' => 'G1', 'status' => 'READY_TO_SHIP']]);
        $url = route('shopee.webhook');

        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'palsu'], $body)->assertOk();
        $this->assertSame(0, SalesOrder::count());

        $signature = hash_hmac('sha256', $url.'|'.$body, 'rahasia-test');
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => $signature], $body)->assertOk();
        $this->assertSame('confirmed', SalesOrder::where('external_number', 'G1')->value('status'));

        $tracking = json_encode(['shop_id' => 555, 'code' => 4, 'data' => ['ordersn' => 'G1', 'tracking_no' => 'JNT123', 'package_number' => 'PKG-G1']]);
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => hash_hmac('sha256', $url.'|'.$tracking, 'rahasia-test')], $tracking)->assertOk();
        $this->assertSame('JNT123', SalesOrder::where('external_number', 'G1')->value('tracking_number'));
    }

    public function test_deauthorization_push_marks_shop_after_confirming(): void
    {
        Http::fake(['*/api/v2/shop/get_shop_info*' => Http::response(['error' => 'error_auth', 'message' => 'Invalid access_token'])]);
        $url = route('shopee.webhook');
        $body = json_encode(['code' => 2, 'timestamp' => now()->getTimestamp(), 'data' => ['shop_id' => 555, 'partner_id' => 1000001]]);

        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => hash_hmac('sha256', $url.'|'.$body, 'rahasia-test')], $body)->assertOk();

        $this->assertSame('expired', $this->shop->fresh()->status);
        $this->assertStringContainsString('dicabut', $this->shop->fresh()->last_error);
    }

    public function test_stock_push_splits_items_with_many_models(): void
    {
        $this->shop->mappings()->delete();
        foreach (range(1, 60) as $i) {
            $this->shop->mappings()->create(['item_id' => 99, 'model_id' => 1000 + $i, 'shopee_sku' => 'X'.$i, 'product_variant_id' => $this->kaosM->id]);
        }
        Http::fake(['*/product/update_stock*' => Http::response(['error' => '', 'response' => []])]);

        $this->artisan('shopee:sync --only=stock')->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => count($r['stock_list']) === 50);
        Http::assertSent(fn ($r) => count($r['stock_list']) === 10);
    }

    public function test_sync_button_and_order_page(): void
    {
        $this->actingAs(User::where('username', 'superadmin')->first());
        $this->fakeOrders([$this->order('H1', 'READY_TO_SHIP', [$this->line(11, 101, 1)])], [
            '*/returns/get_return_list*' => Http::response(['error' => '', 'response' => ['more' => false, 'return' => []]]),
            '*/product/update_stock*' => Http::response(['error' => '', 'response' => []]),
        ]);

        $this->post("/shopee/shops/{$this->shop->id}/sync")->assertSessionHas('success');

        $order = SalesOrder::where('external_number', 'H1')->firstOrFail();
        $this->get("/sales/orders/{$order->id}")->assertOk()->assertSee('Siap Dikirim');
        $this->get('/marketplace/shops')->assertOk()->assertSee('Sinkron');
    }

    public function test_sync_products_button_pulls_catalog_matches_sku_and_pushes_stock(): void
    {
        $this->actingAs(User::where('username', 'superadmin')->first());
        Http::fake([
            '*/product/get_item_list*' => Http::response(['error' => '', 'response' => ['item' => [['item_id' => 11], ['item_id' => 33]], 'has_next_page' => false]]),
            '*/product/get_item_base_info*' => Http::response(['error' => '', 'response' => ['item_list' => [
                ['item_id' => 11, 'item_name' => 'Kaos Polos', 'has_model' => true],
                ['item_id' => 33, 'item_name' => 'Kaos L Baru', 'item_sku' => 'kaos-l', 'has_model' => false],
            ]]]),
            '*/product/get_model_list*' => Http::response(['error' => '', 'response' => [
                'tier_variation' => [], 'model' => [['model_id' => 101, 'model_sku' => 'KAOS-M', 'model_name' => 'M'], ['model_id' => 102, 'model_sku' => 'KAOS-L', 'model_name' => 'L']],
            ]]),
            '*/product/update_stock*' => Http::response(['error' => '', 'response' => []]),
        ]);

        $this->post('/marketplace/sync-products')->assertSessionHas('success');

        $this->assertSame($this->kaosL->id, $this->shop->mappings()->where('item_id', 33)->value('product_variant_id'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'update_stock') && $r['item_id'] === 33);
        $this->get('/marketplace/shops')->assertOk()->assertSee('Sinkron Produk');
        $this->get('/marketplace/products')->assertOk()->assertSee('Sinkron Produk');
    }

    public function test_sync_products_command_skips_stock_for_shop_without_warehouse(): void
    {
        $this->shop->update(['warehouse_id' => null]);
        Http::fake([
            '*/product/get_item_list*' => Http::response(['error' => '', 'response' => ['item' => [], 'has_next_page' => false]]),
        ]);

        $this->artisan('marketplace:sync-products')->expectsOutputToContain('gudang pengirim belum dipilih')->assertSuccessful();
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'update_stock'));
    }

    public function test_topbar_shops_menu_lists_shops_and_syncs_products_orders_and_stock(): void
    {
        $this->actingAs(User::where('username', 'superadmin')->first());
        $this->fakeOrders([$this->order('T1', 'READY_TO_SHIP', [$this->line(11, 101, 1)])], [
            '*/product/get_item_list*' => Http::response(['error' => '', 'response' => ['item' => [['item_id' => 11]], 'has_next_page' => false]]),
            '*/product/get_item_base_info*' => Http::response(['error' => '', 'response' => ['item_list' => [['item_id' => 11, 'item_name' => 'Kaos Polos', 'has_model' => true]]]]),
            '*/product/get_model_list*' => Http::response(['error' => '', 'response' => [
                'tier_variation' => [], 'model' => [['model_id' => 101, 'model_sku' => 'KAOS-M', 'model_name' => 'M'], ['model_id' => 102, 'model_sku' => 'KAOS-L', 'model_name' => 'L']],
            ]]),
            '*/returns/get_return_list*' => Http::response(['error' => '', 'response' => ['more' => false, 'return' => []]]),
            '*/product/update_stock*' => Http::response(['error' => '', 'response' => []]),
        ]);

        $this->get('/dashboard')->assertOk()->assertSee('Toko Terhubung')->assertSee('Toko Uji')->assertSee('Sinkron Semua');

        $this->post("/marketplace/shops/shopee/{$this->shop->id}/sync-all")->assertSessionHas('success');
        $this->assertDatabaseHas('sales_orders', ['external_number' => 'T1']);
        $this->assertNotNull($this->shop->fresh()->last_synced_at);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'get_item_list'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'update_stock'));

        $this->post('/marketplace/sync-all')->assertSessionHas('success');
    }

    public function test_topbar_shop_sync_requires_sync_permission(): void
    {
        $user = User::where('username', 'superadmin')->first();
        $this->actingAs($user);
        $this->shop->update(['is_active' => false]);

        $this->post('/marketplace/sync-all')->assertSessionHas('error');
        $this->post('/marketplace/shops/shopee/999/sync-all')->assertNotFound();
        $this->post('/marketplace/shops/lazada/1/sync-all')->assertNotFound();
    }
}
