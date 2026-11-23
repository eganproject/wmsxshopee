<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopeeSetting;
use App\Models\ShopeeShop;
use App\Models\TiktokShop;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\SkuMappingProblems;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Card "Masalah pemetaan" di halaman Pemetaan SKU: jenis masalah yang
 * terdeteksi, dan tombol penyelesaiannya (petakan ulang, kirim ulang stok,
 * buat paket dari SKU mirip paket, tandai bukan paket).
 */
class SkuMappingProblemsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $warehouse;

    private ShopeeShop $shop;

    private ProductVariant $kaos;

    private ProductVariant $topi;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        ShopeeSetting::create(['environment' => 'test', 'test_partner_id' => 1000001, 'test_partner_key' => 'rahasia-test']);

        $this->warehouse = Warehouse::create(['code' => 'SBY01', 'name' => 'Gudang Surabaya', 'is_active' => true]);
        $unit = Unit::where('code', 'PCS')->value('id');
        $this->kaos = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => $unit, 'has_variants' => false, 'is_active' => true])
            ->variants()->create(['sku' => 'KAOS', 'is_active' => true]);
        $this->topi = Product::create(['sku' => 'TOPI', 'name' => 'Topi', 'unit_id' => $unit, 'has_variants' => false, 'is_active' => true])
            ->variants()->create(['sku' => 'TOPI', 'is_active' => true]);

        $this->shop = ShopeeShop::create([
            'stock_push' => true,
            'environment' => 'test', 'shop_id' => 555, 'shop_name' => 'Toko Uji', 'warehouse_id' => $this->warehouse->id,
            'status' => 'connected', 'access_token' => 'akses', 'refresh_token' => 'refresh', 'access_token_expires_at' => now()->addHours(3),
        ]);

        $this->actingAs(User::where('username', 'superadmin')->first());
    }

    private function problems(): array
    {
        return collect(SkuMappingProblems::for('shopee', $this->shop->fresh())->all(auth()->user()))->keyBy('key')->all();
    }

    public function test_page_shows_green_card_when_nothing_is_wrong(): void
    {
        $this->shop->mappings()->create(['item_id' => 11, 'model_id' => 0, 'shopee_sku' => 'KAOS', 'product_variant_id' => $this->kaos->id, 'match_type' => 'auto']);

        $this->get("/shopee/shops/{$this->shop->id}/mappings")->assertOk()
            ->assertSee('Tidak ada masalah pemetaan')->assertDontSee('Masalah pemetaan</h2>', false);
        $this->assertSame([], $this->problems());
    }

    public function test_sku_mapped_to_a_different_optima_sku_is_flagged_and_can_be_remapped(): void
    {
        // SKU Shopee "TOPI" ada di gudang, tapi dipetakan manual ke KAOS.
        $wrong = $this->shop->mappings()->create(['item_id' => 11, 'model_id' => 0, 'shopee_sku' => 'topi', 'product_variant_id' => $this->kaos->id, 'match_type' => 'manual', 'stock_error' => 'lama']);
        // Dipetakan ke SKU yang memang sama: bukan masalah.
        $this->shop->mappings()->create(['item_id' => 12, 'model_id' => 0, 'shopee_sku' => 'KAOS', 'product_variant_id' => $this->kaos->id, 'match_type' => 'auto']);
        // SKU Shopee tidak ada di gudang, dipetakan manual ke KAOS: sah.
        $this->shop->mappings()->create(['item_id' => 13, 'model_id' => 0, 'shopee_sku' => 'KAOS-PROMO', 'product_variant_id' => $this->kaos->id, 'match_type' => 'manual']);
        // Paket dengan SKU sama, dipetakan ke varian lain: masalah juga.
        $bundle = Bundle::create(['sku' => 'PAKET-2', 'name' => 'Paket 2', 'is_active' => true]);
        $bundle->items()->create(['product_variant_id' => $this->kaos->id, 'qty' => 2]);
        $wrongBundle = $this->shop->mappings()->create(['item_id' => 14, 'model_id' => 0, 'shopee_sku' => 'PAKET-2', 'product_variant_id' => $this->topi->id, 'match_type' => 'manual']);
        $this->shop->mappings()->create(['item_id' => 15, 'model_id' => 0, 'shopee_sku' => 'PAKET-2', 'bundle_id' => $bundle->id, 'match_type' => 'auto']);

        $problems = $this->problems();
        $this->assertSame(2, $problems['sku_conflict']['count']);
        $this->assertSame(1, $problems['failed']['count']);

        $page = $this->get("/shopee/shops/{$this->shop->id}/mappings")->assertOk()
            ->assertSee('2 SKU dipetakan ke SKU Optima yang berbeda')->assertSee('Petakan ulang sesuai SKU');
        $this->assertSame([11, 14], $this->get("/shopee/shops/{$this->shop->id}/mappings?status=sku_conflict")->viewData('mappings')->pluck('item_id')->all());

        $this->post("/shopee/shops/{$this->shop->id}/mappings/fix", ['problem' => 'sku_conflict'])->assertSessionHas('success');
        $this->assertSame($this->topi->id, $wrong->fresh()->product_variant_id);
        $this->assertSame('auto', $wrong->fresh()->match_type);
        $this->assertNull($wrong->fresh()->stock_error);
        $this->assertSame($bundle->id, $wrongBundle->fresh()->bundle_id);
        $this->assertNull($wrongBundle->fresh()->product_variant_id);
        $this->assertArrayNotHasKey('sku_conflict', $this->problems());

        $this->post("/shopee/shops/{$this->shop->id}/mappings/fix", ['problem' => 'lainnya'])->assertSessionHasErrors('problem');
    }

    public function test_failed_stock_push_can_be_retried_from_the_card(): void
    {
        $this->shop->mappings()->create(['item_id' => 11, 'model_id' => 0, 'shopee_sku' => 'KAOS', 'product_variant_id' => $this->kaos->id, 'match_type' => 'auto', 'stock_error' => 'error_item_not_found']);

        $problems = $this->problems();
        $this->assertSame(1, $problems['failed']['count']);
        $this->assertSame('Kirim ulang stok', $problems['failed']['actions'][0]['label']);
        $this->get("/shopee/shops/{$this->shop->id}/mappings")->assertOk()->assertSee('1 SKU gagal kirim stok')->assertSee('Kirim ulang stok');

        Http::fake(['*/product/update_stock*' => Http::response(['error' => '', 'response' => ['success_list' => [], 'failure_list' => []]])]);
        $this->post("/shopee/shops/{$this->shop->id}/mappings/retry-stock")->assertSessionHas('success');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'update_stock') && $r['item_id'] === 11);
        $this->assertNull($this->shop->mappings()->first()->stock_error);
        $this->assertArrayNotHasKey('failed', $this->problems());

        // Kirim stok mati: tombol tidak ditawarkan dan aksinya ditolak dengan pesan.
        $this->shop->update(['stock_push' => false]);
        $this->assertSame('stock_push_off', $this->problems()['stock_push_off']['key']);
        $this->post("/shopee/shops/{$this->shop->id}/mappings/retry-stock")->assertSessionHas('error');
    }

    public function test_bundle_like_sku_can_become_a_bundle_or_be_marked_as_not_a_bundle(): void
    {
        $bund = $this->shop->mappings()->create(['item_id' => 21, 'model_id' => 0, 'shopee_sku' => 'BUND290', 'item_name' => 'Klakson Keong', 'product_variant_id' => $this->kaos->id, 'match_type' => 'manual']);
        $bund2 = $this->shop->mappings()->create(['item_id' => 22, 'model_id' => 0, 'shopee_sku' => 'BUND300', 'product_variant_id' => $this->kaos->id, 'match_type' => 'manual']);

        $this->assertSame(2, $this->problems()['bundle_like']['count']);
        $this->get("/shopee/shops/{$this->shop->id}/mappings?status=bundle_like")->assertOk()
            ->assertSee('2 SKU mirip paket dipetakan ke produk biasa')->assertSee('Buat Paket')->assertSee('Bukan paket');

        // "Bukan paket": tidak dihitung masalah lagi, tag Mirip paket hilang.
        $this->post("/shopee/shops/{$this->shop->id}/mappings/{$bund2->id}/not-bundle")->assertSessionHas('success');
        $this->assertNotNull($bund2->fresh()->bundle_like_ignored_at);
        $this->assertFalse($bund2->fresh()->looksLikeBundle());
        $this->assertSame(1, $this->problems()['bundle_like']['count']);

        // "Buat Paket": form terisi dari baris pemetaan, lalu baris dipetakan ke paket baru.
        $this->get("/master/bundles/create?sku=BUND290&name=Klakson+Keong&variant={$this->kaos->id}&map=shopee:{$bund->id}")->assertOk()
            ->assertSee('value="BUND290"', false)->assertSee('Klakson Keong')->assertSee('dibuat untuk SKU');
        $this->post('/master/bundles', [
            'sku' => 'BUND290', 'name' => 'Klakson Keong', 'map' => "shopee:{$bund->id}",
            'items' => [['product_variant_id' => $this->kaos->id, 'qty' => 2]],
        ])->assertSessionHasNoErrors()->assertRedirect("/shopee/shops/{$this->shop->id}/mappings");

        $bundle = Bundle::where('sku', 'BUND290')->firstOrFail();
        $this->assertSame($bundle->id, $bund->fresh()->bundle_id);
        $this->assertNull($bund->fresh()->product_variant_id);
        $this->assertArrayNotHasKey('bundle_like', $this->problems());
    }

    public function test_unmapped_and_inactive_targets_are_listed(): void
    {
        $this->shop->mappings()->create(['item_id' => 31, 'model_id' => 0, 'shopee_sku' => 'BARU', 'item_name' => 'Baru']);
        $this->shop->mappings()->create(['item_id' => 32, 'model_id' => 0, 'shopee_sku' => null, 'item_name' => 'Tanpa SKU']);
        $this->topi->update(['is_active' => false]);
        $this->shop->mappings()->create(['item_id' => 33, 'model_id' => 0, 'shopee_sku' => 'TOPI', 'product_variant_id' => $this->topi->id, 'match_type' => 'auto']);
        $this->shop->mappings()->create(['item_id' => 34, 'model_id' => 0, 'shopee_sku' => 'LAMA', 'removed_at' => now()]);

        $problems = $this->problems();
        $this->assertSame(2, $problems['unmapped']['count']);
        $this->assertStringContainsString('1 di antaranya tidak punya SKU di Shopee', $problems['unmapped']['body']);
        $this->assertSame(1, $problems['inactive_target']['count']);
        $this->assertSame(1, $problems['removed']['count']);
        $this->assertSame(2, SkuMappingProblems::issueCount($problems));

        $this->get("/shopee/shops/{$this->shop->id}/mappings")->assertOk()
            ->assertSee('2 SKU belum dipetakan')->assertSee('1 SKU dipetakan ke produk/paket nonaktif')->assertSee('1 SKU sudah tidak ada lagi di Shopee');
        $this->assertSame([33], $this->get("/shopee/shops/{$this->shop->id}/mappings?status=inactive_target")->viewData('mappings')->pluck('item_id')->all());
    }

    public function test_tiktok_page_has_the_same_card(): void
    {
        $shop = TiktokShop::create([
            'stock_push' => true, 'shop_id' => '7495000000000000001', 'shop_cipher' => 'ROW_cipher1', 'shop_name' => 'Toko TikTok', 'region' => 'ID',
            'open_id' => 'open-1', 'warehouse_id' => $this->warehouse->id, 'status' => 'connected', 'access_token' => 'akses', 'refresh_token' => 'refresh', 'access_token_expires_at' => now()->addDays(5),
        ]);
        $wrong = $shop->mappings()->create(['product_id' => '1001', 'sku_id' => '2001', 'seller_sku' => 'TOPI', 'product_variant_id' => $this->kaos->id, 'match_type' => 'manual']);
        $bund = $shop->mappings()->create(['product_id' => '1001', 'sku_id' => '2002', 'seller_sku' => 'BUND1', 'product_variant_id' => $this->kaos->id, 'match_type' => 'manual']);

        $this->get("/tiktok/shops/{$shop->id}/mappings")->assertOk()
            ->assertSee('1 SKU dipetakan ke SKU Optima yang berbeda')->assertSee('1 SKU mirip paket dipetakan ke produk biasa')->assertSee('Petakan ulang sesuai SKU');

        $this->post("/tiktok/shops/{$shop->id}/mappings/fix", ['problem' => 'sku_conflict'])->assertSessionHas('success');
        $this->assertSame($this->topi->id, $wrong->fresh()->product_variant_id);

        $this->post("/tiktok/shops/{$shop->id}/mappings/{$bund->id}/not-bundle")->assertSessionHas('success');
        $this->assertSame([], SkuMappingProblems::for('tiktok', $shop->fresh())->all(auth()->user()));
    }
}
