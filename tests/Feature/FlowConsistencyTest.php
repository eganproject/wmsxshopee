<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\DamagedDocument;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Stock;
use App\Models\StockAdjustment;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\SalesOrderService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Temuan uji alur lengkap (24 Okt 2026): hapus master data yang sudah dipakai,
 * resi ganda, penyesuaian di bin nonaktif, dan centang "Pilih semua" pesanan.
 */
class FlowConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private User $admin;

    private Warehouse $warehouse;

    private WarehouseLocation $bin;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::where('username', 'superadmin')->first();
        $this->warehouse = Warehouse::create(['code' => 'JKT', 'name' => 'Gudang Jakarta', 'is_active' => true]);
        $zone = $this->warehouse->zones()->create(['code' => 'A', 'name' => 'A', 'type' => 'storage', 'is_active' => true]);
        $rack = $zone->racks()->create(['code' => 'R1', 'levels' => 1, 'is_active' => true]);
        $this->bin = $rack->locations()->create(['warehouse_id' => $this->warehouse->id, 'code' => 'R1-01-01', 'type' => 'bin', 'is_active' => true]);
        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'is_active' => true]);
        $this->variant = $product->variants()->create(['sku' => 'KAOS', 'is_active' => true]);

        app(StockService::class)->record(['type' => 'in', 'warehouse_id' => $this->warehouse->id, 'movement_date' => now()->toDateString()], [
            ['product_variant_id' => $this->variant->id, 'to_location_id' => $this->bin->id, 'qty' => 10],
        ]);
    }

    /** Pesanan manual sampai Sudah Dikemas. */
    private function packedOrder(?Courier $courier = null): SalesOrder
    {
        $order = SalesOrder::create([
            'number' => SalesOrder::nextNumber(), 'warehouse_id' => $this->warehouse->id, 'order_date' => now(), 'status' => 'draft',
            'customer_name' => 'Andi', 'delivery_type' => 'courier', 'courier_id' => $courier?->id,
        ]);
        $order->items()->create(['product_variant_id' => $this->variant->id, 'qty' => 1, 'price' => 0]);
        $service = app(SalesOrderService::class);
        $service->confirm($order);
        $service->pickSuggested($order);
        $service->pack($order);

        return $order->refresh();
    }

    public function test_courier_used_by_orders_cannot_be_deleted(): void
    {
        $courier = Courier::where('code', 'JNE')->first();
        $order = $this->packedOrder($courier);

        $this->actingAs($this->admin)->from('/master/couriers')->delete("/master/couriers/{$courier->id}")
            ->assertRedirect('/master/couriers')->assertSessionHas('error');

        $this->assertModelExists($courier);
        $this->assertSame($courier->id, $order->fresh()->courier_id);

        $unused = Courier::where('code', 'POS')->first();
        $this->delete("/master/couriers/{$unused->id}")->assertSessionHas('success');
        $this->assertModelMissing($unused);
    }

    public function test_warehouse_with_unbinned_stock_cannot_be_deleted(): void
    {
        $other = Warehouse::create(['code' => 'TTP', 'name' => 'Gudang Titip', 'is_active' => true]);
        app(StockService::class)->record(['type' => 'in', 'warehouse_id' => $other->id, 'movement_date' => now()->toDateString()], [
            ['product_variant_id' => $this->variant->id, 'to_location_id' => null, 'qty' => 5],
        ]);

        $this->actingAs($this->admin)->from('/master/warehouses')->delete("/master/warehouses/{$other->id}")
            ->assertRedirect('/master/warehouses')->assertSessionHas('error');

        $this->assertModelExists($other);
        $this->assertSame(5, (int) Stock::where('warehouse_id', $other->id)->sum('qty'));
    }

    public function test_supplier_used_only_by_damaged_return_cannot_be_deleted(): void
    {
        $supplier = Supplier::create(['code' => 'SC', 'name' => 'Service Center', 'is_active' => true]);
        DamagedDocument::create(['number' => 'RTS-1', 'type' => 'supplier_return', 'status' => 'draft', 'warehouse_id' => $this->warehouse->id, 'document_date' => now(), 'supplier_id' => $supplier->id]);

        $this->actingAs($this->admin)->from('/master/suppliers')->delete("/master/suppliers/{$supplier->id}")
            ->assertRedirect('/master/suppliers')->assertSessionHas('error');

        $this->assertModelExists($supplier);
    }

    public function test_deleting_master_data_still_referenced_shows_message_instead_of_error_page(): void
    {
        // Produk tanpa riwayat stok, tapi sudah ada di draft pesanan.
        $product = Product::create(['sku' => 'TOPI', 'name' => 'Topi', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'is_active' => true]);
        $variant = $product->variants()->create(['sku' => 'TOPI', 'is_active' => true]);
        $order = SalesOrder::create(['number' => 'SO-X', 'warehouse_id' => $this->warehouse->id, 'order_date' => now(), 'status' => 'draft', 'customer_name' => 'X']);
        $order->items()->create(['product_variant_id' => $variant->id, 'qty' => 1, 'price' => 0]);

        $this->actingAs($this->admin)->from('/master/products')->delete("/master/products/{$product->id}")
            ->assertRedirect('/master/products')->assertSessionHas('error');

        $this->assertModelExists($product);
    }

    public function test_tracking_number_cannot_be_used_by_two_orders(): void
    {
        $first = $this->packedOrder();
        $second = $this->packedOrder();
        $this->actingAs($this->admin);

        $this->post("/sales/orders/{$first->id}/shipment", ['shipping_method' => 'dropoff', 'dropoff_point' => 'Agen', 'tracking_number' => 'RESI-1'])
            ->assertSessionHasNoErrors();
        $this->post("/sales/orders/{$second->id}/shipment", ['shipping_method' => 'dropoff', 'dropoff_point' => 'Agen', 'tracking_number' => ' RESI-1 '])
            ->assertSessionHasErrors('tracking_number');
        $this->assertNull($second->fresh()->tracking_number);

        // Tombol Kirim manual juga menolak resi yang sudah dipakai.
        $this->post("/sales/orders/{$second->id}/ship", ['courier_id' => Courier::where('code', 'JNE')->value('id'), 'tracking_number' => 'RESI-1'])
            ->assertSessionHasErrors('tracking_number');
        $this->assertSame('packed', $second->fresh()->status);

        // Mengisi ulang resi yang sama di pesanannya sendiri tetap boleh.
        $this->post("/sales/orders/{$first->id}/shipment", ['shipping_method' => 'dropoff', 'dropoff_point' => 'Agen', 'tracking_number' => 'RESI-1'])
            ->assertSessionHasNoErrors();

        // Resi dari pesanan yang dibatalkan boleh dipakai lagi.
        app(SalesOrderService::class)->cancel($first, 'batal');
        $this->post("/sales/orders/{$second->id}/shipment", ['shipping_method' => 'dropoff', 'dropoff_point' => 'Agen', 'tracking_number' => 'RESI-1'])
            ->assertSessionHasNoErrors();
    }

    public function test_negative_adjustment_can_empty_an_inactive_bin(): void
    {
        $this->bin->update(['is_active' => false]);
        $this->actingAs($this->admin);

        $adjust = fn (int $qty) => $this->post('/inventory/adjustments', ['action' => 'submit', 'warehouse_id' => $this->warehouse->id, 'adjustment_date' => now()->toDateString(), 'reason' => 'input_error', 'items' => [
            ['product_variant_id' => $this->variant->id, 'warehouse_location_id' => $this->bin->id, 'qty' => $qty],
        ]]);

        $adjust(2)->assertSessionHasErrors('items.0.warehouse_location_id');
        $adjust(-10)->assertSessionHasNoErrors();
        $this->post('/inventory/adjustments/'.StockAdjustment::latest('id')->value('id').'/approve')->assertSessionHasNoErrors();

        $this->assertSame(0, (int) Stock::where('warehouse_location_id', $this->bin->id)->value('qty'));
    }

    public function test_order_list_select_all_uses_valid_javascript(): void
    {
        $this->packedOrder();

        $this->actingAs($this->admin)->get('/sales/orders')->assertOk()
            ->assertDontSee(')->length', false);
    }
}
