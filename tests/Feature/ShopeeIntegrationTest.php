<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\Product;
use App\Models\ShopeeSetting;
use App\Models\ShopeeShop;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Shopee\ShopeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopeeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->warehouse = Warehouse::create(['code' => 'JKT01', 'name' => 'Gudang Jakarta', 'is_active' => true]);
        $this->actingAs(User::where('username', 'superadmin')->first());
    }

    private function configure(): ShopeeSetting
    {
        return ShopeeSetting::create(['environment' => 'test', 'test_partner_id' => 1000001, 'test_partner_key' => 'rahasia-test']);
    }

    private function connectedShop(array $attributes = []): ShopeeShop
    {
        return ShopeeShop::create([
            'environment' => 'test',
            'shop_id' => 555,
            'shop_name' => 'Toko Uji',
            'warehouse_id' => $this->warehouse->id,
            'status' => 'connected',
            'access_token' => 'akses-lama',
            'refresh_token' => 'refresh-lama',
            'access_token_expires_at' => now()->addHours(3),
            ...$attributes,
        ]);
    }

    public function test_pages_render(): void
    {
        $this->get('/shopee/settings')->assertRedirect(route('marketplace.settings', ['tab' => 'shopee']));
        $this->get('/shopee/shops')->assertRedirect(route('marketplace.shops', ['platform' => 'shopee']));
        $this->get('/marketplace/settings?tab=shopee')->assertOk()->assertSee('Seller In House System')->assertSee(route('shopee.callback'))->assertSee(route('shopee.webhook'));
        $this->get('/marketplace/shops')->assertOk()->assertSee('Isi Kredensial');

        $this->configure();
        $shop = $this->connectedShop();
        $this->get('/marketplace/shops')->assertOk()->assertSee('Hubungkan Toko')->assertSee('Toko Uji');
        $this->get("/shopee/shops/{$shop->id}/mappings")->assertOk()->assertSee('Tarik dari Shopee');
    }

    public function test_signatures_follow_shopee_v2_base_strings(): void
    {
        $this->configure();
        $client = ShopeeClient::make();

        $this->assertSame(hash_hmac('sha256', '1000001/api/v2/shop/auth_partner1700000000', 'rahasia-test'), $client->sign('/api/v2/shop/auth_partner', 1700000000));
        $this->assertSame(hash_hmac('sha256', '1000001/api/v2/shop/get_shop_info1700000000tokenABC555', 'rahasia-test'), $client->sign('/api/v2/shop/get_shop_info', 1700000000, 'tokenABC', 555));

        $url = $client->authorizationUrl('http://optima.test/shopee/callback?state=x');
        $this->assertStringStartsWith('https://openplatform.sandbox.test-stable.shopee.sg/api/v2/shop/auth_partner?partner_id=1000001&timestamp=', $url);
        $this->assertStringContainsString('&redirect='.urlencode('http://optima.test/shopee/callback?state=x'), $url);
    }

    public function test_settings_store_partner_key_encrypted_and_keep_it_when_left_blank(): void
    {
        $this->put('/shopee/settings', ['environment' => 'live', 'test_partner_id' => 1000001, 'test_partner_key' => 'rahasia'])
            ->assertSessionHasErrors('live_partner_id');

        $this->put('/shopee/settings', ['environment' => 'test', 'test_partner_id' => 1000001, 'test_partner_key' => 'rahasia'])
            ->assertRedirect(route('marketplace.settings', ['tab' => 'shopee']));

        $this->assertNotSame('rahasia', DB::table('shopee_settings')->value('test_partner_key'));
        $this->assertSame('rahasia', ShopeeSetting::current()->test_partner_key);

        $this->put('/shopee/settings', ['environment' => 'test', 'test_partner_id' => 1000002, 'test_partner_key' => ''])->assertSessionHasNoErrors();
        $this->assertSame(1000002, ShopeeSetting::current()->test_partner_id);
        $this->assertSame('rahasia', ShopeeSetting::current()->test_partner_key);
        $this->get('/marketplace/settings?tab=shopee')->assertDontSee('rahasia');
    }

    public function test_connect_and_callback_store_the_shop_with_tokens(): void
    {
        $this->configure();

        $redirect = $this->post('/shopee/shops/connect', ['warehouse_id' => $this->warehouse->id])->headers->get('Location');
        $this->assertStringContainsString('/api/v2/shop/auth_partner', $redirect);
        parse_str(parse_url($redirect, PHP_URL_QUERY), $query);
        $this->assertStringStartsWith(route('shopee.callback'), $query['redirect']);
        parse_str(parse_url($query['redirect'], PHP_URL_QUERY), $back);
        $state = session('shopee_auth.state');
        $this->assertSame($state, $back['state']);

        Http::fake([
            '*/api/v2/auth/token/get*' => Http::response(['error' => '', 'access_token' => 'akses-1', 'refresh_token' => 'refresh-1', 'expire_in' => 14400]),
            '*/api/v2/shop/get_shop_info*' => Http::response(['error' => '', 'shop_name' => 'Optima Official', 'region' => 'ID', 'expire_time' => now()->addYear()->getTimestamp()]),
        ]);

        $this->get("/shopee/callback?state={$state}&code=kode123&shop_id=555")
            ->assertRedirect(route('marketplace.shops', ['platform' => 'shopee']))->assertSessionHas('success');

        $shop = ShopeeShop::firstOrFail();
        $this->assertSame([555, 'test', 'Optima Official', 'ID', $this->warehouse->id, 'connected'], [$shop->shop_id, $shop->environment, $shop->shop_name, $shop->region, $shop->warehouse_id, $shop->status]);
        $this->assertSame('refresh-1', $shop->refresh_token);
        $this->assertNotSame('akses-1', DB::table('shopee_shops')->value('access_token'));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/auth/token/get') && $r['code'] === 'kode123' && $r['shop_id'] === 555 && $r['partner_id'] === 1000001);
    }

    public function test_callback_rejects_missing_or_wrong_state(): void
    {
        $this->configure();
        Http::fake();

        $this->get('/shopee/callback?state=palsu&code=x&shop_id=1')->assertSessionHas('error');
        $this->post('/shopee/shops/connect', ['warehouse_id' => $this->warehouse->id]);
        $this->get('/shopee/callback?state=palsu&code=x&shop_id=1')->assertSessionHas('error');

        $this->assertSame(0, ShopeeShop::count());
        Http::assertNothingSent();
    }

    public function test_refresh_command_only_refreshes_expiring_tokens_and_marks_revoked_shops(): void
    {
        $this->configure();
        $fresh = $this->connectedShop();
        $expiring = $this->connectedShop(['shop_id' => 556, 'access_token_expires_at' => now()->addMinutes(10)]);

        $revoked = ['error' => 'error_auth', 'message' => 'Invalid refresh_token.'];
        Http::fake(['*/api/v2/auth/access_token/get*' => Http::sequence()
            ->push(['error' => '', 'access_token' => 'akses-baru', 'refresh_token' => 'refresh-baru', 'expire_in' => 14400])
            ->push($revoked)->push($revoked)]);

        $this->artisan('shopee:refresh-tokens')->assertSuccessful();

        $this->assertSame('refresh-baru', $expiring->fresh()->refresh_token);
        $this->assertSame('refresh-lama', $fresh->fresh()->refresh_token);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r['refresh_token'] === 'refresh-lama' && $r['shop_id'] === 556);

        // Otorisasi dicabut pemilik toko: refresh ditolak, toko ditandai perlu otorisasi ulang.
        $this->artisan('shopee:refresh-tokens --force')->assertFailed();
        $this->assertSame('expired', $fresh->fresh()->status);
    }

    public function test_pull_catalog_and_auto_match_by_sku(): void
    {
        $this->configure();
        $shop = $this->connectedShop();

        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => true, 'is_active' => true]);
        $kaosM = $product->variants()->create(['sku' => 'KAOS-M', 'name' => 'M', 'is_active' => true]);
        $bundle = Bundle::create(['sku' => 'PAKET-3', 'name' => 'Paket Hemat', 'price' => 1, 'is_active' => true]);

        Http::fake([
            '*/product/get_item_list*' => Http::response(['error' => '', 'response' => ['item' => [['item_id' => 11], ['item_id' => 22]], 'has_next_page' => false]]),
            '*/product/get_item_base_info*' => Http::response(['error' => '', 'response' => ['item_list' => [
                ['item_id' => 11, 'item_name' => 'Kaos Polos', 'has_model' => true],
                ['item_id' => 22, 'item_name' => 'Paket Hemat', 'item_sku' => 'paket-3', 'has_model' => false],
            ]]]),
            '*/product/get_model_list*' => Http::response(['error' => '', 'response' => [
                'tier_variation' => [['name' => 'Ukuran', 'option_list' => [['option' => 'M'], ['option' => 'XL']]]],
                'model' => [['model_id' => 101, 'model_sku' => 'KAOS-M', 'tier_index' => [0]], ['model_id' => 102, 'model_sku' => 'KAOS-XL', 'tier_index' => [1]]],
            ]]),
        ]);

        $this->post("/shopee/shops/{$shop->id}/mappings/pull")->assertSessionHas('success');

        $rows = $shop->mappings()->orderBy('model_id')->get()->keyBy('model_id');
        $this->assertCount(3, $rows);
        $this->assertSame($bundle->id, $rows[0]->bundle_id);
        $this->assertSame($kaosM->id, $rows[101]->product_variant_id);
        $this->assertSame('M', $rows[101]->model_name);
        $this->assertFalse($rows[102]->isMapped());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'get_item_list') && $r['access_token'] === 'akses-lama' && $r['shop_id'] === '555');

        // Petakan manual baris yang tidak cocok, lalu lepas lagi.
        $this->put("/shopee/shops/{$shop->id}/mappings/{$rows[102]->id}", ['target' => "variant:{$kaosM->id}"])->assertSessionHas('success');
        $this->assertSame('manual', $rows[102]->fresh()->match_type);
        $this->put("/shopee/shops/{$shop->id}/mappings/{$rows[102]->id}", ['target' => 'variant:99999'])->assertSessionHasErrors('target');
        $this->put("/shopee/shops/{$shop->id}/mappings/{$rows[102]->id}", ['target' => ''])->assertSessionHasNoErrors();
        $this->assertFalse($rows[102]->fresh()->isMapped());

        $this->getJson('/shopee/mapping-targets?q=paket')->assertJsonFragment(['value' => "bundle:{$bundle->id}"]);
    }

    public function test_manual_row_is_auto_matched_and_duplicates_are_rejected(): void
    {
        $shop = $this->connectedShop();
        $product = Product::create(['sku' => 'TOPI', 'name' => 'Topi', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => false, 'is_active' => true]);
        $variant = $product->variants()->create(['sku' => 'TOPI-01', 'name' => 'Topi', 'is_active' => true]);

        $this->post("/shopee/shops/{$shop->id}/mappings", ['item_id' => 77, 'shopee_sku' => 'topi-01'])->assertSessionHasNoErrors();
        $this->assertSame($variant->id, $shop->mappings()->first()->product_variant_id);

        $this->post("/shopee/shops/{$shop->id}/mappings", ['item_id' => 77, 'model_id' => 0])->assertSessionHasErrors('item_id');
    }

    public function test_staff_cannot_manage_shopee(): void
    {
        $staff = User::factory()->create(['username' => 'staf', 'is_active' => true]);
        $staff->assignRole('Staff Gudang');

        $this->actingAs($staff)->get('/marketplace/shops')->assertForbidden();
        $this->actingAs($staff)->get('/marketplace/settings')->assertForbidden();
        $this->actingAs($staff)->get('/marketplace/products')->assertForbidden();
        $this->actingAs($staff)->post('/shopee/shops/connect', ['warehouse_id' => $this->warehouse->id])->assertForbidden();
    }
}
