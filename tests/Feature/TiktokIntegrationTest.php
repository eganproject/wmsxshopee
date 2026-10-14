<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\Courier;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\TiktokSetting;
use App\Models\TiktokShop;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\FulfillmentScan;
use App\Services\SalesOrderService;
use App\Services\StockService;
use App\Services\Tiktok\TiktokClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
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

    public function test_settings_keep_secret_encrypted_and_when_left_blank(): void
    {
        $this->get('/tiktok/settings')->assertOk()->assertSee('Pengaturan TikTok')->assertSee(route('tiktok.callback'))->assertSee(route('tiktok.webhook'));

        $this->put('/tiktok/settings', ['app_key' => 'key-1', 'app_secret' => 'rahasia-1', 'service_id' => '73'])->assertRedirect(route('tiktok.settings.edit'));
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
                'refresh_token' => 'refresh-1', 'refresh_token_expire_in' => now()->addDays(60)->getTimestamp(),
                'open_id' => 'open-9', 'seller_name' => 'Optima', 'seller_base_region' => 'ID',
            ]]),
            '*/authorization/202309/shops*' => Http::response(['code' => 0, 'message' => 'Success', 'data' => ['shops' => [
                ['id' => '7495000000000000001', 'name' => 'Optima Official', 'region' => 'ID', 'seller_type' => 'LOCAL', 'cipher' => 'ROW_a', 'code' => 'IDLC1'],
                ['id' => '7495000000000000002', 'name' => 'Optima Outlet', 'region' => 'ID', 'seller_type' => 'LOCAL', 'cipher' => 'ROW_b', 'code' => 'IDLC2'],
            ]]]),
        ]);

        $this->get("/tiktok/callback?state={$state}&code=kode123")->assertRedirect(route('tiktok.shops.index'))->assertSessionHas('success');

        $this->assertSame(2, TiktokShop::count());
        $shop = TiktokShop::where('shop_id', '7495000000000000001')->firstOrFail();
        $this->assertSame(['Optima Official', 'ROW_a', 'open-9', $this->warehouse->id, 'connected'], [$shop->shop_name, $shop->shop_cipher, $shop->open_id, $shop->warehouse_id, $shop->status]);
        $this->assertSame('refresh-1', $shop->refresh_token);
        $this->assertTrue($shop->access_token_expires_at->between(now()->addDays(6), now()->addDays(8)));
        $this->assertNotSame('akses-1', DB::table('tiktok_shops')->value('access_token'));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/v2/token/get') && $r['auth_code'] === 'kode123' && $r['grant_type'] === 'authorized_code' && $r['app_key'] === 'appkey123');

        $this->get('/tiktok/shops')->assertOk()->assertSee('Optima Official')->assertSee('Optima Outlet');
    }

    public function test_callback_rejects_wrong_state(): void
    {
        $this->configure();
        $this->post('/tiktok/shops/connect', ['warehouse_id' => $this->warehouse->id]);

        $this->get('/tiktok/callback?state=salah&code=x')->assertRedirect(route('tiktok.shops.index'))->assertSessionHas('error');
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
        $this->assertFalse($order->canArrangeShipment());
        $this->assertFalse($order->hasShippingLabel());

        $this->artisan('tiktok:sync --only=orders')->assertSuccessful();
        $this->assertSame(1, SalesOrder::count());

        $this->get("/sales/orders/{$order->id}")->assertOk()->assertSee('Pesanan TikTok')->assertSee('Perlu Dikirim')
            ->assertSee('Rincian Pembayaran TikTok')->assertSee('Rp 154.000');
        $this->get('/sales/orders')->assertOk()->assertSee('TikTok: Perlu Dikirim');
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
            ->push(['code' => 0, 'data' => ['products' => [['id' => '1002', 'title' => 'Paket', 'skus' => [['id' => '2003', 'seller_sku' => 'PAKET-2']]]], 'next_page_token' => '']]),
        ]);

        $this->post("/tiktok/shops/{$shop->id}/mappings/pull")->assertRedirect()->assertSessionHas('success');

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

    public function test_staff_cannot_manage_tiktok(): void
    {
        $staff = User::factory()->create(['username' => 'staf', 'is_active' => true]);
        $staff->assignRole('Staff Gudang');

        $this->actingAs($staff)->get('/tiktok/shops')->assertForbidden();
        $this->actingAs($staff)->get('/tiktok/settings')->assertForbidden();
    }
}
