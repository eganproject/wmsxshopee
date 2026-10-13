<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $warehouse;

    private WarehouseLocation $bin;

    private ProductVariant $variant;

    private ProductVariant $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::create(['code' => 'JKT01', 'name' => 'Gudang Jakarta', 'is_active' => true]);
        $zone = $this->warehouse->zones()->create(['code' => 'A', 'name' => 'Penyimpanan A', 'type' => 'storage', 'is_active' => true]);
        $rack = $zone->racks()->create(['code' => 'A01', 'levels' => 3, 'is_active' => true]);
        $this->bin = $rack->locations()->create(['warehouse_id' => $this->warehouse->id, 'code' => 'A01-01-01', 'type' => 'bin', 'is_active' => true]);

        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => true, 'is_active' => true]);
        $this->variant = $product->variants()->create(['sku' => 'KAOS-M', 'name' => 'M', 'min_stock' => 10, 'is_active' => true]);
        $this->other = $product->variants()->create(['sku' => 'KAOS-L', 'name' => 'L', 'min_stock' => 2, 'is_active' => true]);

        $stock = app(StockService::class);
        $header = fn ($type, $date) => ['type' => $type, 'warehouse_id' => $this->warehouse->id, 'movement_date' => $date];

        // Bulan lalu: KAOS-M 12 masuk bin. Bulan ini: 5 keluar, -1 penyesuaian, transfer 3 ke tanpa bin.
        $stock->record($header('in', now()->subMonth()->startOfMonth()->toDateString()), [
            ['product_variant_id' => $this->variant->id, 'to_location_id' => $this->bin->id, 'qty' => 12],
            ['product_variant_id' => $this->other->id, 'to_location_id' => null, 'qty' => 6],
        ]);
        $stock->record($header('out', now()->toDateString()), [['product_variant_id' => $this->variant->id, 'from_location_id' => $this->bin->id, 'qty' => 5]]);
        $stock->record($header('adjustment', now()->toDateString()), [['product_variant_id' => $this->variant->id, 'to_location_id' => $this->bin->id, 'qty' => -1]]);
        $stock->record($header('transfer', now()->toDateString()), [['product_variant_id' => $this->variant->id, 'from_location_id' => $this->bin->id, 'to_location_id' => null, 'qty' => 3]]);

        $this->actingAs(User::where('username', 'superadmin')->first());
    }

    /** @return array<int, array<int, mixed>> */
    private function readXlsx(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'test').'.xlsx';
        file_put_contents($path, $content);
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();

        return $rows;
    }

    public function test_stock_per_location_includes_unbinned_and_exports(): void
    {
        $this->get(route('reports.stock-locations'))->assertOk()
            ->assertSee('A01-01-01')->assertSee('Tanpa bin')->assertSee('KAOS-L');

        $this->get(route('reports.stock-locations', ['zone' => 'none']))->assertOk()
            ->assertSee('KAOS-L')->assertDontSee('A01-01-01');

        $response = $this->get(route('reports.stock-locations', ['export' => 'xlsx']))->assertOk();
        $rows = $this->readXlsx($response->streamedContent());
        $this->assertSame(['Gudang', 'Zona', 'Rak', 'Lokasi', 'SKU', 'Produk', 'Varian', 'Satuan', 'Stok'], $rows[0]);
        $this->assertSame(['JKT01', 'A', 'A01', 'A01-01-01', 'KAOS-M', 'Kaos Polos', 'M', 'PCS', 3], $rows[1]);
        $this->assertCount(4, $rows);
    }

    public function test_min_stock_uses_available_quantity(): void
    {
        // KAOS-M fisik 6 (< 10) → di bawah minimum. KAOS-L fisik 6, cadangan 3 → tersedia 3 (> 2) → aman.
        $order = SalesOrder::create(['number' => 'SO-T1', 'status' => 'confirmed', 'warehouse_id' => $this->warehouse->id, 'order_date' => now(), 'customer_name' => 'Budi']);
        $order->items()->create(['product_variant_id' => $this->other->id, 'qty' => 3, 'price' => 0]);

        $this->get(route('reports.min-stock'))->assertOk()->assertSee('KAOS-M')->assertDontSee('KAOS-L');
        $this->get(route('reports.min-stock', ['show' => 'all']))->assertOk()->assertSee('KAOS-L');

        // Cadangan 5 → tersedia 1 (<= 2) → ikut muncul.
        $order->items()->first()->update(['qty' => 5]);
        $rows = $this->readXlsx($this->get(route('reports.min-stock', ['export' => 'xlsx']))->streamedContent());
        $this->assertSame(['KAOS-M', 'Kaos Polos', 'M', 'PCS', 10, 6, 0, 6, 4], $rows[1]);
        $this->assertSame(['KAOS-L', 'Kaos Polos', 'L', 'PCS', 2, 6, 5, 1, 1], $rows[2]);
    }

    public function test_movement_report_summarises_period(): void
    {
        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();

        $this->get(route('reports.movements', ['from' => $from, 'to' => $to]))->assertOk()
            ->assertSee('KAOS-M')->assertDontSee('KAOS-L');

        $rows = $this->readXlsx($this->get(route('reports.movements', ['from' => $from, 'to' => $to, 'export' => 'xlsx']))->streamedContent());
        $this->assertSame(['SKU', 'Produk', 'Varian', 'Satuan', 'Saldo Awal', 'Masuk', 'Keluar', 'Penyesuaian', 'Saldo Akhir'], $rows[0]);
        $this->assertSame(['KAOS-M', 'Kaos Polos', 'M', 'PCS', 12, 0, 5, -1, 6], $rows[1]);
        $this->assertCount(2, $rows);

        // Termasuk yang tidak bergerak & rentang bulan lalu.
        $this->get(route('reports.movements', ['from' => $from, 'to' => $to, 'all' => 1]))->assertOk()->assertSee('KAOS-L');
        $last = $this->readXlsx($this->get(route('reports.movements', [
            'from' => now()->subMonth()->startOfMonth()->toDateString(), 'to' => now()->subMonth()->endOfMonth()->toDateString(), 'export' => 'xlsx',
        ]))->streamedContent());
        $this->assertSame(['KAOS-L', 0, 6, 0, 0, 6], [$last[1][0], ...array_slice($last[1], 4)]);
        $this->assertSame(['KAOS-M', 0, 12, 0, 0, 12], [$last[2][0], ...array_slice($last[2], 4)]);
    }

    public function test_reports_need_permission(): void
    {
        $staff = User::create(['name' => 'Staff', 'username' => 'staff', 'email' => 'staff@wms.local', 'password' => 'password', 'is_active' => true]);
        $staff->assignRole('Staff Gudang');

        $this->actingAs($staff)->get(route('reports.stock-locations'))->assertForbidden();
    }
}
