<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopeeSetting;
use App\Models\ShopeeShop;
use App\Models\TiktokSetting;
use App\Models\TiktokShop;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Shopee\ShopeeCatalog;
use App\Services\StockService;
use App\Services\Tiktok\TiktokCatalog;
use App\Support\MarketplaceStorefront;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Halaman Produk Channel: listing marketplace per toko dengan variasi, harga, stok & pemetaan. */
class ChannelProductsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $warehouse;

    private ProductVariant $kaosM;

    private ProductVariant $kaosL;

    private Bundle $bundle;

    private ShopeeShop $shopee;

    private TiktokShop $tiktok;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
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

        $this->shopee = ShopeeShop::create([
            'environment' => 'live', 'shop_id' => 555, 'shop_name' => 'Toko Oranye', 'region' => 'ID', 'warehouse_id' => $this->warehouse->id,
            'status' => 'connected', 'access_token' => 'akses', 'refresh_token' => 'refresh', 'access_token_expires_at' => now()->addHours(3),
        ]);
        $this->shopee->mappings()->createMany([
            ['item_id' => 11, 'model_id' => 101, 'shopee_sku' => 'KAOS-M', 'item_name' => 'Kaos Polos Premium', 'model_name' => 'Merah, M', 'product_variant_id' => $this->kaosM->id, 'match_type' => 'auto', 'channel_price' => 45000, 'channel_stock' => 7, 'channel_status' => 'NORMAL', 'image_url' => 'https://cf.shopee.co.id/file/kaos.jpg'],
            ['item_id' => 11, 'model_id' => 102, 'shopee_sku' => 'KAOS-XL', 'item_name' => 'Kaos Polos Premium', 'model_name' => 'Merah, XL', 'channel_price' => 47000, 'channel_stock' => 0, 'channel_status' => 'NORMAL'],
            ['item_id' => 22, 'model_id' => 0, 'shopee_sku' => 'PAKET-2', 'item_name' => 'Paket Hemat 2 Kaos', 'bundle_id' => $this->bundle->id, 'channel_status' => 'UNLIST', 'stock_error' => 'model_id not exist'],
            ['item_id' => 33, 'model_id' => 0, 'shopee_sku' => 'LAMA', 'item_name' => 'Produk Lama', 'removed_at' => now()],
        ]);

        $this->tiktok = TiktokShop::create([
            'shop_id' => '7495000000000000001', 'shop_cipher' => 'ROW_cipher1', 'shop_name' => 'Optima TikTok', 'region' => 'ID', 'warehouse_id' => $this->warehouse->id,
            'status' => 'connected', 'access_token' => 'akses', 'refresh_token' => 'refresh', 'access_token_expires_at' => now()->addDays(5),
        ]);
        $this->tiktok->mappings()->create(['product_id' => '1001', 'sku_id' => '2001', 'seller_sku' => 'kaos-l', 'product_name' => 'Kaos Polos TikTok', 'sku_name' => 'L', 'channel_price' => 50000, 'channel_stock' => 4, 'channel_status' => 'ACTIVATE']);
    }

    public function test_page_lists_listings_per_shop_with_variants_prices_stock_and_storefront_links(): void
    {
        $this->actingAs(User::where('username', 'superadmin')->first());

        $response = $this->get('/marketplace/channel-products')->assertOk()
            ->assertSee('Kaos Polos Premium')->assertSee('Merah, M')->assertSee('Merah, XL')
            ->assertSee('Paket Hemat 2 Kaos')->assertSee('Kaos Polos TikTok')
            ->assertDontSee('Produk Lama')
            ->assertSee('Rp 45.000')->assertSee('Rp 47.000')->assertSee('Rp 50.000')
            // Link etalase: Shopee per shop_id/item_id, TikTok Indonesia lewat Shop | Tokopedia.
            ->assertSee('https://shopee.co.id/product/555/11', false)
            ->assertSee('https://shop-id.tokopedia.com/view/product/1001', false)
            ->assertSee('https://cf.shopee.co.id/file/kaos.jpg', false)
            ->assertSee('1 belum terhubung')->assertSee('1 gagal kirim stok')
            ->assertSee('Tayang')->assertSee('Tidak tayang');

        // Stok Optima: KAOS-M 10 di gudang toko, paket dibatasi komponen tersedotnya (KAOS-L 4).
        $this->assertSame(3, substr_count($response->getContent(), 'data-testid="channel-listing"'));
        $this->assertSame(4, substr_count($response->getContent(), 'data-testid="channel-sku"'));
        $response->assertSeeInOrder(['Merah, M', 'KAOS-M', 'Rp 45.000', '>7<', '>10<'], false);
    }

    public function test_filters_narrow_to_platform_shop_mapping_status_listing_status_and_search(): void
    {
        $this->actingAs(User::where('username', 'superadmin')->first());

        $this->get('/marketplace/channel-products?platform=tiktok')->assertOk()->assertSee('Kaos Polos TikTok')->assertDontSee('Kaos Polos Premium');
        $this->get('/marketplace/channel-products?shop=shopee-'.$this->shopee->id)->assertOk()->assertSee('Kaos Polos Premium')->assertDontSee('Kaos Polos TikTok');
        $this->get('/marketplace/channel-products?status=unmapped')->assertOk()->assertSee('Kaos Polos Premium')->assertSee('Kaos Polos TikTok')->assertDontSee('Paket Hemat');
        $this->get('/marketplace/channel-products?status=mapped')->assertOk()->assertSee('Paket Hemat')->assertDontSee('Kaos Polos Premium');
        $this->get('/marketplace/channel-products?status=failed')->assertOk()->assertSee('Paket Hemat')->assertDontSee('Kaos Polos Premium');
        $this->get('/marketplace/channel-products?status=removed')->assertOk()->assertSee('Produk Lama')->assertDontSee('Kaos Polos Premium');
        $this->get('/marketplace/channel-products?listing=hidden')->assertOk()->assertSee('Paket Hemat')->assertDontSee('Kaos Polos Premium');
        $this->get('/marketplace/channel-products?listing=live')->assertOk()->assertSee('Kaos Polos Premium')->assertDontSee('Paket Hemat');
        // Cari SKU variasi: listing induknya tampil utuh dengan semua variasinya.
        $this->get('/marketplace/channel-products?search=KAOS-XL')->assertOk()->assertSee('Kaos Polos Premium')->assertSee('Merah, M')->assertDontSee('Kaos Polos TikTok');
        $this->get('/marketplace/channel-products?search=1001')->assertOk()->assertSee('Kaos Polos TikTok')->assertDontSee('Kaos Polos Premium');
    }

    public function test_auto_match_binds_matching_skus_across_all_shops(): void
    {
        $this->actingAs(User::where('username', 'superadmin')->first());
        $this->kaosM->product->variants()->create(['sku' => 'KAOS-XL', 'name' => 'XL', 'is_active' => true]);

        $this->post('/marketplace/channel-products/auto-match')->assertRedirect()->assertSessionHas('success', fn ($m) => str_starts_with($m, '2 SKU channel cocok otomatis'));

        $this->assertNotNull($this->shopee->mappings()->where('model_id', 102)->value('product_variant_id'));
        $this->assertSame($this->kaosL->id, $this->tiktok->mappings()->where('sku_id', '2001')->value('product_variant_id'));
    }

    public function test_viewer_without_mapping_permission_sees_read_only_page(): void
    {
        $role = Role::create(['name' => 'Pemantau'])->givePermissionTo('shopee.view');
        $this->actingAs(User::factory()->create()->assignRole($role));

        $this->get('/marketplace/channel-products')->assertOk()
            ->assertSee('Kaos Polos Premium')->assertDontSee('Kaos Polos TikTok')
            ->assertDontSee('Cocokkan Otomatis')->assertDontSee('mappings/lookup');
        $this->post('/marketplace/channel-products/auto-match')->assertForbidden();

        $this->actingAs(User::factory()->create());
        $this->get('/marketplace/channel-products')->assertForbidden();
    }

    public function test_shopee_pull_stores_price_stock_status_and_image(): void
    {
        ShopeeSetting::create(['environment' => 'live', 'live_partner_id' => 1000001, 'live_partner_key' => 'rahasia']);
        Http::fake([
            '*/product/get_item_list*' => fn (Request $r) => Http::response(['error' => '', 'response' => [
                'item' => $r['item_status'] === 'NORMAL' ? [['item_id' => 11]] : [['item_id' => 22]], 'has_next_page' => false,
            ]]),
            '*/product/get_item_base_info*' => fn (Request $r) => Http::response(['error' => '', 'response' => ['item_list' => array_values(array_filter([
                ['item_id' => 11, 'item_name' => 'Kaos Polos Premium', 'has_model' => true, 'item_status' => 'NORMAL', 'image' => ['image_url_list' => ['https://cf.shopee.co.id/file/kaos-baru.jpg']]],
                ['item_id' => 22, 'item_name' => 'Paket Hemat 2 Kaos', 'has_model' => false, 'item_sku' => 'PAKET-2', 'item_status' => 'UNLIST',
                    'image' => ['image_url_list' => ['https://cf.shopee.co.id/file/paket.jpg']],
                    'price_info' => [['current_price' => 80000, 'original_price' => 90000]],
                    'stock_info_v2' => ['summary_info' => ['total_available_stock' => 3]]],
            ], fn ($item) => in_array((string) $item['item_id'], explode(',', $r['item_id_list']), true)))]]),
            '*/product/get_model_list*' => Http::response(['error' => '', 'response' => [
                'tier_variation' => [['name' => 'Warna', 'option_list' => [['option' => 'Merah', 'image' => ['image_url' => 'https://cf.shopee.co.id/file/merah.jpg']]]], ['name' => 'Ukuran', 'option_list' => [['option' => 'M'], ['option' => 'XL']]]],
                'model' => [
                    ['model_id' => 101, 'model_sku' => 'KAOS-M', 'tier_index' => [0, 0], 'price_info' => [['current_price' => 46000.0]], 'stock_info_v2' => ['seller_stock' => [['location_id' => 'IDZ', 'stock' => 9]]]],
                    ['model_id' => 102, 'model_sku' => 'KAOS-XL', 'tier_index' => [0, 1], 'price_info' => [['current_price' => 48000]], 'stock_info_v2' => ['summary_info' => ['total_available_stock' => 1]]],
                ],
            ]]),
        ]);

        app(ShopeeCatalog::class)->pull($this->shopee);

        $m = $this->shopee->mappings()->where('model_id', 101)->first();
        $this->assertSame(['46000.00', 9, 'NORMAL', 'https://cf.shopee.co.id/file/merah.jpg', 'Merah, M'], [$m->channel_price, $m->channel_stock, $m->channel_status, $m->image_url, $m->model_name]);
        $xl = $this->shopee->mappings()->where('model_id', 102)->first();
        $this->assertSame(['48000.00', 1], [$xl->channel_price, $xl->channel_stock]);
        $paket = $this->shopee->mappings()->where('item_id', 22)->first();
        $this->assertSame(['80000.00', 3, 'UNLIST', 'https://cf.shopee.co.id/file/paket.jpg'], [$paket->channel_price, $paket->channel_stock, $paket->channel_status, $paket->image_url]);
    }

    public function test_tiktok_pull_stores_price_stock_and_status(): void
    {
        TiktokSetting::create(['app_key' => 'appkey123', 'app_secret' => 'rahasia', 'service_id' => '7300000000000000001']);
        Http::fake(['*/product/202309/products/search*' => Http::response(['code' => 0, 'data' => ['products' => [
            ['id' => '1001', 'title' => 'Kaos Polos TikTok', 'status' => 'ACTIVATE', 'skus' => [
                ['id' => '2001', 'seller_sku' => 'kaos-l', 'sales_attributes' => [['name' => 'Ukuran', 'value_name' => 'L']], 'price' => ['currency' => 'IDR', 'sale_price' => '52000', 'tax_exclusive_price' => '46846'], 'inventory' => [['warehouse_id' => '7000000000000000009', 'quantity' => 2], ['warehouse_id' => '7000000000000000010', 'quantity' => 3]]],
            ]],
            ['id' => '1002', 'title' => 'Topi', 'status' => 'PENDING', 'skus' => [['id' => '2002', 'seller_sku' => 'TOPI']]],
        ], 'next_page_token' => '']])]);

        app(TiktokCatalog::class)->pull($this->tiktok);

        $m = $this->tiktok->mappings()->where('sku_id', '2001')->first();
        $this->assertSame(['52000.00', 5, 'ACTIVATE'], [$m->channel_price, $m->channel_stock, $m->channel_status]);
        $topi = $this->tiktok->mappings()->where('sku_id', '2002')->first();
        $this->assertSame([null, null, 'PENDING'], [$topi->channel_price, $topi->channel_stock, $topi->channel_status]);
    }

    public function test_storefront_urls_follow_shop_region(): void
    {
        $this->assertSame('https://shopee.com.my/product/555/11', MarketplaceStorefront::shopee(new ShopeeShop(['shop_id' => 555, 'region' => 'MY']), 11));
        $this->assertSame('https://shopee.co.id/product/555/11', MarketplaceStorefront::shopee(new ShopeeShop(['shop_id' => 555]), 11));
        $this->assertSame('https://shop.tiktok.com/view/product/1001', MarketplaceStorefront::tiktok(new TiktokShop(['region' => 'MY']), '1001'));
    }
}
