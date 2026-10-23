<?php

namespace Tests\Feature;

use App\Jobs\RefreshMarketplaceCatalog;
use App\Jobs\SyncMarketplaceShop;
use App\Models\MarketplaceSyncLog;
use App\Models\MarketplaceWebhookEvent;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopeeSetting;
use App\Models\ShopeeShop;
use App\Models\TiktokSetting;
use App\Models\TiktokShop;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AlertService;
use App\Services\Shopee\ShopeeException;
use App\Services\Shopee\ShopeeShopApi;
use App\Services\Shopee\ShopeeSync;
use App\Services\StockService;
use App\Services\Tiktok\TiktokAuth;
use App\Services\Tiktok\TiktokClient;
use App\Services\Tiktok\TiktokException;
use App\Services\Tiktok\TiktokShopApi;
use App\Services\Tiktok\TiktokSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Prioritas 2 koneksi marketplace: coba ulang, riwayat sinkron, peringatan & jenis push baru. */
class MarketplaceReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $warehouse;

    private ShopeeShop $shop;

    private ProductVariant $kaos;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        ShopeeSetting::create(['environment' => 'test', 'test_partner_id' => 1000001, 'test_partner_key' => 'rahasia-test']);

        $this->warehouse = Warehouse::create(['code' => 'JKT01', 'name' => 'Gudang Jakarta', 'is_active' => true]);
        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => true, 'is_active' => true]);
        $this->kaos = $product->variants()->create(['sku' => 'KAOS-M', 'name' => 'M', 'is_active' => true]);
        app(StockService::class)->record(['type' => 'in', 'warehouse_id' => $this->warehouse->id, 'movement_date' => now()->toDateString()], [
            ['product_variant_id' => $this->kaos->id, 'to_location_id' => null, 'qty' => 10],
        ]);

        $this->shop = ShopeeShop::create([
            'environment' => 'test', 'shop_id' => 555, 'shop_name' => 'Toko Uji', 'warehouse_id' => $this->warehouse->id, 'is_active' => true,
            'status' => 'connected', 'access_token' => 'akses', 'refresh_token' => 'refresh', 'access_token_expires_at' => now()->addHours(3),
            'connected_at' => now(),
        ]);
        $this->shop->mappings()->create(['item_id' => 11, 'model_id' => 101, 'shopee_sku' => 'KAOS-M', 'product_variant_id' => $this->kaos->id]);
    }

    private function tiktokShop(): TiktokShop
    {
        TiktokSetting::create(['app_key' => 'appkey123', 'app_secret' => 'rahasia', 'service_id' => '7300000000000000001']);

        return TiktokShop::create([
            'shop_id' => '7495000000000000001', 'shop_cipher' => 'ROW_cipher1', 'shop_name' => 'Optima TikTok', 'region' => 'ID', 'is_active' => true,
            'open_id' => 'open-1', 'warehouse_id' => $this->warehouse->id, 'tiktok_warehouse_id' => '7000000000000000009',
            'status' => 'connected', 'access_token' => 'akses', 'refresh_token' => 'refresh', 'access_token_expires_at' => now()->addDays(5),
        ]);
    }

    private function emptyOrders(): void
    {
        Http::fake([
            '*/order/get_order_list*' => Http::response(['error' => '', 'response' => ['order_list' => [], 'more' => false]]),
        ]);
    }

    public function test_shopee_retries_busy_and_server_errors_but_not_failed_posts(): void
    {
        Http::fake([
            '*/product/get_item_list*' => Http::sequence()
                ->push(['error' => 'error_too_many_request', 'message' => 'Terlalu banyak'])
                ->push('', 503)
                ->push(['error' => '', 'response' => ['item' => []]]),
            '*/logistics/ship_order*' => Http::response('', 502),
        ]);
        $api = ShopeeShopApi::for($this->shop);

        $this->assertSame([], $api->get('/api/v2/product/get_item_list')['response']['item']);
        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);

        try {
            $api->post('/api/v2/logistics/ship_order', ['order_sn' => 'X']);
            $this->fail('POST yang gagal di server tidak boleh diulang.');
        } catch (ShopeeException $e) {
            $this->assertSame(502, $e->httpStatus);
        }
        Http::assertSentCount(4);
    }

    public function test_shopee_refreshes_rejected_access_token_once_and_retries(): void
    {
        Http::fake([
            '*/auth/access_token/get*' => Http::response(['error' => '', 'access_token' => 'baru', 'refresh_token' => 'refresh-2', 'expire_in' => 14400]),
            '*/product/get_item_list*' => function (Request $request) {
                return str_contains($request->url(), 'access_token=baru')
                    ? Http::response(['error' => '', 'response' => ['item' => [['item_id' => 1]]]])
                    : Http::response(['error' => 'invalid_acceess_token', 'message' => 'Invalid access_token']);
            },
        ]);

        $this->assertCount(1, ShopeeShopApi::for($this->shop)->get('/api/v2/product/get_item_list')['response']['item']);
        $this->assertSame(['baru', 'refresh-2', 'connected'], [$this->shop->fresh()->access_token, $this->shop->fresh()->refresh_token, $this->shop->fresh()->status]);
    }

    public function test_tiktok_retries_rate_limit_and_refreshes_expired_token(): void
    {
        $shop = $this->tiktokShop();
        Http::fake([
            '*/api/v2/token/refresh*' => Http::response(['code' => 0, 'data' => ['access_token' => 'baru', 'refresh_token' => 'r2', 'access_token_expire_in' => now()->addDays(7)->getTimestamp(), 'refresh_token_expire_in' => now()->addDays(60)->getTimestamp()]]),
            '*/logistics/202309/warehouses*' => function (Request $request) {
                static $calls = 0;
                $calls++;

                return match (true) {
                    $calls === 1 => Http::response(['code' => 36009004, 'message' => 'Too many requests'], 429),
                    $request->header('x-tts-access-token')[0] !== 'baru' => Http::response(['code' => 105002, 'message' => 'Access token is expired']),
                    default => Http::response(['code' => 0, 'data' => ['warehouses' => [['id' => '1']]]]),
                };
            },
        ]);

        $this->assertSame('1', TiktokShopApi::for($shop)->get('/logistics/warehouses')['warehouses'][0]['id']);
        $this->assertSame(['baru', 'connected'], [$shop->fresh()->access_token, $shop->fresh()->status]);
    }

    public function test_sync_runs_are_logged_and_scheduler_queues_one_job_per_shop(): void
    {
        $this->emptyOrders();
        $this->artisan('shopee:sync --only=orders')->assertSuccessful();

        $log = MarketplaceSyncLog::sole();
        $this->assertSame(['shopee', $this->shop->id, 'orders', 'jadwal', 'success'], [$log->platform, $log->shop_ref, $log->part, $log->trigger, $log->status]);
        $this->assertStringContainsString('0 baru', $log->summary);

        Http::fake(['*/returns/get_return_list*' => Http::response(['error' => 'error_param', 'message' => 'Parameter salah'])]);
        $this->artisan('shopee:sync --only=returns')->assertFailed();
        $this->assertSame('failed', MarketplaceSyncLog::latest('id')->first()->status);
        $this->assertStringContainsString('Parameter salah', MarketplaceSyncLog::latest('id')->first()->error);

        Queue::fake();
        ShopeeShop::create(['environment' => 'live', 'shop_id' => 556, 'shop_name' => 'Toko Dua', 'warehouse_id' => $this->warehouse->id, 'is_active' => true, 'status' => 'connected', 'refresh_token' => 'x']);
        $this->artisan('shopee:sync --only=orders --queue')->assertSuccessful();
        Queue::assertPushed(SyncMarketplaceShop::class, 2);
        Queue::assertPushed(SyncMarketplaceShop::class, fn ($job) => $job->shopId === $this->shop->id && $job->parts === ['orders']);
    }

    public function test_queued_shop_sync_records_failure_without_stopping(): void
    {
        Http::fake(['*/order/get_order_list*' => Http::response(['error' => 'error_param', 'message' => 'Rusak'])]);

        (new SyncMarketplaceShop('shopee', $this->shop->id, ['orders']))->handle(app(ShopeeSync::class), app(TiktokSync::class));

        $this->assertSame('failed', MarketplaceSyncLog::sole()->status);
        $this->assertStringContainsString('Rusak', $this->shop->fresh()->last_error);
    }

    public function test_connection_problems_appear_in_notifications(): void
    {
        $user = User::where('username', 'superadmin')->first();
        $this->shop->update(['last_synced_at' => now()->subHour(), 'authorization_expires_at' => now()->addDays(5)]);
        $this->shop->mappings()->update(['stock_error' => 'Model tidak ditemukan']);
        ShopeeShop::create(['environment' => 'live', 'shop_id' => 556, 'shop_name' => 'Toko Putus', 'warehouse_id' => $this->warehouse->id, 'is_active' => true, 'status' => 'expired', 'last_error' => 'Otorisasi dicabut']);
        MarketplaceWebhookEvent::create(['platform' => 'shopee', 'shop_id' => '555', 'shop_ref' => $this->shop->id, 'event_type' => 3, 'order_id' => 'A', 'payload' => [], 'fingerprint' => sha1('a'), 'status' => 'failed']);

        $problems = app(AlertService::class)->marketplaceIssues()->pluck('problem')->implode(' | ');
        $this->assertStringContainsString('Perlu otorisasi ulang: Otorisasi dicabut', $problems);
        $this->assertStringContainsString('Sinkron pesanan terhenti', $problems);
        $this->assertStringContainsString('Otorisasi berakhir', $problems);
        $this->assertStringContainsString('1 SKU gagal dikirim stoknya', $problems);
        $this->assertStringContainsString('1 push gagal diproses', $problems);

        $this->actingAs($user)->get('/notifications')->assertOk()->assertSee('Koneksi marketplace')->assertSee('Toko Putus');
    }

    public function test_sync_log_page_and_retry_buttons(): void
    {
        $this->actingAs(User::where('username', 'superadmin')->first());
        $this->emptyOrders();
        $this->artisan('shopee:sync --only=orders')->assertSuccessful();
        $this->shop->mappings()->update(['stock_error' => 'Ditolak', 'stock_pushed' => 3]);
        $event = MarketplaceWebhookEvent::create(['platform' => 'shopee', 'shop_id' => '555', 'shop_ref' => $this->shop->id, 'event_type' => 3, 'order_id' => 'Z9', 'payload' => ['data' => ['ordersn' => 'Z9']], 'fingerprint' => sha1('z'), 'status' => 'failed', 'error' => 'Sistem sibuk']);

        $this->get('/marketplace/sync-logs')->assertOk()->assertSee('Riwayat Sinkron')->assertSee('Toko Uji')->assertSee('Terjadwal')->assertSee('Kirim Ulang Stok Gagal');
        $this->get('/marketplace/sync-logs?tab=push&status=failed')->assertOk()->assertSee('Z9')->assertSee('Sistem sibuk')->assertSee('Proses Ulang Push Gagal');

        Http::fake(['*/product/update_stock*' => Http::response(['error' => '', 'response' => []])]);
        $this->post('/marketplace/sync-logs/retry-stock')->assertSessionHas('success');
        $this->assertNull($this->shop->mappings()->value('stock_error'));
        $this->assertSame(10, $this->shop->mappings()->value('stock_pushed'));

        Http::fake([
            '*/order/get_order_detail*' => Http::response(['error' => '', 'response' => ['order_list' => []]]),
            '*/payment/get_escrow_detail*' => Http::response(['error' => '', 'response' => []]),
        ]);
        $this->post('/marketplace/sync-logs/retry-push')->assertSessionHas('success');
        $this->assertSame('processed', $event->fresh()->status);
    }

    public function test_sync_log_retry_needs_sync_permission(): void
    {
        $this->shop->mappings()->update(['stock_error' => 'Ditolak']);
        MarketplaceWebhookEvent::create(['platform' => 'shopee', 'shop_id' => '555', 'shop_ref' => $this->shop->id, 'event_type' => 3, 'order_id' => 'Z9', 'payload' => [], 'fingerprint' => sha1('z'), 'status' => 'failed', 'error' => 'Sistem sibuk']);

        // Hak lihat saja: halaman terbuka, tetapi tombol kirim/proses ulang tidak tampil dan ditolak.
        $role = Role::create(['name' => 'Pemantau'])->givePermissionTo('shopee.view');
        $this->actingAs(User::factory()->create()->assignRole($role));

        $this->get('/marketplace/sync-logs')->assertOk()->assertDontSee('Kirim Ulang Stok Gagal');
        $this->get('/marketplace/sync-logs?tab=push')->assertOk()->assertDontSee('Proses Ulang Push Gagal');
        $this->post('/marketplace/sync-logs/retry-stock')->assertForbidden();
        $this->post('/marketplace/sync-logs/retry-push')->assertForbidden();
        $this->assertSame('failed', MarketplaceWebhookEvent::value('status'));
    }

    public function test_tiktok_product_webhook_queues_catalog_refresh_once(): void
    {
        $shop = $this->tiktokShop();
        Queue::fake([RefreshMarketplaceCatalog::class]);
        $send = function (array $payload) {
            $body = json_encode($payload);

            return $this->call('POST', '/tiktok/webhook', [], [], [], ['HTTP_AUTHORIZATION' => hash_hmac('sha256', 'appkey123'.$body, 'rahasia'), 'CONTENT_TYPE' => 'application/json'], $body);
        };

        $send(['type' => 5, 'shop_id' => $shop->shop_id, 'timestamp' => 1, 'data' => ['product_id' => '1001', 'status' => 'ACTIVATE']])->assertOk();
        $send(['type' => 16, 'shop_id' => $shop->shop_id, 'timestamp' => 2, 'data' => ['product_id' => '1002']])->assertOk();

        $this->assertSame(['processed', 'processed'], MarketplaceWebhookEvent::orderBy('id')->pluck('status')->all());
        Queue::assertPushed(RefreshMarketplaceCatalog::class, fn ($job) => $job->platform === 'tiktok' && $job->shopId === $shop->id);
    }

    public function test_shopee_authorization_expiry_push_refreshes_expiry_dates(): void
    {
        Http::fake(['*/shop/get_shop_info*' => Http::response(['error' => '', 'shop_name' => 'Toko Uji', 'region' => 'ID', 'expire_time' => now()->addDays(6)->getTimestamp()])]);
        $url = route('shopee.webhook');
        $body = json_encode(['code' => 12, 'timestamp' => now()->getTimestamp(), 'data' => ['expire_before' => now()->addDays(6)->getTimestamp()]]);

        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => hash_hmac('sha256', $url.'|'.$body, 'rahasia-test')], $body)->assertOk();

        $this->assertSame('processed', MarketplaceWebhookEvent::sole()->status);
        $this->assertTrue($this->shop->fresh()->authorization_expires_at->between(now()->addDays(5), now()->addDays(7)));
        $this->assertStringContainsString('Otorisasi berakhir', app(AlertService::class)->marketplaceIssues()->pluck('problem')->implode(' '));
    }

    public function test_tiktok_rate_limit_backs_off_and_does_not_require_reauthorization(): void
    {
        $shop = $this->tiktokShop();
        Http::fake(['*/order/202309/orders/search*' => Http::response(['code' => 36009002, 'message' => 'Too many requests', 'request_id' => 'r1'])]);

        try {
            app(TiktokSync::class)->run($shop, ['orders']);
            $this->fail('Batas panggilan yang terus berlanjut harus tetap gagal.');
        } catch (TiktokException $e) {
            $this->assertTrue($e->isRateLimited());
            $this->assertFalse($e->needsReauthorization());
        }

        Http::assertSentCount(4);
        Sleep::assertSequence([Sleep::for(2000)->milliseconds(), Sleep::for(4000)->milliseconds(), Sleep::for(8000)->milliseconds()]);
        $this->assertSame('connected', $shop->fresh()->status);
        $this->assertStringContainsString('36009002', $shop->fresh()->last_error);
        $this->assertTrue((new TiktokException('TikTok: Invalid shop cipher (36004004)', 36004004))->needsReauthorization());
    }

    public function test_tiktok_token_refresh_retries_rate_limit_and_keeps_shop_connected(): void
    {
        $shop = $this->tiktokShop();
        Http::fake(['*/api/v2/token/refresh*' => Http::sequence()
            ->push(['code' => 36009002, 'message' => 'Too many requests'], 429)
            ->push(['code' => 0, 'data' => ['access_token' => 'baru', 'refresh_token' => 'r2', 'access_token_expire_in' => now()->addDays(7)->getTimestamp(), 'refresh_token_expire_in' => now()->addDays(60)->getTimestamp()]])
            ->whenEmpty(Http::response(['code' => 36009002, 'message' => 'Too many requests'], 429)),
        ]);

        $this->assertTrue(app(TiktokAuth::class)->refresh($shop));
        Http::assertSentCount(2);
        $this->assertSame(['baru', 'connected'], [$shop->fresh()->access_token, $shop->fresh()->status]);

        $this->expectException(TiktokException::class);

        try {
            app(TiktokAuth::class)->refresh($shop->fresh());
        } finally {
            $this->assertSame('connected', $shop->fresh()->status);
        }
    }

    public function test_tiktok_paces_back_to_back_calls(): void
    {
        config(['tiktok.min_interval_ms' => 250]);
        TiktokClient::$lastCallAt = null;
        Http::fake(['*/logistics/202309/warehouses*' => Http::response(['code' => 0, 'data' => ['warehouses' => []]])]);
        $api = TiktokShopApi::for($this->tiktokShop());

        $api->get('/logistics/warehouses');
        $api->get('/logistics/warehouses');

        Sleep::assertSleptTimes(1);
    }

    public function test_migration_restores_shops_flagged_only_by_rate_limit(): void
    {
        $limited = $this->tiktokShop();
        $limited->update(['status' => 'expired', 'last_error' => 'TikTok: Too many requests (36009002)']);
        $revoked = TiktokShop::create(['shop_id' => '7495000000000000002', 'shop_name' => 'Dicabut', 'is_active' => true, 'status' => 'expired',
            'refresh_token' => 'x', 'last_error' => 'Otorisasi dicabut dari TikTok Shop Seller Center. Hubungkan ulang toko.']);

        (require database_path('migrations/2026_10_23_090000_restore_rate_limited_tiktok_shops.php'))->up();

        $this->assertSame(['connected', 'expired'], [$limited->fresh()->status, $revoked->fresh()->status]);
    }
}
