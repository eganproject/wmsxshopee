<?php

namespace Tests\Feature;

use App\Jobs\SendTokenWebhook;
use App\Models\ShopeeSetting;
use App\Models\ShopeeShop;
use App\Models\TiktokSetting;
use App\Models\TiktokShop;
use App\Services\Shopee\ShopeeAuth;
use App\Services\Shopee\ShopeeClient;
use App\Services\Tiktok\TiktokAuth;
use App\Services\Tiktok\TiktokClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/** Token Shopee & TikTok dikirim ke webhook Optisync setiap diambil / diperbarui. */
class TokenWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://hub.cok-analytics.com/hook/optisynch';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.token_webhook.url' => self::URL]);
    }

    private function webhookCalls(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => $r->url() === self::URL)
            ->map(fn (Request $r) => $r->data())->values()->all();
    }

    public function test_shopee_connect_and_refresh_send_token(): void
    {
        ShopeeSetting::create(['environment' => 'test', 'test_partner_id' => 1000001, 'test_partner_key' => 'rahasia-test']);
        $client = ShopeeClient::make('test');

        Http::fake([
            self::URL => Http::response(['ok' => true]),
            '*/api/v2/auth/token/get*' => Http::response(['error' => '', 'message' => '', 'request_id' => 'req-1', 'access_token' => 'akses-1', 'refresh_token' => 'refresh-1', 'expire_in' => 14400]),
            '*/api/v2/auth/access_token/get*' => Http::response(['error' => '', 'message' => '', 'request_id' => 'req-2', 'access_token' => 'akses-2', 'refresh_token' => 'refresh-2', 'expire_in' => 14000, 'shop_id' => 555, 'partner_id' => 1000001]),
            '*/api/v2/shop/get_shop_info*' => Http::response(['error' => '', 'shop_name' => 'Toko Uji', 'region' => 'ID']),
        ]);

        [$shop] = app(ShopeeAuth::class)->connect($client, 'kode', 555, null, null);

        $this->assertSame([[
            'app' => 'optisynch',
            'platform' => 'shopee',
            'shop_id' => 555,
            'token' => [
                'partner_id' => 1000001, 'refresh_token' => 'refresh-1', 'access_token' => 'akses-1', 'expire_in' => 14400,
                'request_id' => 'req-1', 'error' => '', 'message' => '', 'shop_id' => 555,
            ],
        ]], $this->webhookCalls());

        app(ShopeeAuth::class)->refresh($shop, $client);

        $last = $this->webhookCalls()[1];
        $this->assertSame(['refresh-2', 'akses-2', 14000, 'req-2'], [$last['token']['refresh_token'], $last['token']['access_token'], $last['token']['expire_in'], $last['token']['request_id']]);
    }

    public function test_tiktok_refresh_sends_token_for_every_shop_of_the_seller(): void
    {
        TiktokSetting::create(['app_key' => 'appkey123', 'app_secret' => 'rahasia', 'service_id' => '7300000000000000001']);
        $shop = TiktokShop::create(['shop_id' => '7495000000000000001', 'open_id' => 'open-1', 'status' => 'connected', 'refresh_token' => 'r1', 'access_token' => 'a1', 'access_token_expires_at' => now()->addHour()]);
        TiktokShop::create(['shop_id' => '7495000000000000002', 'open_id' => 'open-1', 'status' => 'connected', 'refresh_token' => 'r1', 'access_token' => 'a1', 'access_token_expires_at' => now()->addHour()]);

        Http::fake([
            self::URL => Http::response(['ok' => true]),
            'auth.tiktok-shops.com/api/v2/token/refresh*' => Http::response(['code' => 0, 'request_id' => 'tt-req', 'data' => [
                'access_token' => 'a2', 'access_token_expire_in' => now()->addDays(7)->getTimestamp(), 'refresh_token' => 'r2', 'refresh_token_expire_in' => now()->addDays(60)->getTimestamp(),
            ]]),
        ]);

        app(TiktokAuth::class)->refresh($shop, TiktokClient::make());

        $calls = $this->webhookCalls();
        $this->assertSame(['7495000000000000001', '7495000000000000002'], array_column($calls, 'shop_id'));
        $token = $calls[0]['token'];
        $this->assertSame(['appkey123', 'r2', 'a2', 'tt-req', '7495000000000000001'], [$token['partner_id'], $token['refresh_token'], $token['access_token'], $token['request_id'], $token['shop_id']]);
        $this->assertEqualsWithDelta(7 * 86400, $token['expire_in'], 5);
    }

    public function test_webhook_failure_never_breaks_token_saving_and_logs_without_tokens(): void
    {
        ShopeeSetting::create(['environment' => 'test', 'test_partner_id' => 1000001, 'test_partner_key' => 'rahasia-test']);
        $shop = ShopeeShop::create(['environment' => 'test', 'shop_id' => 555, 'status' => 'connected', 'access_token' => 'a', 'refresh_token' => 'r', 'access_token_expires_at' => now()->addMinute()]);

        Queue::fake();
        Http::fake(['*/api/v2/auth/access_token/get*' => Http::response(['error' => '', 'access_token' => 'akses-baru', 'refresh_token' => 'refresh-baru', 'expire_in' => 14400])]);

        app(ShopeeAuth::class)->refresh($shop);
        $this->assertSame('refresh-baru', $shop->fresh()->refresh_token);
        Queue::assertPushed(SendTokenWebhook::class, fn (SendTokenWebhook $job) => $job->payload['token']['refresh_token'] === 'refresh-baru');

        // Webhook membalas error: job gagal (diulang antrean), log tanpa token.
        Http::fake([self::URL => Http::response('down', 500)]);
        Log::spy();
        $job = new SendTokenWebhook(['platform' => 'shopee', 'shop_id' => 555, 'token' => ['refresh_token' => 'refresh-baru']]);

        try {
            $job->handle();
            $this->fail('Seharusnya gagal.');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('refresh-baru', $e->getMessage());
            $job->failed($e);
        }

        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx) => ! str_contains(json_encode($ctx), 'refresh-baru'))->once();
    }

    public function test_blank_url_disables_webhook(): void
    {
        config(['services.token_webhook.url' => '']);
        Queue::fake();
        ShopeeSetting::create(['environment' => 'test', 'test_partner_id' => 1000001, 'test_partner_key' => 'rahasia-test']);
        $shop = ShopeeShop::create(['environment' => 'test', 'shop_id' => 555, 'status' => 'connected', 'access_token' => 'a', 'refresh_token' => 'r', 'access_token_expires_at' => now()->addMinute()]);
        Http::fake(['*/api/v2/auth/access_token/get*' => Http::response(['error' => '', 'access_token' => 'x', 'refresh_token' => 'y', 'expire_in' => 14400])]);

        app(ShopeeAuth::class)->refresh($shop);

        Queue::assertNothingPushed();
    }
}
