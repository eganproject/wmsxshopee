<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\AlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class AlertTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $jakarta;

    private Warehouse $bandung;

    private WarehouseLocation $binJkt;

    private ProductVariant $kaos;

    private ProductVariant $topi;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->jakarta, $this->binJkt] = $this->warehouse('JKT01');
        [$this->bandung, $binBdg] = $this->warehouse('BDG01');

        $unit = Unit::where('code', 'PCS')->value('id');
        $this->kaos = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => $unit, 'has_variants' => false, 'is_active' => true])
            ->variants()->create(['sku' => 'KAOS', 'min_stock' => 10, 'is_active' => true]);
        $this->topi = Product::create(['sku' => 'TOPI', 'name' => 'Topi', 'unit_id' => $unit, 'has_variants' => false, 'is_active' => true])
            ->variants()->create(['sku' => 'TOPI', 'is_active' => true]);
        $this->supplier = Supplier::create(['code' => 'SUP01', 'name' => 'CV Sumber Textile', 'is_active' => true]);

        // Jakarta: 8 kaos (di bawah minimum 10), topi habis. Bandung: 30 kaos.
        Stock::create(['product_variant_id' => $this->kaos->id, 'warehouse_id' => $this->jakarta->id, 'warehouse_location_id' => $this->binJkt->id, 'qty' => 8]);
        Stock::create(['product_variant_id' => $this->kaos->id, 'warehouse_id' => $this->bandung->id, 'warehouse_location_id' => $binBdg->id, 'qty' => 30]);
    }

    /** @return array{Warehouse, WarehouseLocation} */
    private function warehouse(string $code): array
    {
        $warehouse = Warehouse::create(['code' => $code, 'name' => "Gudang {$code}", 'is_active' => true]);
        $zone = $warehouse->zones()->create(['code' => 'A', 'name' => 'Zona A', 'type' => 'storage', 'is_active' => true]);
        $rack = $zone->racks()->create(['code' => 'A01', 'levels' => 3, 'is_active' => true]);

        return [$warehouse, $rack->locations()->create(['warehouse_id' => $warehouse->id, 'code' => "{$code}-A01-01", 'type' => 'bin', 'is_active' => true])];
    }

    private function superAdmin(): User
    {
        return User::where('username', 'superadmin')->first();
    }

    private function jakartaStaff(): User
    {
        $user = User::factory()->create()->assignRole('Staff Gudang');
        $user->syncWarehouseAccess(false, [$this->jakarta->id]);

        return $user->fresh();
    }

    /** Pesanan terkirim $qty unit dari Jakarta, $daysAgo hari lalu. */
    private function shipped(ProductVariant $variant, int $qty, int $daysAgo, ?Warehouse $warehouse = null): void
    {
        $order = SalesOrder::create(['number' => 'SO-'.uniqid(), 'status' => 'shipped', 'warehouse_id' => ($warehouse ?? $this->jakarta)->id,
            'order_date' => today()->subDays($daysAgo), 'customer_name' => 'Budi', 'shipped_at' => now()->subDays($daysAgo)]);
        $order->items()->create(['product_variant_id' => $variant->id, 'qty' => $qty, 'price' => 0]);
    }

    public function test_bell_counts_low_stock_pending_orders_and_old_draft_receipts(): void
    {
        $this->actingAs($this->superAdmin());

        // Hanya yang melewati ambang yang dihitung.
        SalesOrder::create(['number' => 'SO-LAMA', 'status' => 'packed', 'warehouse_id' => $this->jakarta->id, 'order_date' => today()->subDays(2), 'customer_name' => 'Sari', 'confirmed_at' => now()->subHours(30)]);
        SalesOrder::create(['number' => 'SO-BARU', 'status' => 'confirmed', 'warehouse_id' => $this->jakarta->id, 'order_date' => today(), 'customer_name' => 'Andi', 'confirmed_at' => now()->subHour()]);
        SalesOrder::create(['number' => 'SO-DRAFT', 'status' => 'draft', 'warehouse_id' => $this->jakarta->id, 'order_date' => today(), 'customer_name' => 'Dodi'])
            ->forceFill(['created_at' => now()->subDays(2)])->save();
        GoodsReceipt::create(['number' => 'GR-LAMA', 'status' => 'draft', 'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->jakarta->id, 'receipt_date' => today()])
            ->forceFill(['created_at' => now()->subDays(3)])->save();
        GoodsReceipt::create(['number' => 'GR-BARU', 'status' => 'draft', 'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->jakarta->id, 'receipt_date' => today()]);

        $groups = app(AlertService::class)->groups($this->superAdmin());

        // Total kaos 38 di semua gudang, jadi belum menipis.
        $this->assertSame(0, $groups['low_stock']['count']);
        $this->assertSame(['SO-LAMA'], $groups['pending_orders']['items']->pluck('number')->all());
        $this->assertSame(['SO-DRAFT'], $groups['draft_orders']['items']->pluck('number')->all());
        $this->assertSame(['GR-LAMA'], $groups['draft_receipts']['items']->pluck('number')->all());

        $this->get('/dashboard')->assertOk()->assertSee('data-testid="notification-count"', false);
        $this->get('/notifications')->assertOk()
            ->assertSee('Pesanan tertunda')->assertSee('SO-LAMA')->assertDontSee('SO-BARU')
            ->assertSee('GR-LAMA')->assertDontSee('GR-BARU');
    }

    public function test_alerts_respect_warehouse_access(): void
    {
        SalesOrder::create(['number' => 'SO-BDG', 'status' => 'confirmed', 'warehouse_id' => $this->bandung->id, 'order_date' => today()->subDays(3), 'customer_name' => 'Sari', 'confirmed_at' => now()->subDays(3)]);

        $staff = $this->jakartaStaff();
        $this->actingAs($staff);
        $groups = app(AlertService::class)->groups($staff);

        // Staff Jakarta hanya melihat 8 kaos Jakarta (di bawah minimum) dan tidak melihat pesanan Bandung.
        $this->assertSame(1, $groups['low_stock']['count']);
        $this->assertSame('KAOS', $groups['low_stock']['items']->first()->sku);
        $this->assertSame(0, $groups['pending_orders']['count']);

        // Staff tanpa reports.view tetap bisa membuka halaman notifikasi, tanpa tautan laporan.
        $this->get('/notifications')->assertOk()->assertSee('Stok menipis')->assertDontSee('SO-BDG')
            ->assertDontSee(route('reports.reorder'));
    }

    public function test_bundle_without_enough_components_is_flagged(): void
    {
        $this->actingAs($this->superAdmin());

        $ok = Bundle::create(['sku' => 'PKT-KAOS', 'name' => 'Paket 2 Kaos', 'price' => 0, 'is_active' => true]);
        $ok->items()->create(['product_variant_id' => $this->kaos->id, 'qty' => 2]);
        $empty = Bundle::create(['sku' => 'PKT-TOPI', 'name' => 'Paket Kaos + Topi', 'price' => 0, 'is_active' => true]);
        $empty->items()->createMany([
            ['product_variant_id' => $this->kaos->id, 'qty' => 1, 'sort_order' => 0],
            ['product_variant_id' => $this->topi->id, 'qty' => 1, 'sort_order' => 1],
        ]);

        $bundles = app(AlertService::class)->unavailableBundles();

        $this->assertSame(['PKT-TOPI'], $bundles->pluck('sku')->all());
        $this->get('/notifications')->assertOk()->assertSee('Paket tidak bisa dipenuhi')->assertSee('PKT-TOPI');
    }

    public function test_reorder_suggestion_uses_minimum_sales_velocity_and_draft_receipts(): void
    {
        $this->actingAs($this->superAdmin());

        // 60 kaos terkirim dalam 30 hari = 2/hari; penjualan 40 hari lalu diabaikan.
        $this->shipped($this->kaos, 45, 3);
        $this->shipped($this->kaos, 15, 20);
        $this->shipped($this->kaos, 100, 40);
        // Topi tanpa stok minimum tapi terjual 6 = 0,2/hari.
        $this->shipped($this->topi, 6, 5);

        // 20 kaos sudah ada di draft penerimaan.
        $receipt = GoodsReceipt::create(['number' => 'GR-DRAFT', 'status' => 'draft', 'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->jakarta->id, 'receipt_date' => today()]);
        $receipt->items()->create(['product_variant_id' => $this->kaos->id, 'warehouse_location_id' => $this->binJkt->id, 'qty_ordered' => 20, 'qty_received' => 0]);

        $response = $this->get(route('reports.reorder', ['warehouse' => $this->jakarta->id]))->assertOk()
            ->assertSee('Saran Pembelian');
        $rows = collect($response->viewData('rows')->items())->keyBy('sku');

        // Kaos Jakarta: target 10 + 2×30 = 70; tersedia 8, draft 20 → saran 42, habis dalam 4 hari.
        $this->assertSame(70, $rows['KAOS']->target);
        $this->assertSame(42, $rows['KAOS']->suggested);
        $this->assertSame(4, $rows['KAOS']->days_left);
        // Topi: target ceil(0,2×30) = 6; tersedia 0 → saran 6.
        $this->assertSame(6, $rows['TOPI']->suggested);
        $this->assertSame(['KAOS', 'TOPI'], $rows->keys()->all());

        // Lebih lama stoknya (cover 60 hari) → saran lebih besar.
        $rows = collect($this->get(route('reports.reorder', ['warehouse' => $this->jakarta->id, 'cover' => 60]))->viewData('rows')->items())->keyBy('sku');
        $this->assertSame(10 + 120 - 8 - 20, $rows['KAOS']->suggested);

        $export = $this->get(route('reports.reorder', ['warehouse' => $this->jakarta->id, 'export' => 'xlsx']))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'test').'.xlsx';
        file_put_contents($path, $export->streamedContent());
        $reader = new Reader;
        $reader->open($path);
        $sheetRows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheetRows[] = $row->toArray();
            }
        }
        $reader->close();

        $this->assertSame('Saran Beli', $sheetRows[0][11]);
        $this->assertSame('KAOS', $sheetRows[1][0]);
        $this->assertEquals(42, $sheetRows[1][11]);
    }

    public function test_reorder_hides_other_warehouses_from_limited_user(): void
    {
        $this->shipped($this->kaos, 300, 2, $this->bandung);

        $staff = $this->jakartaStaff();
        $staff->givePermissionTo('reports.view');
        $this->actingAs($staff);

        // Penjualan Bandung tidak ikut: Jakarta hanya perlu sampai minimum 10 → saran 2.
        $rows = collect($this->get(route('reports.reorder'))->assertOk()->viewData('rows')->items())->keyBy('sku');
        $this->assertSame(0, $rows['KAOS']->sold);
        $this->assertSame(2, $rows['KAOS']->suggested);
    }
}
