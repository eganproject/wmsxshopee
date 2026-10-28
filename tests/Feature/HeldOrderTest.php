<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\ShopeeSetting;
use App\Models\ShopeeShop;
use App\Models\Stock;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AlertService;
use App\Services\SalesOrderService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pesanan marketplace yang tertahan karena stok kurang: dikonfirmasi ulang
 * otomatis saat stok bertambah, tampil di Notifikasi dengan saran transfer,
 * dan peringatan penyebab stok marketplace tidak sama dengan Optima.
 */
class HeldOrderTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $warehouse;

    private Warehouse $big;

    private ShopeeShop $shop;

    private ProductVariant $kaos;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        ShopeeSetting::create(['environment' => 'test', 'test_partner_id' => 1000001, 'test_partner_key' => 'rahasia-test']);

        $this->warehouse = Warehouse::create(['code' => 'SBY01', 'name' => 'Gudang Surabaya', 'is_active' => true]);
        $this->big = Warehouse::create(['code' => 'GDB', 'name' => 'Gudang Besar', 'is_active' => true]);
        $this->kaos = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => false, 'is_active' => true])
            ->variants()->create(['sku' => 'KAOS', 'is_active' => true]);

        $this->shop = ShopeeShop::create([
            'stock_push' => true,
            'environment' => 'test', 'shop_id' => 555, 'shop_name' => 'Toko Uji', 'warehouse_id' => $this->warehouse->id,
            'status' => 'connected', 'access_token' => 'akses', 'refresh_token' => 'refresh', 'access_token_expires_at' => now()->addHours(3),
        ]);
        $this->shop->mappings()->create(['item_id' => 11, 'model_id' => 0, 'shopee_sku' => 'KAOS', 'product_variant_id' => $this->kaos->id]);
    }

    private function syncOrder(string $sn, int $qty, int $minutesAgo = 0): SalesOrder
    {
        $order = [
            'order_sn' => $sn, 'order_status' => 'READY_TO_SHIP', 'create_time' => now()->subMinutes($minutesAgo)->getTimestamp(),
            'buyer_username' => 'budi', 'recipient_address' => ['name' => 'Budi', 'phone' => '0812', 'full_address' => 'Jl. Merdeka 1'],
            'shipping_carrier' => 'SPX Standard', 'package_list' => [['package_number' => 'PKG-'.$sn]],
            'item_list' => [['item_id' => 11, 'model_id' => 0, 'model_sku' => 'KAOS', 'item_name' => 'Kaos', 'model_name' => '', 'model_quantity_purchased' => $qty, 'model_discounted_price' => 50000]],
        ];

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*/order/get_order_list*' => Http::response(['error' => '', 'response' => ['order_list' => [['order_sn' => $sn]], 'more' => false]]),
            '*/order/get_order_detail*' => Http::response(['error' => '', 'response' => ['order_list' => [$order]]]),
            '*/logistics/get_tracking_number*' => Http::response(['error' => '', 'response' => ['tracking_number' => '']]),
            '*/payment/get_escrow_detail*' => Http::response(['error' => '', 'response' => ['order_income' => []]]),
            '*/product/update_stock*' => Http::response(['error' => '', 'response' => ['success_list' => [], 'failure_list' => []]]),
        ]);
        $this->artisan('shopee:sync --only=orders')->assertSuccessful();

        return SalesOrder::where('external_number', $sn)->firstOrFail();
    }

    private function stockIn(Warehouse $warehouse, int $qty): void
    {
        app(StockService::class)->record(['type' => 'in', 'warehouse_id' => $warehouse->id, 'movement_date' => now()->toDateString()], [
            ['product_variant_id' => $this->kaos->id, 'to_location_id' => null, 'qty' => $qty],
        ]);
    }

    private function admin(): User
    {
        return User::where('username', 'superadmin')->first();
    }

    public function test_held_order_is_confirmed_automatically_when_stock_arrives(): void
    {
        $order = $this->syncOrder('A1', 3);
        $this->assertSame('draft', $order->status);
        $this->assertStringStartsWith(SalesOrder::STOCK_HELD_PREFIX, $order->shopee_sync_error);
        $this->assertSame(1, SalesOrder::stockHeld()->count());

        $this->stockIn($this->warehouse, 2);
        $this->app->terminate();
        $order->refresh();
        $this->assertSame('draft', $order->status, 'stok 2 belum cukup untuk 3');
        $this->assertStringContainsString('tersedia 2', $order->shopee_sync_error);

        $this->stockIn($this->warehouse, 1);
        $this->app->terminate();
        $order->refresh();
        $this->assertSame('confirmed', $order->status);
        $this->assertNull($order->shopee_sync_error);
    }

    public function test_scheduled_retry_confirms_oldest_order_first(): void
    {
        $newer = $this->syncOrder('B2', 2, 5);
        $older = $this->syncOrder('B1', 2, 30);

        // Tambah stok tanpa event (cth. dari proses lain): jadwal tiap menit yang menutupnya.
        Stock::withoutEvents(fn () => Stock::create(['product_variant_id' => $this->kaos->id, 'warehouse_id' => $this->warehouse->id, 'qty' => 2]));

        $this->artisan('orders:retry-held')->expectsOutputToContain('1 dari 2')->assertSuccessful();

        $this->assertSame('confirmed', $older->refresh()->status);
        $this->assertSame('draft', $newer->refresh()->status);
    }

    public function test_manual_draft_and_unmapped_orders_are_not_retried(): void
    {
        $manual = SalesOrder::create(['number' => SalesOrder::nextNumber(), 'status' => 'draft', 'warehouse_id' => $this->warehouse->id, 'order_date' => now()->toDateString(), 'customer_name' => 'Ani']);
        $manual->items()->create(['product_variant_id' => $this->kaos->id, 'qty' => 1, 'price' => 1]);

        $this->stockIn($this->warehouse, 5);
        $this->app->terminate();

        $this->assertSame('draft', $manual->refresh()->status);
        $this->assertSame(0, SalesOrder::stockHeld()->count());
    }

    public function test_alert_lists_held_order_with_transfer_from_warehouse_that_has_stock(): void
    {
        $order = $this->syncOrder('C1', 3);
        $this->stockIn($this->big, 10);
        $this->app->terminate();
        $this->assertSame('draft', $order->refresh()->status, 'gudang lain bukan cadangan: tidak dihitung');

        $group = app(AlertService::class)->groups($this->admin())['held_orders'];
        $this->assertSame(1, $group['count']);
        $short = $group['items']->first()->shortages->first();
        $this->assertSame(3, $short->short);
        $this->assertSame('GDB', $short->sources->first()->warehouse->code);
        $this->assertSame('GDB', $group['items']->first()->transferFrom->code);

        $this->actingAs($this->admin());
        $this->get('/notifications')->assertOk()->assertSee('Pesanan tertahan stok')->assertSee('kurang 3')->assertSee('Transfer dari GDB');

        $page = $this->get('/inventory/transfers/create?held='.$order->id)->assertOk()->assertSee($order->number);
        $prefill = $page->viewData('prefill');
        $this->assertSame($this->big->id, $page->viewData('from'));
        $this->assertSame($this->warehouse->id, $page->viewData('to'));
        $this->assertSame(3, $prefill[0]['qty']);
        $this->assertStringContainsString($order->number, $prefill[0]['notes']);
    }

    public function test_transfer_from_supply_warehouse_releases_held_order_when_sent(): void
    {
        $this->warehouse->update(['supply_warehouse_id' => $this->big->id, 'supply_stock_percent' => 0]);
        $order = $this->syncOrder('D1', 3);
        $this->stockIn($this->big, 10);
        $this->app->terminate();
        $this->assertSame('draft', $order->refresh()->status, 'persen cadangan 0: belum dihitung');

        $this->actingAs($this->admin());
        $this->post('/inventory/transfers', [
            'from_warehouse_id' => $this->big->id, 'to_warehouse_id' => $this->warehouse->id, 'transfer_date' => now()->toDateString(),
            'items' => [['product_variant_id' => $this->kaos->id, 'qty' => 3]],
        ])->assertSessionHasNoErrors();
        $this->app->terminate();

        $this->assertSame('confirmed', $order->refresh()->status);
    }

    public function test_stock_push_off_and_bundle_like_mappings_are_warned(): void
    {
        $this->shop->update(['stock_push' => false]);
        $this->shop->mappings()->create(['item_id' => 22, 'model_id' => 0, 'shopee_sku' => 'BUND32', 'product_variant_id' => $this->kaos->id]);

        $problems = app(AlertService::class)->marketplaceIssues()->pluck('problem');
        $this->assertTrue($problems->contains(fn ($p) => str_starts_with($p, 'Kirim stok mati')));
        $this->assertTrue($problems->contains(fn ($p) => str_contains($p, '1 SKU mirip paket')));

        $this->actingAs($this->admin());
        $this->get("/shopee/shops/{$this->shop->id}/mappings")->assertOk()->assertSee('Mirip paket')->assertSee('terlihat seperti paket');
        $filtered = $this->get("/shopee/shops/{$this->shop->id}/mappings?status=bundle_like")->assertOk()->viewData('mappings');
        $this->assertSame(['BUND32'], $filtered->pluck('shopee_sku')->all());

        $this->shop->update(['stock_push' => true]);
        $this->shop->mappings()->where('shopee_sku', 'BUND32')->update(['product_variant_id' => null]);
        $problems = app(AlertService::class)->marketplaceIssues()->pluck('problem');
        $this->assertFalse($problems->contains(fn ($p) => str_starts_with($p, 'Kirim stok mati') || str_contains($p, 'mirip paket')));
    }

    public function test_cancelling_a_confirmed_order_releases_stock_to_held_order(): void
    {
        $this->stockIn($this->warehouse, 2);
        $first = $this->syncOrder('E1', 2, 30);
        $held = $this->syncOrder('E2', 2, 5);
        $this->assertSame('confirmed', $first->status);
        $this->assertSame('draft', $held->status);

        app(SalesOrderService::class)->cancel($first->refresh(), 'Batal');
        $this->app->terminate();

        $this->assertSame('confirmed', $held->refresh()->status);
    }
}
