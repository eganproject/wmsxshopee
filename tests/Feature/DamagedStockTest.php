<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\DamagedDocument;
use App\Models\DamagedStock;
use App\Models\DamagedStockLedger;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\StockIntegrity;
use App\Services\StockService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DamagedStockTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $warehouse;

    private Warehouse $bandung;

    private WarehouseLocation $bin;

    private WarehouseLocation $bandungBin;

    private ProductVariant $variant;

    private ProductVariant $part;

    private ProductVariant $bundle;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->warehouse, $this->bin] = $this->warehouse('JKT01', 'Gudang Jakarta');
        [$this->bandung, $this->bandungBin] = $this->warehouse('BDG01', 'Gudang Bandung');

        $unit = Unit::where('code', 'PCS')->value('id');
        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => $unit, 'has_variants' => true, 'is_active' => true]);
        $this->variant = $product->variants()->create(['sku' => 'KAOS-M', 'name' => 'M', 'barcode' => '8991001', 'is_active' => true]);
        $this->part = Product::create(['sku' => 'SABLON', 'name' => 'Sablon Tempel', 'unit_id' => $unit, 'is_active' => true])
            ->variants()->create(['sku' => 'SABLON', 'is_active' => true]);
        $this->bundle = Product::create(['sku' => 'KAOS-SBL', 'name' => 'Kaos Sablon', 'unit_id' => $unit, 'is_active' => true])
            ->variants()->create(['sku' => 'KAOS-SBL', 'is_active' => true]);

        $this->supplier = Supplier::create(['code' => 'SUP01', 'name' => 'CV Sumber Textile', 'is_active' => true]);
    }

    /**
     * @return array{0: Warehouse, 1: WarehouseLocation}
     */
    private function warehouse(string $code, string $name): array
    {
        $warehouse = Warehouse::create(['code' => $code, 'name' => $name, 'is_active' => true]);
        $zone = $warehouse->zones()->create(['code' => 'A', 'name' => 'Penyimpanan A', 'type' => 'storage', 'is_active' => true]);
        $rack = $zone->racks()->create(['code' => 'A01', 'levels' => 3, 'is_active' => true]);
        $bin = $rack->locations()->create(['warehouse_id' => $warehouse->id, 'code' => "{$code}-A01-01", 'type' => 'bin', 'is_active' => true]);

        return [$warehouse, $bin];
    }

    private function superAdmin(): User
    {
        return User::where('username', 'superadmin')->first();
    }

    private function good(ProductVariant $variant, ?WarehouseLocation $bin = null): int
    {
        return (int) Stock::withoutGlobalScopes()->where('product_variant_id', $variant->id)->where('warehouse_location_id', ($bin ?? $this->bin)->id)->value('qty');
    }

    private function damaged(ProductVariant $variant, ?Warehouse $warehouse = null): int
    {
        return (int) DamagedStock::withoutGlobalScopes()->where('product_variant_id', $variant->id)->where('warehouse_id', ($warehouse ?? $this->warehouse)->id)->value('qty');
    }

    private function stockIn(ProductVariant $variant, int $qty, ?WarehouseLocation $bin = null): void
    {
        $bin ??= $this->bin;

        app(StockService::class)->record([
            'type' => 'in',
            'warehouse_id' => $bin->warehouse_id,
            'movement_date' => now()->toDateString(),
            'created_by' => $this->superAdmin()->id,
        ], [['product_variant_id' => $variant->id, 'to_location_id' => $bin->id, 'qty' => $qty]]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $overrides
     */
    private function document(string $type, array $items, array $overrides = [])
    {
        return $this->post(route('inventory.damaged.documents.store'), [
            'type' => $type,
            'warehouse_id' => $this->warehouse->id,
            'document_date' => now()->toDateString(),
            'action' => 'approve',
            'items' => $items,
            ...$overrides,
        ]);
    }

    /** Pindahkan $qty barang baik ke stok rusak lewat dokumen Pindah ke Rusak. */
    private function makeDamaged(int $qty, ?ProductVariant $variant = null): void
    {
        $variant ??= $this->variant;
        $this->stockIn($variant, $qty);
        $this->document('intake', [['product_variant_id' => $variant->id, 'from_location_id' => $this->bin->id, 'qty' => $qty]])->assertSessionHasNoErrors();
    }

    public function test_receipt_damaged_qty_goes_to_damaged_stock(): void
    {
        $this->actingAs($this->superAdmin());

        $this->post('/inventory/receipts', [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'action' => 'receive',
            'items' => [['product_variant_id' => $this->variant->id, 'warehouse_location_id' => $this->bin->id, 'qty_received' => 10, 'qty_damaged' => 2]],
        ])->assertSessionHasNoErrors()->assertSessionHas('success', fn ($message) => str_contains($message, '2 barang rusak masuk Stok Rusak'));

        $receipt = GoodsReceipt::sole();
        $this->assertSame(10, $this->good($this->variant));
        $this->assertSame(2, $this->damaged($this->variant));

        $ledger = DamagedStockLedger::sole();
        $this->assertSame('receipt', $ledger->type);
        $this->assertSame($receipt->number, $ledger->reference);
        $this->assertTrue($ledger->source->is($receipt));
        $this->assertSame(2, $ledger->qty_in);
    }

    public function test_receipt_with_only_damaged_items_can_be_received(): void
    {
        $this->actingAs($this->superAdmin());

        $this->post('/inventory/receipts', [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'action' => 'receive',
            'items' => [['product_variant_id' => $this->variant->id, 'qty_received' => 0, 'qty_damaged' => 4]],
        ])->assertSessionHasNoErrors();

        $receipt = GoodsReceipt::sole();
        $this->assertSame('received', $receipt->status);
        $this->assertNull($receipt->stock_movement_id);
        $this->assertSame(4, $this->damaged($this->variant));
        $this->get("/inventory/receipts/{$receipt->id}")->assertOk()->assertSee('4 rusak di Stok Rusak');
    }

    public function test_sales_return_damaged_qty_goes_to_damaged_stock(): void
    {
        $this->actingAs($this->superAdmin());

        $order = SalesOrder::create([
            'number' => 'SO2610-001', 'status' => 'shipped', 'warehouse_id' => $this->warehouse->id, 'order_date' => now()->toDateString(),
            'channel_name' => 'Shopee Toko A', 'customer_name' => 'Budi', 'tracking_number' => 'JX123', 'shipped_at' => now(),
        ]);
        $line = $order->items()->create(['product_variant_id' => $this->variant->id, 'qty' => 3, 'price' => 75000]);

        $this->post(route('sales.returns.store'), [
            'source' => 'order',
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'return_date' => now()->toDateString(),
            'action' => 'receive',
            'items' => [['sales_order_item_id' => $line->id, 'qty_good' => 1, 'qty_damaged' => 2, 'warehouse_location_id' => $this->bin->id, 'reason' => 'damaged']],
        ])->assertSessionHasNoErrors();

        $return = SalesReturn::sole();
        $this->assertSame(1, $this->good($this->variant));
        $this->assertSame(2, $this->damaged($this->variant));
        $this->assertTrue(DamagedStockLedger::where('type', 'return')->sole()->source->is($return));
        $this->get(route('sales.returns.show', $return))->assertOk()->assertSee('Lihat Stok Rusak');
    }

    public function test_move_good_stock_to_damaged(): void
    {
        $this->actingAs($this->superAdmin());
        $this->stockIn($this->variant, 10);

        $this->document('intake', [['product_variant_id' => $this->variant->id, 'from_location_id' => $this->bin->id, 'qty' => 3, 'notes' => 'kemasan sobek']])
            ->assertSessionHasNoErrors();

        $document = DamagedDocument::sole();
        $this->assertMatchesRegularExpression('/^RSK-\d{6}-0001$/', $document->number);
        $this->assertSame(7, $this->good($this->variant));
        $this->assertSame(3, $this->damaged($this->variant));
        $this->assertSame('damage', $document->movement->type);
        $this->assertSame($document->number, $document->movement->reference);
        $this->assertSame('intake', DamagedStockLedger::sole()->type);
        $this->assertTrue(ActivityLog::where('subject_type', $document->getMorphClass())->where('subject_id', $document->id)->where('module', 'damaged')->exists());
        $this->assertCount(0, app(StockIntegrity::class)->problems());
    }

    public function test_move_to_damaged_fails_when_good_stock_is_short(): void
    {
        $this->actingAs($this->superAdmin());
        $this->stockIn($this->variant, 2);

        $this->document('intake', [['product_variant_id' => $this->variant->id, 'from_location_id' => $this->bin->id, 'qty' => 5]])
            ->assertSessionHasErrors('items.0.qty');

        $this->assertTrue(DamagedDocument::sole()->isDraft());
        $this->assertSame(2, $this->good($this->variant));
        $this->assertSame(0, $this->damaged($this->variant));
    }

    public function test_return_to_supplier_or_other_party(): void
    {
        $this->actingAs($this->superAdmin());
        $this->makeDamaged(5);

        // Penerima wajib: supplier atau pihak lain.
        $this->document('supplier_return', [['product_variant_id' => $this->variant->id, 'qty' => 1]])->assertSessionHasErrors('recipient');

        $this->document('supplier_return', [['product_variant_id' => $this->variant->id, 'qty' => 2]], ['supplier_id' => $this->supplier->id, 'reference' => 'SJR-01'])
            ->assertSessionHasNoErrors();
        $this->document('supplier_return', [['product_variant_id' => $this->variant->id, 'qty' => 1]], ['recipient' => 'Service Center Bandung'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $this->damaged($this->variant));
        $documents = DamagedDocument::where('type', 'supplier_return')->orderBy('id')->get();
        $this->assertSame('CV Sumber Textile', $documents[0]->recipientName());
        $this->assertSame('Service Center Bandung', $documents[1]->recipientName());
        $this->assertNull($documents[0]->stock_movement_id);

        // Tidak boleh melebihi saldo rusak.
        $this->document('supplier_return', [['product_variant_id' => $this->variant->id, 'qty' => 3]], ['supplier_id' => $this->supplier->id])
            ->assertSessionHasErrors('items.0.qty');
        $this->assertSame(2, $this->damaged($this->variant));
    }

    public function test_disposal_reduces_damaged_stock_only(): void
    {
        $this->actingAs($this->superAdmin());
        $this->makeDamaged(4);
        $this->stockIn($this->variant, 6);

        $this->document('disposal', [['product_variant_id' => $this->variant->id, 'qty' => 4]])->assertSessionHasNoErrors();

        $this->assertSame(0, $this->damaged($this->variant));
        $this->assertSame(6, $this->good($this->variant));
        $this->assertNull(DamagedDocument::where('type', 'disposal')->sole()->stock_movement_id);
    }

    public function test_repair_moves_damaged_back_to_good_stock(): void
    {
        $this->actingAs($this->superAdmin());
        $this->makeDamaged(3);

        $this->document('repair', [['product_variant_id' => $this->variant->id, 'to_location_id' => $this->bin->id, 'qty' => 2]])->assertSessionHasNoErrors();

        $document = DamagedDocument::where('type', 'repair')->sole();
        $this->assertSame(1, $this->damaged($this->variant));
        $this->assertSame(2, $this->good($this->variant));
        $this->assertSame('repair', $document->movement->type);
        $this->assertCount(0, app(StockIntegrity::class)->problems());
    }

    public function test_assembly_turns_damaged_a_plus_good_b_into_c(): void
    {
        $this->actingAs($this->superAdmin());
        $this->makeDamaged(2);
        $this->stockIn($this->part, 5);

        $this->document('assembly', [
            ['role' => 'input', 'source' => 'damaged', 'product_variant_id' => $this->variant->id, 'qty' => 2],
            ['role' => 'input', 'source' => 'good', 'product_variant_id' => $this->part->id, 'from_location_id' => $this->bin->id, 'qty' => 2],
            ['role' => 'output', 'product_variant_id' => $this->bundle->id, 'to_location_id' => $this->bin->id, 'qty' => 2],
        ])->assertSessionHasNoErrors();

        $document = DamagedDocument::where('type', 'assembly')->sole();
        $this->assertSame(0, $this->damaged($this->variant));
        $this->assertSame(3, $this->good($this->part));
        $this->assertSame(2, $this->good($this->bundle));
        $this->assertSame('assembly', $document->movement->type);
        $this->assertSame([-2, 2], $document->movement->items->pluck('qty')->all());
        $this->assertCount(0, app(StockIntegrity::class)->problems());

        $this->get(route('inventory.damaged.documents.show', $document))->assertOk()->assertSee('Hasil Rakit / Campur')->assertSee('KAOS-SBL');
        $this->get(route('inventory.movements.show', $document->movement))->assertOk()->assertSee('Rakit / Campur');
    }

    public function test_assembly_requires_damaged_input_and_output(): void
    {
        $this->actingAs($this->superAdmin());
        $this->stockIn($this->part, 5);

        $this->document('assembly', [
            ['role' => 'input', 'source' => 'good', 'product_variant_id' => $this->part->id, 'from_location_id' => $this->bin->id, 'qty' => 1],
            ['role' => 'output', 'product_variant_id' => $this->bundle->id, 'qty' => 1],
        ])->assertSessionHasErrors('items');

        $this->makeDamaged(1);
        $this->document('assembly', [
            ['role' => 'input', 'source' => 'damaged', 'product_variant_id' => $this->variant->id, 'qty' => 1],
        ])->assertSessionHasErrors('items');
    }

    public function test_good_stock_error_points_at_the_right_form_row(): void
    {
        $this->actingAs($this->superAdmin());
        $this->makeDamaged(1);

        // Baris form ke-1 (indeks 1) adalah bahan dari stok baik yang kosong.
        $this->document('assembly', [
            ['role' => 'input', 'source' => 'damaged', 'product_variant_id' => $this->variant->id, 'qty' => 1],
            ['role' => 'input', 'source' => 'good', 'product_variant_id' => $this->part->id, 'from_location_id' => $this->bin->id, 'qty' => 1],
            ['role' => 'output', 'product_variant_id' => $this->bundle->id, 'qty' => 1],
        ])->assertSessionHasErrors('items.1.qty');

        $this->assertSame(1, $this->damaged($this->variant));
    }

    public function test_pages_render(): void
    {
        $this->actingAs($this->superAdmin());
        $this->makeDamaged(3);
        $document = DamagedDocument::sole();

        $this->get(route('inventory.damaged.index'))->assertOk()->assertSee('KAOS-M')->assertSee('Stok Rusak');
        $this->get(route('inventory.damaged.ledger', ['variant' => $this->variant->id]))->assertOk()->assertSee($document->number);
        $this->get(route('inventory.damaged.documents.index'))->assertOk()->assertSee($document->number);
        $this->get(route('inventory.damaged.documents.show', $document))->assertOk()->assertSee('Pindah ke Rusak');
        $this->get(route('inventory.movements.show', $document->movement))->assertOk()->assertSee('Pindah ke Rusak');

        foreach (array_keys(DamagedDocument::TYPES) as $type) {
            $this->get(route('inventory.damaged.documents.create', ['type' => $type, 'warehouse' => $this->warehouse->id, 'variant' => $this->variant->id]))->assertOk();
        }

        $this->get(route('inventory.damaged.index', ['export' => 'xlsx']))->assertOk();
        $this->get(route('inventory.damaged.ledger', ['export' => 'xlsx']))->assertOk();
        $this->get(route('inventory.damaged.documents.index', ['export' => 'xlsx']))->assertOk();
        $this->get('/reports/movements')->assertOk();
    }

    public function test_permissions_per_document_type(): void
    {
        $staff = User::factory()->create()->assignRole('Staff Gudang');
        $this->actingAs($staff);
        $this->stockIn($this->variant, 5);

        $this->get(route('inventory.damaged.index'))->assertOk();
        $this->document('intake', [['product_variant_id' => $this->variant->id, 'from_location_id' => $this->bin->id, 'qty' => 2]], ['action' => 'draft'])->assertSessionHasNoErrors();
        $draft = DamagedDocument::sole();

        // Staff Gudang bawaan boleh membuat draft Pindah ke Rusak tapi tidak menyetujuinya.
        $this->document('intake', [['product_variant_id' => $this->variant->id, 'from_location_id' => $this->bin->id, 'qty' => 1]])->assertForbidden();
        $this->post(route('inventory.damaged.documents.approve', $draft))->assertForbidden();
        $this->get(route('inventory.damaged.documents.show', $draft))->assertOk()->assertDontSee('Setujui dokumen?');
        $this->assertSame(0, $this->damaged($this->variant));

        $this->actingAs($this->superAdmin())->post(route('inventory.damaged.documents.approve', $draft))->assertSessionHasNoErrors();
        $this->assertSame($this->superAdmin()->id, $draft->refresh()->approved_by);
        $this->actingAs($staff);

        // Juga tidak boleh memusnahkan / meretur.
        $this->get(route('inventory.damaged.documents.create', ['type' => 'disposal']))->assertForbidden();
        $this->document('disposal', [['product_variant_id' => $this->variant->id, 'qty' => 1]], ['action' => 'draft'])->assertForbidden();
        $this->document('supplier_return', [['product_variant_id' => $this->variant->id, 'qty' => 1]], ['recipient' => 'X'])->assertForbidden();
        $this->assertSame(2, $this->damaged($this->variant));

        $nobody = User::factory()->create();
        $this->actingAs($nobody)->get(route('inventory.damaged.index'))->assertForbidden();
    }

    public function test_limited_user_only_sees_and_posts_to_own_warehouse(): void
    {
        $this->actingAs($this->superAdmin());
        $this->stockIn($this->variant, 3, $this->bandungBin);
        $this->document('intake', [['product_variant_id' => $this->variant->id, 'from_location_id' => $this->bandungBin->id, 'qty' => 3]], ['warehouse_id' => $this->bandung->id])
            ->assertSessionHasNoErrors();
        $bandungDocument = DamagedDocument::sole();

        $user = User::factory()->create()->assignRole('Admin');
        $user->syncWarehouseAccess(false, [$this->warehouse->id]);
        $this->actingAs($user->fresh());

        $this->get(route('inventory.damaged.index'))->assertOk()->assertDontSee('BDG01');
        $this->get(route('inventory.damaged.documents.show', $bandungDocument))->assertNotFound();
        $this->document('disposal', [['product_variant_id' => $this->variant->id, 'qty' => 1]], ['warehouse_id' => $this->bandung->id])
            ->assertSessionHasErrors('warehouse_id');
        $this->assertSame(3, $this->damaged($this->variant, $this->bandung));

        // Nomor dokumen tetap urut walau dokumen gudang lain tidak terlihat.
        $this->stockIn($this->variant, 1);
        $this->document('intake', [['product_variant_id' => $this->variant->id, 'from_location_id' => $this->bin->id, 'qty' => 1]])->assertSessionHasNoErrors();
        $this->assertStringEndsWith('-0002', DamagedDocument::withoutGlobalScopes()->latest('id')->value('number'));
        $this->assertSame(2, StockMovement::withoutGlobalScopes()->where('type', 'damage')->distinct()->count('number'));
    }

    public function test_draft_does_not_touch_stock_until_approved(): void
    {
        $this->actingAs($this->superAdmin());
        $this->stockIn($this->variant, 10);

        $this->document('intake', [['product_variant_id' => $this->variant->id, 'from_location_id' => $this->bin->id, 'qty' => 3]], ['action' => 'draft'])
            ->assertSessionHasNoErrors();

        $document = DamagedDocument::sole();
        $this->assertTrue($document->isDraft());
        $this->assertSame(10, $this->good($this->variant));
        $this->assertSame(0, $this->damaged($this->variant));
        $this->assertSame(0, DamagedStockLedger::count());
        $this->get(route('inventory.damaged.index'))->assertOk()->assertSee('1 dokumen draft menunggu persetujuan');
        $this->get(route('inventory.damaged.documents.show', $document))->assertOk()->assertSee('Draft')->assertSee('Setujui');

        // Draft bisa diubah.
        $this->get(route('inventory.damaged.documents.edit', $document))->assertOk()->assertSee($document->number);
        $this->put(route('inventory.damaged.documents.update', $document), [
            'type' => 'intake',
            'warehouse_id' => $this->warehouse->id,
            'document_date' => now()->toDateString(),
            'reference' => 'CEK-RAK',
            'action' => 'draft',
            'items' => [['product_variant_id' => $this->variant->id, 'from_location_id' => $this->bin->id, 'qty' => 4]],
        ])->assertSessionHasNoErrors();
        $this->assertSame([4], $document->refresh()->items->pluck('qty')->all());
        $this->assertSame('CEK-RAK', $document->reference);

        $this->post(route('inventory.damaged.documents.approve', $document))->assertRedirect(route('inventory.damaged.documents.show', $document));

        $document->refresh();
        $this->assertSame('approved', $document->status);
        $this->assertNotNull($document->approved_at);
        $this->assertSame(6, $this->good($this->variant));
        $this->assertSame(4, $this->damaged($this->variant));
        $this->assertTrue(ActivityLog::where('subject_type', $document->getMorphClass())->where('subject_id', $document->id)->where('event', 'status')->exists());

        // Setelah disetujui terkunci.
        $this->post(route('inventory.damaged.documents.approve', $document))->assertSessionHasErrors('document');
        $this->get(route('inventory.damaged.documents.edit', $document))->assertRedirect(route('inventory.damaged.documents.show', $document));
        $this->delete(route('inventory.damaged.documents.destroy', $document))->assertSessionHas('error');
        $this->assertSame(4, $this->damaged($this->variant));
    }

    public function test_draft_can_be_deleted(): void
    {
        $this->actingAs($this->superAdmin());
        $this->makeDamaged(2);
        $this->document('disposal', [['product_variant_id' => $this->variant->id, 'qty' => 2]], ['action' => 'draft'])->assertSessionHasNoErrors();
        $draft = DamagedDocument::where('type', 'disposal')->sole();

        $this->delete(route('inventory.damaged.documents.destroy', $draft))->assertRedirect(route('inventory.damaged.documents.index'));

        $this->assertNull(DamagedDocument::find($draft->id));
        $this->assertSame(2, $this->damaged($this->variant));
    }

    public function test_save_and_approve_keeps_draft_when_stock_is_short(): void
    {
        $this->actingAs($this->superAdmin());
        $this->makeDamaged(1);

        $this->document('disposal', [['product_variant_id' => $this->variant->id, 'qty' => 5]])
            ->assertSessionHasErrors('items.0.qty');

        $draft = DamagedDocument::where('type', 'disposal')->sole();
        $this->assertTrue($draft->isDraft());
        $this->assertSame(1, $this->damaged($this->variant));
    }

    public function test_migration_backfills_damaged_qty_from_earlier_documents(): void
    {
        $this->actingAs($this->superAdmin());
        $this->post('/inventory/receipts', [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'action' => 'receive',
            'items' => [['product_variant_id' => $this->variant->id, 'warehouse_location_id' => $this->bin->id, 'qty_received' => 1, 'qty_damaged' => 3]],
        ])->assertSessionHasNoErrors();

        // Kondisi sebelum fitur ada: tabel stok rusak belum ada.
        foreach (['damaged_stock_ledgers', 'damaged_document_items', 'damaged_documents', 'damaged_stocks'] as $table) {
            Schema::drop($table);
        }

        (require database_path('migrations/2026_10_14_130000_create_damaged_stock_tables.php'))->up();

        $this->assertSame(3, $this->damaged($this->variant));
        $this->assertSame(GoodsReceipt::sole()->number, DB::table('damaged_stock_ledgers')->value('reference'));
    }

    public function test_seeder_grants_new_permissions_to_existing_roles_only_once(): void
    {
        $admin = Role::findByName('Admin');
        $admin->revokePermissionTo('damaged.dispose');
        Permission::where('name', 'damaged.assemble')->delete();

        $this->seed(PermissionSeeder::class);

        $admin->refresh();
        // Permission baru diberikan; yang sengaja dicabut tidak dikembalikan.
        $this->assertTrue($admin->hasPermissionTo('damaged.assemble'));
        $this->assertFalse($admin->hasPermissionTo('damaged.dispose'));
        $this->assertFalse(Role::findByName('Staff Gudang')->hasPermissionTo('damaged.assemble'));
    }
}
