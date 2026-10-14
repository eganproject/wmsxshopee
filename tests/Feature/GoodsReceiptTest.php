<?php

namespace Tests\Feature;

use App\Models\DamagedStock;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockLedger;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GoodsReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $warehouse;

    private WarehouseLocation $binA;

    private WarehouseLocation $binB;

    private ProductVariant $variant;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::create(['code' => 'JKT01', 'name' => 'Gudang Jakarta', 'is_active' => true]);
        $zone = $this->warehouse->zones()->create(['code' => 'A', 'name' => 'Penyimpanan A', 'type' => 'storage', 'is_active' => true]);
        $rack = $zone->racks()->create(['code' => 'A01', 'levels' => 3, 'is_active' => true]);
        $this->binA = $rack->locations()->create(['warehouse_id' => $this->warehouse->id, 'code' => 'A01-01-01', 'type' => 'bin', 'is_active' => true]);
        $this->binB = $rack->locations()->create(['warehouse_id' => $this->warehouse->id, 'code' => 'A01-01-02', 'type' => 'bin', 'is_active' => true]);

        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => true, 'is_active' => true]);
        $this->variant = $product->variants()->create(['sku' => 'KAOS-M', 'name' => 'M', 'barcode' => '8991001', 'is_active' => true]);

        $this->supplier = Supplier::create(['code' => 'SUP01', 'name' => 'CV Sumber Textile', 'is_active' => true]);
    }

    private function superAdmin(): User
    {
        return User::where('username', 'superadmin')->first();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'supplier_reference' => 'SJ-0912',
            'items' => [
                ['product_variant_id' => $this->variant->id, 'warehouse_location_id' => $this->binA->id, 'qty_ordered' => 12, 'qty_received' => 10, 'qty_damaged' => 1],
                ['product_variant_id' => $this->variant->id, 'warehouse_location_id' => $this->binB->id, 'qty_received' => 5],
            ],
            ...$overrides,
        ];
    }

    private function qty(WarehouseLocation $location): int
    {
        return (int) Stock::where('product_variant_id', $this->variant->id)->where('warehouse_location_id', $location->id)->value('qty');
    }

    public function test_draft_does_not_change_stock_and_gets_short_number(): void
    {
        $this->actingAs($this->superAdmin());
        $this->get('/inventory/receipts/create')->assertOk();

        $this->post('/inventory/receipts', $this->payload(['action' => 'draft']))->assertSessionHasNoErrors();
        $this->post('/inventory/receipts', $this->payload(['action' => 'draft']))->assertSessionHasNoErrors();

        $numbers = GoodsReceipt::orderBy('id')->pluck('number')->all();
        $prefix = 'GR'.now()->format('ym').'-';
        $this->assertSame([$prefix.'001', $prefix.'002'], $numbers);
        $this->assertLessThanOrEqual(10, strlen($numbers[0]));

        $receipt = GoodsReceipt::first();
        $this->assertTrue($receipt->isDraft());
        $this->assertCount(2, $receipt->items);
        $this->assertSame(0, $this->qty($this->binA));
        $this->assertSame(0, StockLedger::count());

        $this->get('/inventory/receipts')->assertOk()->assertSee($receipt->number)->assertSee('CV Sumber Textile');
        $this->get("/inventory/receipts/{$receipt->id}")->assertOk()->assertSee('SJ-0912')->assertSee('-1');
        $this->get("/inventory/receipts/{$receipt->id}/edit")->assertOk();
    }

    public function test_receiving_posts_good_qty_to_stock_card_and_locks_document(): void
    {
        $this->actingAs($this->superAdmin());
        $this->post('/inventory/receipts', $this->payload(['action' => 'draft']));
        $receipt = GoodsReceipt::first();

        $this->post("/inventory/receipts/{$receipt->id}/receive")->assertRedirect("/inventory/receipts/{$receipt->id}");

        $receipt->refresh();
        $this->assertSame('received', $receipt->status);
        $this->assertNotNull($receipt->received_at);
        $this->assertSame(10, $this->qty($this->binA)); // yang rusak tidak masuk stok
        $this->assertSame(5, $this->qty($this->binB));
        $this->assertSame('in', $receipt->movement->type);
        $this->assertSame($receipt->number, $receipt->movement->reference);
        $this->assertSame(15, (int) StockLedger::where('stock_movement_id', $receipt->stock_movement_id)->sum('qty_in'));

        // Terkunci: tidak bisa diterima ulang, diubah atau dihapus.
        $this->post("/inventory/receipts/{$receipt->id}/receive")->assertSessionHasErrors('receipt');
        $this->assertSame(10, $this->qty($this->binA));
        $this->put("/inventory/receipts/{$receipt->id}", $this->payload())->assertForbidden();
        $this->get("/inventory/receipts/{$receipt->id}/edit")->assertRedirect("/inventory/receipts/{$receipt->id}");
        $this->delete("/inventory/receipts/{$receipt->id}")->assertSessionHas('error');
        $this->assertModelExists($receipt);

        $this->get("/inventory/stock-card?variant={$this->variant->id}")->assertOk()->assertSee($receipt->movement->number);
    }

    public function test_save_and_receive_in_one_step_with_attachment(): void
    {
        Storage::fake('public');
        $this->actingAs($this->superAdmin());

        $this->post('/inventory/receipts', $this->payload([
            'action' => 'receive',
            'attachment' => UploadedFile::fake()->image('surat-jalan.jpg'),
        ]))->assertSessionHasNoErrors();

        $receipt = GoodsReceipt::first();
        $this->assertSame('received', $receipt->status);
        Storage::disk('public')->assertExists($receipt->attachment_path);
        $this->assertSame(15, $this->qty($this->binA) + $this->qty($this->binB));
    }

    public function test_draft_can_be_edited_and_deleted(): void
    {
        $this->actingAs($this->superAdmin());
        $this->post('/inventory/receipts', $this->payload(['action' => 'draft']));
        $receipt = GoodsReceipt::first();

        $this->put("/inventory/receipts/{$receipt->id}", $this->payload([
            'supplier_reference' => 'SJ-0913',
            'items' => [['product_variant_id' => $this->variant->id, 'warehouse_location_id' => $this->binB->id, 'qty_received' => 7]],
        ]))->assertSessionHasNoErrors();

        $receipt->refresh();
        $this->assertSame('SJ-0913', $receipt->supplier_reference);
        $this->assertCount(1, $receipt->items);
        $this->assertSame(7, $receipt->items->first()->qty_received);

        $this->delete("/inventory/receipts/{$receipt->id}")->assertRedirect(route('inventory.receipts.index'));
        $this->assertModelMissing($receipt);
    }

    public function test_validation_rules(): void
    {
        $this->actingAs($this->superAdmin());

        $otherWarehouse = Warehouse::create(['code' => 'BDG01', 'name' => 'Gudang Bandung', 'is_active' => true]);
        $this->post('/inventory/receipts', $this->payload(['warehouse_id' => $otherWarehouse->id]))
            ->assertSessionHasErrors('items.0.warehouse_location_id');

        $this->post('/inventory/receipts', $this->payload(['items' => [
            ['product_variant_id' => $this->variant->id, 'warehouse_location_id' => $this->binA->id, 'qty_received' => 0],
        ]]))->assertSessionHasErrors('items.0.qty_received');

        $this->post('/inventory/receipts', $this->payload(['receipt_date' => now()->addDay()->toDateString()]))
            ->assertSessionHasErrors('receipt_date');

        $this->supplier->update(['is_active' => false]);
        $this->post('/inventory/receipts', $this->payload())->assertSessionHasErrors('supplier_id');

        $this->assertSame(0, GoodsReceipt::count());
    }

    public function test_all_damaged_is_received_into_damaged_stock_only(): void
    {
        $this->actingAs($this->superAdmin());

        $this->post('/inventory/receipts', $this->payload([
            'action' => 'receive',
            'items' => [['product_variant_id' => $this->variant->id, 'warehouse_location_id' => $this->binA->id, 'qty_received' => 0, 'qty_damaged' => 3]],
        ]))->assertSessionHasNoErrors();

        // Tidak ada stok jual yang bertambah; 3 rusak masuk Stok Rusak (siap diretur ke supplier).
        $receipt = GoodsReceipt::first();
        $this->assertSame('received', $receipt->status);
        $this->assertNull($receipt->stock_movement_id);
        $this->assertSame(0, StockLedger::count());
        $this->assertSame(3, (int) DamagedStock::where('product_variant_id', $this->variant->id)->value('qty'));
    }

    public function test_permissions(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo('receipts.view', 'receipts.create');

        $this->actingAs($viewer);
        $this->get('/inventory/receipts')->assertOk();
        $this->post('/inventory/receipts', $this->payload(['action' => 'draft']))->assertSessionHasNoErrors();

        $receipt = GoodsReceipt::first();
        $this->post("/inventory/receipts/{$receipt->id}/receive")->assertForbidden();
        $this->post('/inventory/receipts', $this->payload(['action' => 'receive']))->assertForbidden();
        $this->delete("/inventory/receipts/{$receipt->id}")->assertForbidden();
        $this->assertTrue($receipt->fresh()->isDraft());
    }

    public function test_bin_location_is_optional(): void
    {
        $this->actingAs($this->superAdmin());

        $this->post('/inventory/receipts', $this->payload([
            'action' => 'receive',
            'items' => [['product_variant_id' => $this->variant->id, 'warehouse_location_id' => '', 'qty_received' => 8]],
        ]))->assertSessionHasNoErrors();

        $receipt = GoodsReceipt::first();
        $this->assertSame('received', $receipt->status);
        $this->assertNull($receipt->items->first()->warehouse_location_id);
        $this->assertSame(8, (int) Stock::where('product_variant_id', $this->variant->id)->whereNull('warehouse_location_id')->value('qty'));
        $this->get("/inventory/receipts/{$receipt->id}")->assertOk()->assertSee('Tanpa bin');
    }
}
