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
        $this->assertSame(1, SalesOrder::count(), 'Pesanan belum bayar / sudah selesai tidak dibuat.');
        $this->assertNotNull($this->shop->fresh()->orders_synced_until);

        // Sinkron ulang tidak menggandakan pesanan.
        $this->artisan('shopee:sync --only=orders')->assertSuccessful();
        $this->assertSame(1, SalesOrder::count());
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
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'get_tracking_number') && $r['order_sn'] === 'D2' && $r['package_number'] === 'PKG-D2');
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

        $this->get('/shopee/products')->assertOk()
            ->assertSee('KAOS-M')->assertSee('KAOS-XL')->assertSee('Toko Uji')
            ->assertSeeText('Stok dikirim 7')->assertSee('Model sudah dihapus');

        $this->get('/shopee/products?status=unlinked')->assertOk()->assertSee('KAOS-XL');
        $unlinked = $this->get('/shopee/products?status=unlinked')->viewData('rows')->pluck('sku')->all();
        $this->assertSame(['KAOS-XL'], $unlinked);
        $this->assertSame(['KAOS-L', 'KAOS-M'], $this->get('/shopee/products?status=linked')->viewData('rows')->pluck('sku')->all());
        $this->assertSame(['KAOS-L'], $this->get('/shopee/products?status=error')->viewData('rows')->pluck('sku')->all());
        $this->assertSame(['PAKET-2'], $this->get('/shopee/products?type=bundle&status=linked')->viewData('rows')->pluck('sku')->all());

        // Toko lain belum punya pemetaan apa pun: semua SKU belum terhubung di toko itu.
        $other = ShopeeShop::create(['environment' => 'test', 'shop_id' => 777, 'shop_name' => 'Toko Dua', 'warehouse_id' => $this->warehouse->id, 'status' => 'connected']);
        $this->assertSame(['KAOS-L', 'KAOS-M', 'KAOS-XL'], $this->get("/shopee/products?shop={$other->id}&status=unlinked")->viewData('rows')->pluck('sku')->all());
        $this->assertNotNull($kaosXl);

        $this->get('/shopee/products?export=xlsx')->assertOk()->assertDownload();
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

        $this->artisan('shopee:sync --only=returns')->assertSuccessful();
        $this->assertSame(1, SalesReturn::count());
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
        $this->get('/shopee/shops')->assertOk()->assertSee('Sinkron');
    }
}
