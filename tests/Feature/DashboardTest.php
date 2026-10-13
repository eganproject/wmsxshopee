<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $jakarta;

    private Warehouse $bandung;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jakarta = Warehouse::create(['code' => 'JKT01', 'name' => 'Gudang Jakarta', 'is_active' => true]);
        $this->bandung = Warehouse::create(['code' => 'BDG01', 'name' => 'Gudang Bandung', 'is_active' => true]);

        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => true, 'is_active' => true]);
        $this->variant = $product->variants()->create(['sku' => 'KAOS-M', 'name' => 'M', 'min_stock' => 10, 'is_active' => true]);

        // Jakarta: masuk 20 kemarin, keluar 4 hari ini. Bandung: masuk 5 hari ini.
        $stock = app(StockService::class);
        $stock->record(['type' => 'in', 'warehouse_id' => $this->jakarta->id, 'movement_date' => today()->subDay()->toDateString()],
            [['product_variant_id' => $this->variant->id, 'to_location_id' => null, 'qty' => 20]]);
        $stock->record(['type' => 'out', 'warehouse_id' => $this->jakarta->id, 'movement_date' => today()->toDateString()],
            [['product_variant_id' => $this->variant->id, 'from_location_id' => null, 'qty' => 4]]);
        $stock->record(['type' => 'in', 'warehouse_id' => $this->bandung->id, 'movement_date' => today()->toDateString()],
            [['product_variant_id' => $this->variant->id, 'to_location_id' => null, 'qty' => 5]]);

        SalesOrder::create(['number' => 'SO-T1', 'status' => 'confirmed', 'warehouse_id' => $this->jakarta->id, 'order_date' => today(), 'customer_name' => 'Budi']);
        SalesOrder::create(['number' => 'SO-T2', 'status' => 'packed', 'warehouse_id' => $this->bandung->id, 'order_date' => today(), 'customer_name' => 'Sari']);
        SalesOrder::create(['number' => 'SO-T3', 'status' => 'shipped', 'warehouse_id' => $this->jakarta->id, 'order_date' => today(), 'customer_name' => 'Andi', 'shipped_at' => now()]);
    }

    public function test_dashboard_summarises_orders_stock_and_movements(): void
    {
        $this->actingAs(User::where('username', 'superadmin')->first());

        $response = $this->get('/dashboard')->assertOk()
            ->assertSee('Pesanan per Status')->assertSee('Pergerakan Barang 14 Hari')
            ->assertSee('Penerimaan Hari Ini')->assertSee('Aktivitas Terbaru');

        $orders = $response->viewData('orders');
        $this->assertSame(2, $orders['open']);
        $this->assertSame(1, $orders['shipped_today']);
        $this->assertSame(1, $orders['counts']['packed']);

        // Semua gudang: fisik 21 >= 10, jadi aman.
        $this->assertSame(0, $response->viewData('lowStock')['count']);

        $chart = $response->viewData('chart');
        $this->assertCount(14, $chart['days']);
        $this->assertSame(25, $chart['in']);
        $this->assertSame(4, $chart['out']);
        $this->assertSame(['in' => 5, 'out' => 4], array_intersect_key($chart['days']->last(), ['in' => 0, 'out' => 0]));
    }

    public function test_warehouse_filter_narrows_every_panel(): void
    {
        $this->actingAs(User::where('username', 'superadmin')->first());

        $response = $this->get('/dashboard?warehouse='.$this->bandung->id)->assertOk();

        $this->assertSame(1, $response->viewData('orders')['open']);
        $this->assertSame(0, $response->viewData('orders')['shipped_today']);
        $this->assertSame(5, $response->viewData('chart')['in']);
        $this->assertSame(0, $response->viewData('chart')['out']);

        // Bandung hanya punya 5 (< 10), pesanan packed tanpa item tidak mencadangkan apa pun.
        $lowStock = $response->viewData('lowStock');
        $this->assertSame(1, $lowStock['count']);
        $this->assertSame('KAOS-M', $lowStock['rows']->first()->sku);
        $this->assertSame(5, (int) $lowStock['rows']->first()->available);
        $response->assertSee('KAOS-M');
    }

    public function test_panels_follow_permissions(): void
    {
        $role = Role::create(['name' => 'Penjualan', 'guard_name' => 'web']);
        $role->syncPermissions(['dashboard.view', 'sales.view']);
        $user = User::factory()->create();
        $user->assignRole($role);

        $response = $this->actingAs($user)->get('/dashboard')->assertOk()
            ->assertSee('Pesanan per Status')
            ->assertDontSee('Stok Menipis')->assertDontSee('Pergerakan Barang')
            ->assertDontSee('Penerimaan Hari Ini')->assertDontSee('Aktivitas Terbaru');

        $this->assertNull($response->viewData('lowStock'));
        $this->assertNull($response->viewData('chart'));
        $this->assertNull($response->viewData('receipts'));
        $this->assertNull($response->viewData('activities'));
    }
}
