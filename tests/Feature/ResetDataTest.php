<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\ShopeeShop;
use App\Models\Stock;
use App\Models\TiktokShop;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResetDataTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $warehouse;

    private ShopeeShop $shopee;

    private TiktokShop $tiktok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::create(['code' => 'JKT01', 'name' => 'Gudang Jakarta', 'is_active' => true]);
        $variant = Product::create(['sku' => 'KAOS', 'name' => 'Kaos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => false, 'is_active' => true])
            ->variants()->create(['sku' => 'KAOS-1', 'name' => 'Kaos', 'is_active' => true]);
        app(StockService::class)->record(['type' => 'in', 'warehouse_id' => $this->warehouse->id, 'movement_date' => now()->toDateString()], [
            ['product_variant_id' => $variant->id, 'to_location_id' => null, 'qty' => 5],
        ]);

        $this->shopee = ShopeeShop::create([
            'stock_push' => true, 'environment' => 'live', 'shop_id' => 555, 'shop_name' => 'Toko Uji', 'warehouse_id' => $this->warehouse->id,
            'status' => 'connected', 'access_token' => 'akses', 'refresh_token' => 'refresh', 'access_token_expires_at' => now()->addHours(3),
            'orders_synced_until' => now(),
        ]);
        $this->shopee->mappings()->create(['item_id' => 1, 'model_id' => 0, 'shopee_sku' => 'KAOS-1', 'product_variant_id' => $variant->id, 'stock_pushed' => 5]);
        $this->tiktok = TiktokShop::create([
            'stock_push' => true, 'shop_id' => '749', 'shop_cipher' => 'c', 'shop_name' => 'TikTok Uji', 'warehouse_id' => $this->warehouse->id,
            'status' => 'connected', 'access_token' => 'akses-tt', 'refresh_token' => 'refresh-tt', 'access_token_expires_at' => now()->addDays(5),
        ]);
        $this->tiktok->mappings()->create(['product_id' => 'p1', 'sku_id' => 's1', 'seller_sku' => 'KAOS-1', 'product_variant_id' => $variant->id]);

        $order = SalesOrder::create(['number' => SalesOrder::nextNumber(), 'status' => 'draft', 'warehouse_id' => $this->warehouse->id, 'order_date' => now()->toDateString(), 'shopee_shop_id' => $this->shopee->id, 'external_number' => 'SN1', 'customer_name' => 'Budi']);
        $order->items()->create(['product_variant_id' => $variant->id, 'qty' => 1, 'price' => 1000]);
    }

    public function test_reset_clears_transactions_but_keeps_shops_connected_and_products(): void
    {
        $this->artisan('optima:reset-data --force --no-backup --days=10')->assertSuccessful();

        $this->assertSame(0, SalesOrder::withoutGlobalScopes()->count());
        $this->assertSame(0, Stock::withoutGlobalScopes()->count());
        $this->assertSame(1, Product::count());
        $this->assertNotNull(User::where('username', 'superadmin')->first());
        $this->assertSame(1, Warehouse::count());

        $shopee = $this->shopee->refresh();
        $this->assertSame('connected', $shopee->status);
        $this->assertSame('akses', $shopee->access_token);
        $this->assertFalse((bool) $shopee->stock_push, 'Stok kosong tidak boleh terkirim ke marketplace.');
        $this->assertTrue($shopee->orders_synced_until->between(now()->subDays(10)->subMinute(), now()->subDays(10)->addMinute()));
        $mapping = $shopee->mappings()->first();
        $this->assertNotNull($mapping->product_variant_id);
        $this->assertNull($mapping->stock_pushed);

        $tiktok = $this->tiktok->refresh();
        $this->assertSame('akses-tt', $tiktok->access_token);
        $this->assertFalse((bool) $tiktok->stock_push);

        $this->assertStringStartsWith('SO', SalesOrder::nextNumber());
    }

    public function test_with_products_also_clears_products_and_unlinks_mappings(): void
    {
        $this->artisan('optima:reset-data --force --no-backup --with-products')->assertSuccessful();

        $this->assertSame(0, Product::count());
        $this->assertSame(1, $this->shopee->mappings()->count());
        $this->assertNull($this->shopee->mappings()->first()->product_variant_id);
        $this->assertNull($this->tiktok->mappings()->first()->product_variant_id);
        $this->assertSame('connected', $this->shopee->refresh()->status);
    }

    public function test_reset_asks_for_confirmation(): void
    {
        $this->artisan('optima:reset-data --no-backup')->expectsConfirmation('Lanjutkan?', 'no')->assertSuccessful();

        $this->assertSame(1, SalesOrder::withoutGlobalScopes()->count());
    }
}
