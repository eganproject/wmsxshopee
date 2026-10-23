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
use App\Services\SalesOrderService;
use App\Services\StockCountService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Stok yang sudah di-picking (per bin) atau dicadangkan pesanan (per gudang)
 * tidak boleh dipakai transfer lokasi, Barang Keluar, Pindah ke Rusak, atau opname.
 */
class OrderStockProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $warehouse;

    private WarehouseLocation $binA;

    private WarehouseLocation $binB;

    private ProductVariant $kaos;

    private SalesOrderService $orders;

    private StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::create(['code' => 'GD01', 'name' => 'Gudang', 'is_active' => true]);
        $rack = $this->warehouse->zones()->create(['code' => 'A', 'name' => 'Picking', 'type' => 'picking', 'is_active' => true])
            ->racks()->create(['code' => 'A01', 'levels' => 1, 'is_active' => true]);
        $this->binA = $rack->locations()->create(['warehouse_id' => $this->warehouse->id, 'code' => 'A01-01-01', 'type' => 'bin', 'is_active' => true]);
        $this->binB = $rack->locations()->create(['warehouse_id' => $this->warehouse->id, 'code' => 'A01-01-02', 'type' => 'bin', 'is_active' => true]);
        $this->kaos = Product::create(['sku' => 'KAOS', 'name' => 'Kaos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => false, 'is_active' => true])
            ->variants()->create(['sku' => 'KAOS', 'is_active' => true]);

        $this->orders = app(SalesOrderService::class);
        $this->stock = app(StockService::class);
        $this->stock->record(['type' => 'in', 'warehouse_id' => $this->warehouse->id, 'movement_date' => now()->toDateString()],
            [['product_variant_id' => $this->kaos->id, 'to_location_id' => $this->binA->id, 'qty' => 8]]);

        $this->actingAs(User::where('username', 'superadmin')->first());
    }

    private function order(int $qty): SalesOrder
    {
        $order = SalesOrder::create(['number' => SalesOrder::nextNumber(), 'status' => 'draft', 'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(), 'customer_name' => 'Budi', 'delivery_type' => 'courier']);
        $order->items()->create(['product_variant_id' => $this->kaos->id, 'qty' => $qty, 'price' => 0]);
        $this->orders->confirm($order);

        return $order->fresh();
    }

    private function picked(int $qty): SalesOrder
    {
        $order = $this->order($qty);
        $this->orders->pick($order, [$order->items()->first()->id => [['warehouse_location_id' => $this->binA->id, 'qty' => $qty]]]);

        return $order->fresh();
    }

    private function move(string $type, array $row): void
    {
        $this->stock->record(['type' => $type, 'warehouse_id' => $this->warehouse->id, 'movement_date' => now()->toDateString()],
            [['product_variant_id' => $this->kaos->id, ...$row]]);
    }

    private function rejected(callable $action, string $contains): void
    {
        try {
            $action();
            $this->fail('Dokumen seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($contains, collect($e->errors())->flatten()->implode(' '));
        }
    }

    private function binQty(WarehouseLocation $bin): int
    {
        return (int) Stock::where('warehouse_location_id', $bin->id)->value('qty');
    }

    public function test_bin_transfer_cannot_take_picked_stock_but_can_move_the_rest(): void
    {
        $order = $this->picked(5);

        $this->rejected(fn () => $this->move('transfer', ['from_location_id' => $this->binA->id, 'to_location_id' => $this->binB->id, 'qty' => 4]),
            "5 pcs sudah diambil untuk pesanan {$order->number}, jadi yang bisa dipakai hanya 3");

        $this->move('transfer', ['from_location_id' => $this->binA->id, 'to_location_id' => $this->binB->id, 'qty' => 3]);
        $this->assertSame([5, 3], [$this->binQty($this->binA), $this->binQty($this->binB)]);

        // Pesanan tetap bisa dikirim dari bin hasil picking-nya.
        $this->orders->pack($order);
        $this->orders->ship($order, ['tracking_number' => 'R1'], null);
        $this->assertSame('shipped', $order->fresh()->status);
        $this->assertSame(0, $this->binQty($this->binA));
    }

    public function test_manual_out_and_damage_cannot_use_reserved_stock(): void
    {
        $this->order(6);

        $this->rejected(fn () => $this->move('out', ['from_location_id' => $this->binA->id, 'qty' => 3]), 'dicadangkan 6 pcs');
        $this->rejected(fn () => $this->move('damage', ['from_location_id' => $this->binA->id, 'qty' => 3]), 'yang bebas dipakai hanya 2');

        $this->move('out', ['from_location_id' => $this->binA->id, 'qty' => 2]);
        $this->assertSame(6, $this->binQty($this->binA));
    }

    public function test_stock_count_cannot_post_below_picked_stock(): void
    {
        $order = $this->picked(5);
        $counts = app(StockCountService::class);
        $count = $counts->create(['warehouse_id' => $this->warehouse->id, 'scope' => 'all', 'count_date' => now()->toDateString()]);
        $line = $count->items()->first();

        // Barang yang sudah diambil picker tidak ada di rak, jadi hitungannya lebih kecil.
        $counts->save($count, [['id' => $line->id, 'product_variant_id' => $this->kaos->id, 'counted_qty' => 3]]);
        $this->rejected(fn () => $counts->post($count->fresh(), null), "sudah diambil untuk pesanan {$order->number}");
        $this->assertSame(8, $this->binQty($this->binA));
        $this->assertTrue($count->fresh()->isDraft());
    }

    public function test_cancelled_or_reopened_orders_release_the_protection(): void
    {
        $order = $this->picked(5);
        $this->orders->reopen($order);

        $this->move('transfer', ['from_location_id' => $this->binA->id, 'to_location_id' => $this->binB->id, 'qty' => 8]);
        $this->assertSame(8, $this->binQty($this->binB));
    }
}
