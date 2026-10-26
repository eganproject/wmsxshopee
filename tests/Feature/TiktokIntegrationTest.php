<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\Courier;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\ShopeeShop;
use App\Models\TiktokSetting;
use App\Models\TiktokShop;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\FulfillmentScan;
use App\Services\SalesOrderService;
use App\Services\StockService;
use App\Services\Tiktok\TiktokClient;
use App\Services\Tiktok\TiktokOrderSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Integrasi TikTok Shop: semua panggilan API dipalsukan, tidak ada request ke TikTok. */
class TiktokIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $warehouse;

    private ProductVariant $kaosM;

    private ProductVariant $kaosL;

    private Bundle $bundle;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->actingAs(User::where('username', 'superadmin')->first());

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
    }

    private function configure(): TiktokSetting
    {
        return TiktokSetting::create(['app_key' => 'appkey123', 'app_secret' => 'rahasia', 'service_id' => '7300000000000000001']);
    }

    private function shop(array $attributes = []): TiktokShop
    {
        $this->configure();

        $shop = TiktokShop::create([
            'stock_push' => true,
            'shop_id' => '7495000000000000001', 'shop_cipher' => 'ROW_cipher1', 'shop_name' => 'Optima TikTok', 'region' => 'ID',
            'open_id' => 'open-1', 'warehouse_id' => $this->warehouse->id, 'tiktok_warehouse_id' => '7000000000000000009',
            'status' => 'connected', 'access_token' => 'akses', 'refresh_token' => 'refresh', 'access_token_expires_at' => now()->addDays(5),
            ...$attributes,
        ]);
        $shop->mappings()->createMany([
            ['product_id' => '1001', 'sku_id' => '2001', 'seller_sku' => 'KAOS-M', 'product_variant_id' => $this->kaosM->id],
            ['product_id' => '1001', 'sku_id' => '2002', 'seller_sku' => 'KAOS-L', 'product_variant_id' => $this->kaosL->id],
            ['product_id' => '1002', 'sku_id' => '2003', 'seller_sku' => 'PAKET-2', 'bundle_id' => $this->bundle->id],
        ]);

        return $shop;
    }

    /** @return array<string, mixed> */
    private function order(string $id, string $status, array $lines, array $extra = []): array
    {
        return [
            'id' => $id, 'status' => $status, 'create_time' => now()->getTimestamp(), 'rts_sla_time' => now()->addDays(2)->getTimestamp(),
            'recipient_address' => ['name' => 'Siti', 'phone_number' => '(+62)812****', 'full_address' => 'Jl. Asia Afrika 2, Bandung'],
            'shipping_provider' => 'J&T Express', 'delivery_option_name' => 'Standard', 'fulfillment_type' => 'FULFILLMENT_BY_SELLER',
            'packages' => [['id' => 'PKG'.$id]], 'buyer_message' => 'Bungkus rapi',
            'payment' => ['currency' => 'IDR', 'original_total_product_price' => '160000', 'seller_discount' => '10000', 'platform_discount' => '5000',
                'sub_total' => '145000', 'shipping_fee' => '9000', 'total_amount' => '154000'],
            'line_items' => $lines, ...$extra,
        ];
    }

    /** Satu line_item per unit, seperti API TikTok. */
    private function units(string $skuId, int $qty, string $sellerSku = '', int $price = 75000): array
    {
        return array_fill(0, $qty, ['id' => uniqid(), 'product_id' => '1001', 'sku_id' => $skuId, 'seller_sku' => $sellerSku,
            'product_name' => 'Kaos Polos', 'sku_name' => 'Putih', 'sale_price' => (string) $price, 'original_price' => '80000', 'display_status' => 'AWAITING_SHIPMENT']);
    }

    private function fakeSearch(array $orders, array $more = []): void
    {
        Http::fake([
            '*/order/202309/orders/search*' => Http::response(['code' => 0, 'message' => 'Success', 'data' => ['orders' => $orders, 'next_page_token' => '', 'total_count' => count($orders)]]),
            ...$more,
        ]);
    }

    /** Ganti semua respons palsu (Http::fake berikutnya tidak menimpa yang lama). */
    private function refake(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    private function available(ProductVariant $variant): int
    {
        return app(SalesOrderService::class)->availability($this->warehouse->id, [$variant->id])[$variant->id]['available'];
    }

    public function test_signature_follows_tiktok_202309_rules(): void
    {
        $this->configure();
        $client = TiktokClient::make();
        $body = '{"status":"ACTIVATE"}';

        $expected = hash_hmac('sha256', 'rahasia/product/202309/products/searchapp_keyappkey123page_size100shop_cipherROW_xtimestamp1700000000'.$body.'rahasia', 'rahasia');

        $this->assertSame($expected, $client->sign('/product/202309/products/search', [
            'timestamp' => 1700000000, 'shop_cipher' => 'ROW_x', 'app_key' => 'appkey123', 'page_size' => 100, 'sign' => 'abaikan', 'access_token' => 'abaikan',
        ], $body));
        $this->assertSame('/order/202309/orders/search', $client->versioned('/order/orders/search'));
        $this->assertSame('/product/202309/products/1/inventory/update', $client->versioned('/product/products/1/inventory/update'));
        $this->assertSame('/order/202407/orders/1/price_detail', $client->versioned('/order/202407/orders/1/price_detail'));
    }

    public function test_requests_are_signed_and_carry_token_header_and_cipher(): void
    {
        $shop = $this->shop();
        $this->fakeSearch([]);

        $this->artisan('tiktok:sync --only=orders')->assertSuccessful();

        Http::assertSent(function (Request $request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $path = parse_url($request->url(), PHP_URL_PATH);
            $sign = $query['sign'];
            unset($query['sign']);

            return $path === '/order/202309/orders/search' && $request->method() === 'POST'
                && $request->header('x-tts-access-token')[0] === 'akses'
                && $query['shop_cipher'] === 'ROW_cipher1' && $query['app_key'] === 'appkey123'
                && $sign === TiktokClient::make()->sign($path, $query, $request->body());
        });
        $this->assertNotNull($shop->fresh()->orders_synced_until);
    }

    /** Satu pesanan TikTok yang error tidak menahan pesanan lain; pesanan itu dicoba lagi di sinkron berikutnya. */
    public function test_one_broken_tiktok_order_does_not_stop_the_shop_sync(): void
    {
        $shop = $this->shop();
        $broken = $this->order('BAD1', 'AWAITING_SHIPMENT', $this->units('2001', 1));
        $good = $this->order('OK1', 'AWAITING_SHIPMENT', $this->units('2001', 1));
        $this->fakeSearch([$broken, $good]);
        // Simulasikan error database pada satu pesanan.
        $failing = true;
        SalesOrder::creating(function (SalesOrder $order) use (&$failing) {
            if ($failing && $order->external_number === 'BAD1') {
                throw new \RuntimeException('Simulasi error database');
            }
        });

        $stats = app(TiktokOrderSync::class)->sync($shop);

        $this->assertSame([1, 1], [$stats['created'], $stats['failed']]);
        $this->assertSame('confirmed', SalesOrder::where('external_number', 'OK1')->value('status'));
        $this->assertSame(['BAD1'], Cache::get("tiktok:order-retry:{$shop->id}"));

        $failing = false;
        $this->refake();
        $this->fakeSearch([$good], [
            '*/order/202309/orders?*' => Http::response(['code' => 0, 'data' => ['orders' => [$this->order('BAD1', 'AWAITING_SHIPMENT', $this->units('2001', 1))]]]),
        ]);
        $stats = app(TiktokOrderSync::class)->sync($shop->fresh());

        $this->assertSame(0, $stats['failed']);
        $this->assertSame('confirmed', SalesOrder::where('external_number', 'BAD1')->value('status'));
        $this->assertNull(Cache::get("tiktok:order-retry:{$shop->id}"));
    }

    public function test_tiktok_retry_list_survives_an_api_error_mid_sync(): void
    {
        $shop = $this->shop();
        Cache::forever("tiktok:order-retry:{$shop->id}", ['BAD1']);
        $this->fakeSearch([], [
            '*/order/202309/orders?*' => Http::response(['code' => 36009004, 'message' => 'Layanan sibuk']),
        ]);

        try {
            app(TiktokOrderSync::class)->sync($shop);
            $this->fail('Error API seharusnya diteruskan.');
        } catch (\App\Services\Tiktok\TiktokException) {
        }

        $this->assertSame(['BAD1'], Cache::get("tiktok:order-retry:{$shop->id}"));
    }

    public function test_settings_keep_secret_encrypted_and_when_left_blank(): void
    {
        $this->get('/tiktok/settings')->assertRedirect(route('marketplace.settings', ['tab' => 'tiktok']));
        $this->get('/marketplace/settings?tab=tiktok')->assertOk()->assertSee('Pengaturan Marketplace')->assertSee(route('tiktok.callback'))->assertSee(route('tiktok.webhook'));

        $this->put('/tiktok/settings', ['app_key' => 'key-1', 'app_secret' => 'rahasia-1', 'service_id' => '73'])->assertRedirect(route('marketplace.settings', ['tab' => 'tiktok']));
        $this->assertNotSame('rahasia-1', DB::table('tiktok_settings')->value('app_secret'));
        $this->assertSame('rahasia-1', TiktokSetting::current()->app_secret);

        $this->put('/tiktok/settings', ['app_key' => 'key-2', 'app_secret' => ''])->assertRedirect();
        $this->assertSame(['key-2', 'rahasia-1'], [TiktokSetting::current()->app_key, TiktokSetting::current()->app_secret]);

        TiktokSetting::query()->delete();
        $this->put('/tiktok/settings', ['app_key' => 'key-3'])->assertSessionHasErrors('app_secret');
    }

    public function test_connect_and_callback_store_every_authorized_shop(): void
    {
        $this->configure();

        $redirect = $this->post('/tiktok/shops/connect', ['warehouse_id' => $this->warehouse->id])->headers->get('Location');
        $this->assertStringStartsWith('https://services.tiktokshop.com/open/authorize?', $redirect);
        parse_str(parse_url($redirect, PHP_URL_QUERY), $query);
        $this->assertSame('7300000000000000001', $query['service_id']);
        $state = session('tiktok_auth.state');
        $this->assertSame($state, $query['state']);

        Http::fake([
            'auth.tiktok-shops.com/api/v2/token/get*' => Http::response(['code' => 0, 'message' => 'success', 'data' => [
                'access_token' => 'akses-1', 'access_token_expire_in' => now()->addDays(7)->getTimestamp(),
                'refresh_token' => 'refresh-1', 'refresh_token_expire_in' => 4914729878, // ± 2125, otorisasi Unlimited
                'open_id' => 'open-9', 'seller_name' => 'Optima', 'seller_base_region' => 'ID',
            ]]),
            '*/authorization/202309/shops*' => Http::response(['code' => 0, 'message' => 'Success', 'data' => ['shops' => [
                ['id' => '7495000000000000001', 'name' => 'Optima Official', 'region' => 'ID', 'seller_type' => 'LOCAL', 'cipher' => 'ROW_a', 'code' => 'IDLC1'],
                ['id' => '7495000000000000002', 'name' => 'Optima Outlet', 'region' => 'ID', 'seller_type' => 'LOCAL', 'cipher' => 'ROW_b', 'code' => 'IDLC2'],
            ]]]),
        ]);

        $this->get("/tiktok/callback?state={$state}&code=kode123")->assertRedirect(route('marketplace.shops', ['platform' => 'tiktok']))->assertSessionHas('success');

        $this->assertSame(2, TiktokShop::count());
        $shop = TiktokShop::where('shop_id', '7495000000000000001')->firstOrFail();
        $this->assertSame(['Optima Official', 'ROW_a', 'open-9', $this->warehouse->id, 'connected'], [$shop->shop_name, $shop->shop_cipher, $shop->open_id, $shop->warehouse_id, $shop->status]);
        $this->assertSame('refresh-1', $shop->refresh_token);
        $this->assertTrue($shop->access_token_expires_at->between(now()->addDays(6), now()->addDays(8)));
        $this->assertSame('2037-12-31', $shop->refresh_token_expires_at->toDateString());
        $this->assertNotSame('akses-1', DB::table('tiktok_shops')->value('access_token'));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/v2/token/get') && $r['auth_code'] === 'kode123' && $r['grant_type'] === 'authorized_code' && $r['app_key'] === 'appkey123');

        $this->get('/marketplace/shops?platform=tiktok')->assertOk()->assertSee('Optima Official')->assertSee('Optima Outlet');
    }

    public function test_callback_rejects_wrong_state(): void
    {
        $this->configure();
        $this->post('/tiktok/shops/connect', ['warehouse_id' => $this->warehouse->id]);

        $this->get('/tiktok/callback?state=salah&code=x')->assertRedirect(route('marketplace.shops', ['platform' => 'tiktok']))->assertSessionHas('error');
        $this->assertSame(0, TiktokShop::count());
    }

    public function test_refresh_updates_all_shops_of_the_same_seller_and_marks_revoked(): void
    {
        $shop = $this->shop(['access_token_expires_at' => now()->addHours(2)]);
        $sibling = TiktokShop::create(['shop_id' => '7495000000000000002', 'open_id' => 'open-1', 'status' => 'connected', 'refresh_token' => 'refresh', 'access_token' => 'akses', 'access_token_expires_at' => now()->addHours(2)]);

        Http::fake(['auth.tiktok-shops.com/api/v2/token/refresh*' => Http::response(['code' => 0, 'data' => [
            'access_token' => 'akses-2', 'access_token_expire_in' => now()->addDays(7)->getTimestamp(), 'refresh_token' => 'refresh-2', 'refresh_token_expire_in' => now()->addDays(60)->getTimestamp(),
        ]])]);

        $this->artisan('tiktok:refresh-tokens')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertSame(['akses-2', 'refresh-2'], [$shop->fresh()->access_token, $sibling->fresh()->refresh_token]);

        // Token masih lama: tidak diperbarui.
        $this->artisan('tiktok:refresh-tokens')->assertSuccessful();
        Http::assertSentCount(1);

        $this->refake();
        Http::fake(['auth.tiktok-shops.com/api/v2/token/refresh*' => Http::response(['code' => 105002, 'message' => 'refresh token is expired'])]);
        $this->artisan('tiktok:refresh-tokens --force')->assertFailed();
        $this->assertSame('expired', $shop->fresh()->status);
    }

    public function test_awaiting_shipment_orders_are_created_grouped_per_sku_and_reserve_stock(): void
    {
        $shop = $this->shop();
        $this->fakeSearch([
            $this->order('580001', 'AWAITING_SHIPMENT', [...$this->units('2001', 2), ...$this->units('2003', 1)]),
            $this->order('580002', 'UNPAID', $this->units('2001', 1)),
            $this->order('580003', 'COMPLETED', $this->units('2001', 1)),
            $this->order('580004', 'AWAITING_SHIPMENT', $this->units('2001', 1), ['fulfillment_type' => 'FULFILLMENT_BY_TIKTOK']),
        ]);

        $this->artisan('tiktok:sync --only=orders')->assertSuccessful();

        $order = SalesOrder::where('external_number', '580001')->firstOrFail();
        $this->assertSame(['confirmed', $shop->id, 'AWAITING_SHIPMENT', 'TikTok · Optima TikTok', 'Siti', 'PKG580001'],
            [$order->status, $order->tiktok_shop_id, $order->tiktok_order_status, $order->channel_name, $order->customer_name, $order->tiktok_package_id]);
        $this->assertSame(Courier::where('code', 'JNT')->value('id'), $order->courier_id);
        $this->assertSame(2, (int) $order->items()->whereNull('sales_order_bundle_id')->sum('qty'));
        $this->assertCount(1, $order->bundles);
        $this->assertSame('154000', $order->tiktok_payment['total_amount']);
        $this->assertNotNull($order->tiktok_ship_by);
        $this->assertSame(10 - 3, $this->available($this->kaosM));
        $this->assertSame(1, SalesOrder::count(), 'Belum bayar, sudah selesai & Fulfilled by TikTok tidak dibuat.');
        $this->assertTrue($order->canArrangeShipment(), 'Pengiriman TikTok bisa diatur dari Optima.');
        $this->assertFalse($order->hasShippingLabel());

        $this->artisan('tiktok:sync --only=orders')->assertSuccessful();
        $this->assertSame(1, SalesOrder::count());

        $this->get("/sales/orders/{$order->id}")->assertOk()->assertSee('Pesanan TikTok')->assertSee('Perlu Dikirim')
            ->assertSee('Rincian Pembayaran TikTok')->assertSee('Rp 154.000');
        $this->get('/sales/orders')->assertOk()->assertSee('Perlu Dikirim')->assertSee('AWAITING_SHIPMENT');
    }

    public function test_unmapped_sku_keeps_order_draft_until_mapped(): void
    {
        $shop = $this->shop();
        $this->fakeSearch([$this->order('590001', 'AWAITING_SHIPMENT', $this->units('2999', 1, 'BARU-01'))]);

        $this->artisan('tiktok:sync --only=orders')->assertSuccessful();
        $order = SalesOrder::where('external_number', '590001')->firstOrFail();
        $this->assertSame('draft', $order->status);
        $this->assertStringContainsString('BARU-01', $order->tiktok_sync_error);
        $mapping = $shop->mappings()->where('sku_id', '2999')->firstOrFail();

        $this->put("/tiktok/shops/{$shop->id}/mappings/{$mapping->id}", ['target' => 'variant:'.$this->kaosL->id])->assertRedirect();
        $this->artisan('tiktok:sync --only=orders')->assertSuccessful();

        $order->refresh();
        $this->assertSame('confirmed', $order->status);
        $this->assertNull($order->tiktok_sync_error);
    }

    public function test_unmapped_order_is_linked_right_after_mapping_without_waiting_for_sync(): void
    {
        $shop = $this->shop();
        $this->fakeSearch([$this->order('590002', 'AWAITING_SHIPMENT', [...$this->units('2999', 2, 'BARU-02'), ...$this->units('2001', 1)])]);
        $this->artisan('tiktok:sync --only=orders')->assertSuccessful();

        $order = SalesOrder::where('external_number', '590002')->firstOrFail();
        $this->assertSame('draft', $order->status);
        $this->assertSame(['BARU-02'], $order->unmapped_skus);
        $this->assertCount(1, $order->items, 'SKU yang sudah dipetakan tetap masuk sebagai baris.');

        $mapping = $shop->mappings()->where('sku_id', '2999')->firstOrFail();
        $this->refake(); // tanpa respons API: penghubungan ulang memakai item yang tersimpan
        $this->put("/tiktok/shops/{$shop->id}/mappings/{$mapping->id}", ['target' => 'variant:'.$this->kaosL->id])
            ->assertSessionHas('success', fn ($message) => str_contains($message, '1 pesanan yang tertahan kini terhubung'));

        $order->refresh();
        $this->assertSame('confirmed', $order->status);
        $this->assertNull($order->unmapped_skus);
        $this->assertNull($order->tiktok_sync_error);
        $this->assertSame(2, (int) $order->items->firstWhere('product_variant_id', $this->kaosL->id)->qty);
        $this->assertSame(2, $this->available($this->kaosL));
    }

    public function test_cancelled_and_shipped_statuses_follow_tiktok(): void
    {
        $this->shop();
        $this->fakeSearch([
            $this->order('600001', 'AWAITING_SHIPMENT', $this->units('2001', 2)),
            $this->order('600002', 'AWAITING_SHIPMENT', $this->units('2002', 1)),
        ]);
        $this->artisan('tiktok:sync --only=orders')->assertSuccessful();
        $this->assertSame(10 - 2, $this->available($this->kaosM));

        $this->refake();
        $this->fakeSearch([
            $this->order('600001', 'CANCELLED', $this->units('2001', 2), ['cancel_reason' => 'Pembeli berubah pikiran']),
            $this->order('600002', 'IN_TRANSIT', $this->units('2002', 1), ['tracking_number' => 'JX123']),
        ]);
        $this->artisan('tiktok:sync --only=orders')->assertSuccessful();

        $cancelled = SalesOrder::where('external_number', '600001')->firstOrFail();
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertStringContainsString('Pembeli berubah pikiran', $cancelled->cancel_reason);
        $this->assertSame(10, $this->available($this->kaosM));

        $shipped = SalesOrder::where('external_number', '600002')->firstOrFail();
        $this->assertSame(['shipped', 'JX123', true], [$shipped->status, $shipped->tracking_number, $shipped->shipped_automatically]);
        $this->assertNotNull($shipped->tiktok_shipped_at);
    }

    public function test_stock_push_sends_available_qty_per_product(): void
    {
        $shop = $this->shop();
        Http::fake(['*/inventory/update*' => Http::response(['code' => 0, 'message' => 'Success', 'data' => []])]);

        $this->artisan('tiktok:sync --only=stock')->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/product/202309/products/1001/inventory/update')
            && $r['skus'] == [
                ['id' => '2001', 'inventory' => [['warehouse_id' => '7000000000000000009', 'quantity' => 10]]],
                ['id' => '2002', 'inventory' => [['warehouse_id' => '7000000000000000009', 'quantity' => 4]]],
            ]);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/products/1002/inventory/update') && $r['skus'][0]['inventory'][0]['quantity'] === 4);
        $this->assertSame(10, $shop->mappings()->where('sku_id', '2001')->value('stock_pushed'));

        $this->refake();
        Http::fake(['*/inventory/update*' => Http::response(['code' => 12052900, 'message' => 'product is frozen'])]);
        $this->artisan('tiktok:sync --only=stock')->assertSuccessful();
        $this->assertStringContainsString('product is frozen', $shop->mappings()->where('sku_id', '2001')->value('stock_error'));
        $this->get("/tiktok/shops/{$shop->id}/mappings?status=failed")->assertOk()->assertSee('product is frozen');
    }

    public function test_pull_catalog_and_auto_match_by_seller_sku(): void
    {
        $shop = $this->shop(['tiktok_warehouse_id' => null]);
        $shop->mappings()->delete();

        Http::fake(['*/product/202309/products/search*' => Http::sequence()
            ->push(['code' => 0, 'data' => ['products' => [['id' => '1001', 'title' => 'Kaos Polos', 'skus' => [
                ['id' => '2001', 'seller_sku' => 'kaos-m', 'sales_attributes' => [['name' => 'Ukuran', 'value_name' => 'M']], 'inventory' => [['warehouse_id' => '7000000000000000010', 'quantity' => 3]]],
                ['id' => '2002', 'seller_sku' => 'TIDAK-ADA'],
            ]]], 'next_page_token' => 'hal2']])
            ->push(['code' => 0, 'data' => ['products' => [
                // Produk toko uji sering masih direview (PENDING); tetap ditarik.
                ['id' => '1002', 'title' => 'Paket', 'status' => 'PENDING', 'skus' => [['id' => '2003', 'seller_sku' => 'PAKET-2']]],
                ['id' => '1003', 'title' => 'Dihapus', 'status' => 'DELETED', 'skus' => [['id' => '2009', 'seller_sku' => 'LAMA']]],
            ], 'next_page_token' => '']]),
        ]);

        $this->post("/tiktok/shops/{$shop->id}/mappings/pull")->assertRedirect()
            ->assertSessionHas('success', fn ($message) => str_contains($message, '2 produk') && str_contains($message, '1 belum aktif'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/products/search') && $r['status'] === 'ALL');

        $this->assertSame(3, $shop->mappings()->count());
        $m = $shop->mappings()->where('sku_id', '2001')->first();
        $this->assertSame([$this->kaosM->id, 'auto', 'M'], [$m->product_variant_id, $m->match_type, $m->sku_name]);
        $this->assertSame($this->bundle->id, $shop->mappings()->where('sku_id', '2003')->value('bundle_id'));
        $this->assertNull($shop->mappings()->where('sku_id', '2002')->value('product_variant_id'));
        $this->assertSame('7000000000000000010', $shop->fresh()->tiktok_warehouse_id);

        $this->get("/tiktok/shops/{$shop->id}/mappings")->assertOk()->assertSee('Kaos Polos')->assertSee('TIDAK-ADA');
    }

    public function test_webhook_verifies_signature_and_syncs_the_order(): void
    {
        $shop = $this->shop();
        Http::fake(['*/order/202309/orders?*' => Http::response(['code' => 0, 'data' => ['orders' => [$this->order('610001', 'AWAITING_SHIPMENT', $this->units('2001', 1))]]])]);
        $body = json_encode(['type' => 1, 'shop_id' => $shop->shop_id, 'timestamp' => now()->getTimestamp(), 'data' => ['order_id' => '610001', 'order_status' => 'AWAITING_SHIPMENT']]);

        $this->call('POST', '/tiktok/webhook', [], [], [], ['HTTP_AUTHORIZATION' => 'salah', 'CONTENT_TYPE' => 'application/json'], $body)->assertStatus(401);
        $this->assertSame(0, SalesOrder::count());

        $signature = hash_hmac('sha256', 'appkey123'.$body, 'rahasia');
        $this->call('POST', '/tiktok/webhook', [], [], [], ['HTTP_AUTHORIZATION' => $signature, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
        $this->assertSame('confirmed', SalesOrder::where('external_number', '610001')->value('status'));

        $revoke = json_encode(['type' => 6, 'shop_id' => $shop->shop_id, 'data' => []]);
        $this->call('POST', '/tiktok/webhook', [], [], [], ['HTTP_AUTHORIZATION' => hash_hmac('sha256', 'appkey123'.$revoke, 'rahasia'), 'CONTENT_TYPE' => 'application/json'], $revoke)->assertOk();
        $this->assertSame('expired', $shop->fresh()->status);
    }

    public function test_handover_requires_shipment_arranged_in_tiktok(): void
    {
        $this->shop();
        $this->fakeSearch([$this->order('620001', 'AWAITING_SHIPMENT', $this->units('2001', 1))]);
        $this->artisan('tiktok:sync --only=orders')->assertSuccessful();
        $order = SalesOrder::where('external_number', '620001')->firstOrFail();
        $service = app(SalesOrderService::class);
        $service->pick($order, $service->suggestPicks($order));
        $service->pack($order->fresh());

        $this->expectException(ValidationException::class);
        app(FulfillmentScan::class)->handover($order->number, null);
    }

    private function syncedOrder(string $id, array $extra = []): SalesOrder
    {
        $this->fakeSearch([$this->order($id, 'AWAITING_SHIPMENT', $this->units('2001', 1), $extra)]);
        $this->artisan('tiktok:sync --only=orders')->assertSuccessful();
        $this->refake();

        return SalesOrder::where('external_number', $id)->firstOrFail();
    }

    public function test_arrange_pickup_and_print_label_through_fulfillment_api(): void
    {
        $this->shop();
        $order = $this->syncedOrder('630001');
        $start = now()->addDay()->setTime(9, 0)->getTimestamp();

        Http::fake([
            '*/fulfillment/202309/packages/PKG630001/handover_time_slots*' => Http::response(['code' => 0, 'data' => [
                'can_pickup' => true, 'can_drop_off' => true, 'drop_off_point_url' => 'https://seller.tiktok.com/dropoff',
                'pickup_slots' => [['start_time' => $start, 'end_time' => $start + 7200, 'avaliable' => true], ['start_time' => $start - 7200, 'end_time' => $start, 'avaliable' => false]],
            ]]),
            '*/fulfillment/202309/packages/PKG630001/shipping_documents*' => Http::response(['code' => 0, 'data' => ['doc_url' => 'https://files.tiktok.test/label.pdf', 'tracking_number' => 'JX777']]),
            '*/fulfillment/202309/packages/PKG630001/ship?*' => Http::response(['code' => 0, 'data' => []]),
            '*/fulfillment/202309/packages/PKG630001?*' => Http::response(['code' => 0, 'data' => ['package_id' => 'PKG630001', 'tracking_number' => 'JX777']]),
            '*files.tiktok.test/*' => Http::response('%PDF-1.4 label', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $this->get("/sales/orders/{$order->id}/shipment")->assertOk()->assertSee('Atur di TikTok')->assertSee('Lihat titik drop off terdekat');
        $this->post("/sales/orders/{$order->id}/shipment", ['method' => 'pickup', 'pickup_slot' => $start.'-'.($start + 7200)])
            ->assertRedirect(route('sales.orders.show', $order))->assertSessionHas('success');

        Http::assertSent(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/packages/PKG630001/ship')
            && $r['handover_method'] === 'PICKUP' && $r['pickup_slot'] == ['start_time' => $start, 'end_time' => $start + 7200]);

        $order->refresh();
        $this->assertSame(['AWAITING_COLLECTION', 'pickup', 'JX777'], [$order->tiktok_order_status, $order->shipping_method, $order->tracking_number]);
        $this->assertTrue($order->tiktokShipmentArranged());
        $this->assertFalse($order->canArrangeShipment());
        $this->assertTrue($order->hasShippingLabel());
        $this->get("/sales/orders/{$order->id}/shipping-label")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'shipping_documents') && str_contains($r->url(), 'document_type=SHIPPING_LABEL'));
        $this->assertNotNull($order->fresh()->shipping_label_printed_at);
    }

    public function test_several_tiktok_labels_are_printed_together(): void
    {
        $this->shop();
        $first = $this->syncedOrder('660001');
        $second = $this->syncedOrder('660002');
        SalesOrder::whereKey([$first->id, $second->id])->update(['tiktok_order_status' => 'AWAITING_COLLECTION', 'tracking_number' => 'JX1']);

        Http::fake([
            '*/fulfillment/202309/packages/*/shipping_documents*' => Http::response(['code' => 0, 'data' => ['doc_url' => 'https://files.tiktok.test/label.pdf']]),
            '*files.tiktok.test/*' => Http::response('%PDF-1.4 label', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $page = $this->get('/sales/orders/shipping-labels?'.http_build_query(['orders' => [$first->id, $second->id]]))
            ->assertOk()->assertSee('Cetak Resi Gabungan')->assertDontSee('dicetak per pesanan');
        $parts = $page->viewData('parts');
        $this->assertSame(['tiktok', 'tiktok'], $parts->pluck('type')->all());

        $this->get($parts[1]['url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertNotNull($second->fresh()->shipping_label_printed_at);
    }

    public function test_bulk_dropoff_arranges_tiktok_orders(): void
    {
        $this->shop();
        $order = $this->syncedOrder('640001');

        Http::fake([
            '*/handover_time_slots*' => Http::response(['code' => 0, 'data' => ['can_pickup' => false, 'can_drop_off' => true, 'pickup_slots' => []]]),
            '*/packages/PKG640001/ship*' => Http::response(['code' => 0, 'data' => []]),
            '*/fulfillment/202309/packages/PKG640001?*' => Http::response(['code' => 0, 'data' => ['tracking_number' => '']]),
        ]);

        $this->post('/sales/orders/shipment', ['orders' => [$order->id], 'method' => 'dropoff'])->assertRedirect()->assertSessionHas('success');

        Http::assertSent(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/ship') && $r['handover_method'] === 'DROP_OFF');
        $this->assertSame(['dropoff', 'AWAITING_COLLECTION'], [$order->fresh()->shipping_method, $order->fresh()->tiktok_order_status]);
    }

    public function test_buyer_cancel_request_blocks_shipment_and_handover(): void
    {
        $this->shop();
        $order = $this->syncedOrder('650001', ['is_buyer_request_cancel' => true]);

        $this->assertTrue($order->tiktok_cancel_requested);
        $this->assertStringContainsString('mengajukan pembatalan', $order->tiktok_sync_error);
        $this->assertFalse($order->canArrangeShipment());
    }

    public function test_returns_are_pulled_as_draft_sales_returns_and_status_follows(): void
    {
        $shop = $this->shop();
        $lines = [...$this->units('2001', 2), ...$this->units('2003', 1)];
        $this->fakeSearch([$this->order('660001', 'AWAITING_SHIPMENT', $lines)]);
        $this->artisan('tiktok:sync --only=orders')->assertSuccessful();
        $this->refake();
        $this->fakeSearch([$this->order('660001', 'IN_TRANSIT', $lines, ['tracking_number' => 'JX1'])]);
        $this->artisan('tiktok:sync --only=orders')->assertSuccessful();
        $this->refake();
        $order = SalesOrder::where('external_number', '660001')->firstOrFail();
        $this->assertSame('shipped', $order->status);

        $returns = fn (string $status) => ['code' => 0, 'data' => ['return_orders' => [
            ['return_id' => 'R1', 'order_id' => '660001', 'return_type' => 'RETURN_AND_REFUND', 'return_status' => $status, 'return_reason_text' => 'Barang rusak',
                'return_tracking_number' => 'RET-9', 'create_time' => now()->getTimestamp(),
                'return_line_items' => [['sku_id' => '2001'], ['sku_id' => '2003']]],
            ['return_id' => 'R2', 'order_id' => '660001', 'return_type' => 'REFUND', 'return_status' => $status, 'return_line_items' => [['sku_id' => '2001']]],
            ['return_id' => 'R3', 'order_id' => '999', 'return_type' => 'RETURN_AND_REFUND', 'return_status' => $status, 'return_line_items' => [['sku_id' => '2001']]],
        ], 'next_page_token' => '']];

        Http::fake(['*/return_refund/202309/returns/search*' => Http::response($returns('AWAITING_BUYER_SHIP'))]);
        $this->artisan('tiktok:sync --only=returns')->assertSuccessful();

        $return = SalesReturn::where('external_return_number', 'R1')->firstOrFail();
        $this->assertSame(['draft', $order->id, $shop->id, 'AWAITING_BUYER_SHIP', 'RET-9'], [$return->status, $return->sales_order_id, $return->tiktok_shop_id, $return->tiktok_return_status, $return->tracking_number]);
        $this->assertSame(1 + 2, (int) $return->items()->sum('qty_good'), '1 kaos M + paket dipecah jadi 2 komponen.');
        $this->assertSame('damaged', $return->items()->value('reason'));
        $this->assertSame(1, SalesReturn::count(), 'Refund tanpa barang & pesanan di luar Optima dilewati.');
        $this->assertNotNull($shop->fresh()->returns_synced_until);

        $this->refake();
        Http::fake(['*/return_refund/202309/returns/search*' => Http::response($returns('BUYER_SHIPPED_ITEM'))]);
        $this->artisan('tiktok:sync --only=returns')->assertSuccessful();
        $this->assertSame('BUYER_SHIPPED_ITEM', $return->fresh()->tiktok_return_status);
        $this->get("/sales/returns/{$return->id}")->assertOk()->assertSee('TikTok:');
    }

    public function test_webhook_handles_return_and_expiry_events(): void
    {
        $shop = $this->shop();
        Http::fake(['*/return_refund/202309/returns/search*' => Http::response(['code' => 0, 'data' => ['return_orders' => []]])]);
        $send = function (array $payload) {
            $body = json_encode($payload);

            return $this->call('POST', '/tiktok/webhook', [], [], [], ['HTTP_AUTHORIZATION' => hash_hmac('sha256', 'appkey123'.$body, 'rahasia'), 'CONTENT_TYPE' => 'application/json'], $body);
        };

        $send(['type' => 12, 'shop_id' => $shop->shop_id, 'data' => ['order_id' => '670001', 'return_id' => 'R9']])->assertOk();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/returns/search') && $r['order_ids'] === ['670001']);

        $send(['type' => 7, 'shop_id' => $shop->shop_id, 'data' => []])->assertOk();
        $this->assertStringContainsString('akan segera berakhir', $shop->fresh()->last_error);
        $this->assertSame('connected', $shop->fresh()->status);
    }

    public function test_marketplace_pages_list_both_platforms(): void
    {
        $tiktok = $this->shop();
        $shopee = ShopeeShop::create(['environment' => 'test', 'shop_id' => 555, 'shop_name' => 'Toko Shopee Uji', 'warehouse_id' => $this->warehouse->id, 'status' => 'connected', 'is_active' => true]);
        $shopee->mappings()->create(['item_id' => 11, 'model_id' => 0, 'shopee_sku' => 'KAOS-L', 'product_variant_id' => $this->kaosL->id]);
        $tiktok->mappings()->where('sku_id', '2002')->delete();

        $this->get('/marketplace/shops')->assertOk()->assertSee('Optima TikTok')->assertSee('Toko Shopee Uji')->assertSee(route('tiktok.mappings.index', $tiktok))->assertSee(route('shopee.mappings.index', $shopee));
        $this->assertCount(1, $this->get('/marketplace/shops?platform=shopee')->viewData('shops'));
        $this->assertCount(1, $this->get('/marketplace/shops?search=tiktok')->viewData('shops'));

        $this->get('/marketplace/products')->assertOk()->assertSee('Optima TikTok')->assertSee('Toko Shopee Uji');
        $rows = fn (string $query) => $this->get('/marketplace/products?'.$query)->viewData('rows')->pluck('sku')->all();
        $this->assertSame(['KAOS-L', 'KAOS-M'], $rows('status=linked'));
        $this->assertSame(['KAOS-L'], $rows("status=unlinked&shop=tiktok-{$tiktok->id}"));
        $this->assertSame(['KAOS-M'], $rows("status=unlinked&shop=shopee-{$shopee->id}"));
        $this->assertSame(['KAOS-M'], $rows('platform=tiktok&status=linked'));
        $this->get('/marketplace/products?export=xlsx')->assertOk()->assertDownload();
        $this->get('/marketplace/settings')->assertOk()->assertSee('Partner ID')->assertDontSee('App Secret');
        $this->get('/marketplace/settings?tab=tiktok')->assertOk()->assertSee('App Secret');
    }

    public function test_staff_cannot_manage_tiktok(): void
    {
        $staff = User::factory()->create(['username' => 'staf', 'is_active' => true]);
        $staff->assignRole('Staff Gudang');

        $this->actingAs($staff)->get('/marketplace/shops')->assertForbidden();
        $this->actingAs($staff)->get('/marketplace/settings')->assertForbidden();
        $this->actingAs($staff)->post('/tiktok/shops/connect', ['warehouse_id' => $this->warehouse->id])->assertForbidden();
    }
}
