<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Nomor dokumen tetap berurutan setelah melewati batas digit (9999 / 999). */
class DocumentNumberTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_stock_movement_number_continues_after_9999(): void
    {
        $warehouse = Warehouse::create(['code' => 'GD01', 'name' => 'Gudang', 'is_active' => true]);
        $variant = Product::create(['sku' => 'KAOS', 'name' => 'Kaos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => false, 'is_active' => true])
            ->variants()->create(['sku' => 'KAOS', 'is_active' => true]);
        $prefix = 'MSK-'.now()->format('Ym').'-';
        StockMovement::create(['number' => $prefix.'9999', 'type' => 'in', 'warehouse_id' => $warehouse->id, 'movement_date' => now()->toDateString()]);

        $record = fn () => app(StockService::class)->record(
            ['type' => 'in', 'warehouse_id' => $warehouse->id, 'movement_date' => now()->toDateString()],
            [['product_variant_id' => $variant->id, 'to_location_id' => null, 'qty' => 1]],
        );

        $this->assertSame($prefix.'10000', $record()->number);
        $this->assertSame($prefix.'10001', $record()->number);
    }

    public function test_sales_order_number_continues_after_999(): void
    {
        $warehouse = Warehouse::create(['code' => 'GD01', 'name' => 'Gudang', 'is_active' => true]);
        $prefix = SalesOrder::PREFIX.now()->format('ym').'-';

        foreach (['998', '999', '1000'] as $sequence) {
            SalesOrder::create(['number' => $prefix.$sequence, 'status' => 'draft', 'warehouse_id' => $warehouse->id, 'order_date' => now()->toDateString(), 'customer_name' => 'Budi']);
        }

        $this->assertSame($prefix.'1001', SalesOrder::nextNumber());
    }
}
