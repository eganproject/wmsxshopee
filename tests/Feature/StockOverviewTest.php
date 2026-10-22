<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Stock;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\StockService;
use App\Support\StockOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Halaman Stok: fisik, dipesan, tersedia, tertahan & status per SKU, dan sudah diambil per bin. */
class StockOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $warehouse;

    private WarehouseLocation $binA;

    private ProductVariant $medium;

    private ProductVariant $large;

    private ProductVariant $xl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::create(['code' => 'JKT01', 'name' => 'Gudang Jakarta', 'is_active' => true]);
        $zone = $this->warehouse->zones()->create(['code' => 'A', 'name' => 'Penyimpanan A', 'type' => 'storage', 'is_active' => true]);
        $rack = $zone->racks()->create(['code' => 'A01', 'levels' => 3, 'is_active' => true]);
        $this->binA = $rack->locations()->create(['warehouse_id' => $this->warehouse->id, 'code' => 'A01-01-01', 'type' => 'bin', 'is_active' => true]);
        $binB = $rack->locations()->create(['warehouse_id' => $this->warehouse->id, 'code' => 'A01-01-02', 'type' => 'bin', 'is_active' => true]);

        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => true, 'is_active' => true]);
        $this->medium = $product->variants()->create(['sku' => 'KAOS-M', 'name' => 'M', 'is_active' => true]);
        $this->large = $product->variants()->create(['sku' => 'KAOS-L', 'name' => 'L', 'is_active' => true, 'min_stock' => 5]);
        $this->xl = $product->variants()->create(['sku' => 'KAOS-XL', 'name' => 'XL', 'is_active' => true]);

        app(StockService::class)->record(
            ['type' => 'in', 'warehouse_id' => $this->warehouse->id, 'movement_date' => now()->toDateString()],
            [
                ['product_variant_id' => $this->medium->id, 'to_location_id' => $this->binA->id, 'qty' => 6],
                ['product_variant_id' => $this->medium->id, 'to_location_id' => $binB->id, 'qty' => 4],
                ['product_variant_id' => $this->large->id, 'to_location_id' => null, 'qty' => 5],
            ],
        );

        $this->actingAs(User::where('username', 'superadmin')->first());

        // Pesanan 1: 3 KAOS-M dikonfirmasi lalu diambil dari bin A.
        $this->order('ORD-1', 'confirm', [[$this->medium, 3]]);
        $first = SalesOrder::where('external_number', 'ORD-1')->with('items')->firstOrFail();
        $this->post("/sales/orders/{$first->id}/pick", ['picks' => [$first->items[0]->id => [['warehouse_location_id' => $this->binA->id, 'qty' => 3]]]])->assertSessionHasNoErrors();

        // Pesanan 2: 2 KAOS-M dikonfirmasi (belum diambil).
        $this->order('ORD-2', 'confirm', [[$this->medium, 2]]);

        // Pesanan 3: draft 8 KAOS-M + 1 KAOS-XL (belum mencadangkan stok).
        $this->order('ORD-3', 'draft', [[$this->medium, 8], [$this->xl, 1]]);
    }

    /** @param  list<array{0: ProductVariant, 1: int}>  $lines */
    private function order(string $number, string $action, array $lines): void
    {
        $this->post('/sales/orders', [
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'channel_name' => 'Shopee Toko A',
            'external_number' => $number,
            'customer_name' => 'Budi',
            'action' => $action,
            'items' => array_map(fn ($line) => ['product_variant_id' => $line[0]->id, 'qty' => $line[1], 'price' => 10000], $lines),
        ])->assertSessionHasNoErrors();
    }

    public function test_per_sku_shows_on_hand_reserved_available_and_held(): void
    {
        $response = $this->get('/inventory/stocks')->assertOk()
            ->assertSee('Per SKU')->assertSee('Dipesan')->assertSee('Tersedia')->assertSee('Tertahan');

        $rows = $response->viewData('stocks')->getCollection()->keyBy('sku');

        $medium = $rows['KAOS-M'];
        $this->assertSame([10, 5, 2, 3, 5, 8], [(int) $medium->on_hand, (int) $medium->reserved, (int) $medium->reserved_waiting, (int) $medium->reserved_picked, (int) $medium->available, (int) $medium->held]);
        $this->assertSame('short', StockOverview::status($medium));

        $this->assertSame('low', StockOverview::status($rows['KAOS-L']));

        // SKU tanpa stok fisik tetap tampil karena ada pesanan.
        $this->assertSame(0, (int) $rows['KAOS-XL']->on_hand);
        $this->assertSame('short', StockOverview::status($rows['KAOS-XL']));

        $this->assertSame(['skus' => 3, 'on_hand' => 15, 'reserved' => 5, 'available' => 10, 'held' => 9, 'short' => 2], $response->viewData('totals'));
    }

    public function test_quick_filters(): void
    {
        $skus = fn (string $status) => $this->get("/inventory/stocks?status={$status}")->assertOk()
            ->viewData('stocks')->getCollection()->pluck('sku')->sort()->values()->all();

        $this->assertSame(['KAOS-M', 'KAOS-XL'], $skus('ordered'));
        $this->assertSame(['KAOS-M', 'KAOS-XL'], $skus('short'));
        $this->assertSame(['KAOS-L'], $skus('low'));
        $this->assertSame(['KAOS-XL'], $skus('out'));
    }

    public function test_sellable_column_only_when_one_warehouse_selected(): void
    {
        $this->get('/inventory/stocks')->assertDontSee('Stok Jual');

        $response = $this->get("/inventory/stocks?warehouse={$this->warehouse->id}")->assertOk()->assertSee('Stok Jual');
        $this->assertSame(5, $response->viewData('sellable')[$this->medium->id]);
    }

    public function test_per_location_shows_picked_and_free(): void
    {
        $response = $this->get('/inventory/stocks?view=location')->assertOk()->assertSee('Sudah Diambil')->assertSee('Bebas');

        $stocks = $response->viewData('stocks')->getCollection();
        $binA = $stocks->first(fn ($s) => $s->warehouse_location_id === $this->binA->id);
        $this->assertSame(3, $response->viewData('picked')[$binA->id]);
    }

    public function test_exports_include_new_columns(): void
    {
        $this->get('/inventory/stocks?export=xlsx')->assertOk()->assertDownload();
        $this->get("/inventory/stocks?export=xlsx&warehouse={$this->warehouse->id}")->assertOk()->assertDownload();
        $this->get('/inventory/stocks?view=location&export=xlsx')->assertOk()->assertDownload();
    }

    public function test_page_does_not_change_stock_or_orders(): void
    {
        $before = [Stock::sum('qty'), SalesOrder::pluck('status', 'id')->all()];

        $this->get('/inventory/stocks');
        $this->get('/inventory/stocks?view=location');

        $this->assertSame($before, [Stock::sum('qty'), SalesOrder::pluck('status', 'id')->all()]);
    }
}
