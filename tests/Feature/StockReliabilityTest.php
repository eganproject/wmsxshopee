<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Stock;
use App\Models\StockLedger;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\StockService;
use App\Support\DatabaseConflict;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PDOException;
use Tests\TestCase;

/**
 * Alur stok dari ujung ke ujung dan pengaman stok minus / klik ganda / bentrok
 * antar pengguna. Setelah tiap alur, saldo harus cocok dengan kartu stok.
 */
class StockReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $warehouse;

    private WarehouseLocation $binA;

    private WarehouseLocation $binB;

    private ProductVariant $variant;

    private ProductVariant $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::create(['code' => 'JKT01', 'name' => 'Gudang Jakarta', 'is_active' => true]);
        $zone = $this->warehouse->zones()->create(['code' => 'A', 'name' => 'Penyimpanan A', 'type' => 'storage', 'is_active' => true]);
        $rack = $zone->racks()->create(['code' => 'A01', 'levels' => 3, 'is_active' => true]);
        $this->binA = $rack->locations()->create(['warehouse_id' => $this->warehouse->id, 'code' => 'A01-01-01', 'type' => 'bin', 'is_active' => true]);
        $this->binB = $rack->locations()->create(['warehouse_id' => $this->warehouse->id, 'code' => 'A01-01-02', 'type' => 'bin', 'is_active' => true]);

        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => true, 'is_active' => true]);
        $this->variant = $product->variants()->create(['sku' => 'KAOS-M', 'name' => 'M', 'is_active' => true]);
        $this->other = $product->variants()->create(['sku' => 'KAOS-L', 'name' => 'L', 'is_active' => true]);

        $this->actingAs(User::where('username', 'superadmin')->first());
    }

    private function qty(ProductVariant $variant, ?WarehouseLocation $location): int
    {
        return (int) Stock::where('product_variant_id', $variant->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->where('warehouse_location_id', $location?->id)
            ->value('qty');
    }

    /** @param  array<int, array<string, mixed>>  $items */
    private function record(string $type, array $items): void
    {
        app(StockService::class)->record(
            ['type' => $type, 'warehouse_id' => $this->warehouse->id, 'movement_date' => now()->toDateString()],
            $items,
        );
    }

    private function assertStockConsistent(): void
    {
        $this->artisan('stock:check')->assertSuccessful();
    }

    public function test_full_flow_receipt_transfer_order_and_return_keeps_balance_equal_to_stock_card(): void
    {
        // Penerimaan barang: 10 ke bin A, 3 tanpa bin.
        $supplier = Supplier::create(['code' => 'SUP01', 'name' => 'CV Sumber', 'is_active' => true]);
        $this->post('/inventory/receipts', [
            'action' => 'receive',
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'items' => [
                ['product_variant_id' => $this->variant->id, 'warehouse_location_id' => $this->binA->id, 'qty_received' => 10],
                ['product_variant_id' => $this->other->id, 'warehouse_location_id' => null, 'qty_received' => 3],
            ],
        ])->assertSessionHasNoErrors();

        // Klik "Terima" kedua kali (mis. tombol ditekan dua kali) tidak menambah stok lagi.
        $receipt = GoodsReceipt::sole();
        $this->post("/inventory/receipts/{$receipt->id}/receive")->assertSessionHasErrors('receipt');
        $this->assertSame(10, $this->qty($this->variant, $this->binA));
        $this->assertStockConsistent();

        // Transfer 4 dari bin A ke bin B.
        $this->post('/inventory/movements', [
            'type' => 'transfer',
            'warehouse_id' => $this->warehouse->id,
            'movement_date' => now()->toDateString(),
            'items' => [['product_variant_id' => $this->variant->id, 'from_location_id' => $this->binA->id, 'to_location_id' => $this->binB->id, 'qty' => 4]],
        ])->assertSessionHasNoErrors();
        $this->assertSame(6, $this->qty($this->variant, $this->binA));
        $this->assertSame(4, $this->qty($this->variant, $this->binB));

        // Pesanan: konfirmasi, ambil dari kedua bin, kemas, kirim.
        $this->post('/sales/orders', [
            'action' => 'confirm',
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'customer_name' => 'Budi',
            'items' => [
                ['product_variant_id' => $this->variant->id, 'qty' => 8, 'price' => 75000],
                ['product_variant_id' => $this->other->id, 'qty' => 2, 'price' => 70000],
            ],
        ])->assertSessionHasNoErrors();
        $order = SalesOrder::with('items')->sole();
        $line = fn (ProductVariant $variant) => $order->items->firstWhere('product_variant_id', $variant->id)->id;

        $this->post("/sales/orders/{$order->id}/pick", ['picks' => [
            $line($this->variant) => [['warehouse_location_id' => $this->binA->id, 'qty' => 6], ['warehouse_location_id' => $this->binB->id, 'qty' => 2]],
            $line($this->other) => [['warehouse_location_id' => null, 'qty' => 2]],
        ]])->assertSessionHasNoErrors();
        $this->post("/sales/orders/{$order->id}/pack")->assertSessionHasNoErrors();

        $shipping = ['courier_id' => Courier::first()->id, 'tracking_number' => 'JNE123'];
        $this->post("/sales/orders/{$order->id}/ship", $shipping)->assertSessionHasNoErrors();
        // Kirim dua kali: stok tidak keluar dua kali.
        $this->post("/sales/orders/{$order->id}/ship", $shipping)->assertSessionHasErrors('order');

        $this->assertSame(0, $this->qty($this->variant, $this->binA));
        $this->assertSame(2, $this->qty($this->variant, $this->binB));
        $this->assertSame(1, $this->qty($this->other, null));
        $this->assertSame(1, StockMovement::where('type', 'out')->count());
        $this->assertStockConsistent();

        // Retur: 1 barang layak jual kembali ke bin A.
        $this->post(route('sales.returns.store'), [
            'action' => 'receive',
            'source' => 'order',
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'return_date' => now()->toDateString(),
            'items' => [['sales_order_item_id' => $line($this->variant), 'qty_good' => 1, 'qty_damaged' => 0, 'warehouse_location_id' => $this->binA->id, 'reason' => 'wrong_size']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->qty($this->variant, $this->binA));
        $this->assertStockConsistent();
    }

    public function test_stock_out_beyond_balance_is_rejected_and_nothing_is_written(): void
    {
        $this->record('in', [['product_variant_id' => $this->variant->id, 'to_location_id' => $this->binA->id, 'qty' => 5]]);
        $movements = StockMovement::count();
        $ledgers = StockLedger::count();

        // Baris pertama cukup, baris kedua tidak: seluruh dokumen batal.
        $this->post('/inventory/movements', [
            'type' => 'out',
            'warehouse_id' => $this->warehouse->id,
            'movement_date' => now()->toDateString(),
            'items' => [
                ['product_variant_id' => $this->variant->id, 'from_location_id' => $this->binA->id, 'qty' => 3],
                ['product_variant_id' => $this->variant->id, 'from_location_id' => $this->binA->id, 'qty' => 3],
            ],
        ])->assertSessionHasErrors('items.1.qty');

        $this->assertSame(5, $this->qty($this->variant, $this->binA));
        $this->assertSame($movements, StockMovement::count());
        $this->assertSame($ledgers, StockLedger::count());

        // Penyesuaian minus melebihi saldo juga ditolak, termasuk stok tanpa bin.
        $this->expectException(ValidationException::class);
        $this->record('adjustment', [['product_variant_id' => $this->other->id, 'to_location_id' => null, 'qty' => -1]]);
    }

    public function test_balance_can_never_be_saved_negative_even_outside_stock_service(): void
    {
        $this->record('in', [['product_variant_id' => $this->variant->id, 'to_location_id' => $this->binA->id, 'qty' => 2]]);
        $stock = Stock::sole();

        try {
            $stock->update(['qty' => -1]);
            $this->fail('Saldo minus seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('tidak boleh minus', $e->errors()['stock'][0]);
        }

        $this->assertSame(2, $stock->fresh()->qty);
    }

    public function test_stock_check_reports_balance_that_differs_from_stock_card(): void
    {
        $this->record('in', [
            ['product_variant_id' => $this->variant->id, 'to_location_id' => $this->binA->id, 'qty' => 5],
            ['product_variant_id' => $this->other->id, 'to_location_id' => null, 'qty' => 2],
        ]);
        $this->assertStockConsistent();

        // Diubah langsung di database (melewati aplikasi).
        DB::table('stocks')->where('product_variant_id', $this->variant->id)->update(['qty' => 7]);

        $this->artisan('stock:check')
            ->expectsOutputToContain('Ditemukan 1 saldo stok bermasalah')
            ->expectsOutputToContain('Saldo beda dengan kartu stok')
            ->assertFailed();
    }

    public function test_negative_balance_is_blocked_by_the_database_or_reported(): void
    {
        $this->record('in', [['product_variant_id' => $this->other->id, 'to_location_id' => null, 'qty' => 2]]);
        $minus = fn () => DB::table('stocks')->where('product_variant_id', $this->other->id)->update(['qty' => -1]);

        if (DB::connection()->getDriverName() !== 'sqlite') {
            // MySQL/MariaDB: CHECK constraint menolak saldo minus.
            $this->expectException(QueryException::class);
            $minus();
        }

        // SQLite tidak punya CHECK di tabel lama: stock:check yang menangkapnya.
        $minus();
        $this->artisan('stock:check')->expectsOutputToContain('Saldo minus')->assertFailed();
    }

    public function test_reports_with_opening_balance_after_stock_out(): void
    {
        // Saldo awal periode berasal dari baris keluar yang lebih besar dari masuk di baris itu.
        app(StockService::class)->record(['type' => 'in', 'warehouse_id' => $this->warehouse->id, 'movement_date' => now()->subDays(10)->toDateString()],
            [['product_variant_id' => $this->variant->id, 'to_location_id' => $this->binA->id, 'qty' => 5]]);
        app(StockService::class)->record(['type' => 'out', 'warehouse_id' => $this->warehouse->id, 'movement_date' => now()->subDays(5)->toDateString()],
            [['product_variant_id' => $this->variant->id, 'from_location_id' => $this->binA->id, 'qty' => 3]]);
        app(StockService::class)->record(['type' => 'adjustment', 'warehouse_id' => $this->warehouse->id, 'movement_date' => now()->toDateString()],
            [['product_variant_id' => $this->variant->id, 'to_location_id' => $this->binA->id, 'qty' => -1]]);

        $from = now()->subDays(2)->toDateString();
        $this->get("/inventory/stock-card?variant={$this->variant->id}&from={$from}")->assertOk();
        $this->get("/reports/movements?from={$from}&to=".now()->toDateString())->assertOk()->assertSee('KAOS-M');
    }

    public function test_lock_conflict_with_other_user_shows_a_retry_message(): void
    {
        $deadlock = new QueryException('mysql', 'update stocks set qty = ?', [1], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
        $this->assertSame('Data sedang diproses pengguna lain. Silakan coba lagi.', DatabaseConflict::message($deadlock));

        $check = new PDOException('SQLSTATE[HY000]: General error: 3819 Check constraint \'stocks_qty_non_negative\' is violated.');
        $check->errorInfo = ['HY000', 3819, "Check constraint 'stocks_qty_non_negative' is violated."];
        $this->assertStringContainsString('tidak boleh minus', DatabaseConflict::message(new QueryException('mysql', 'update stocks', [], $check)));

        $this->assertNull(DatabaseConflict::message(new QueryException('mysql', 'select 1', [], new PDOException('SQLSTATE[42S02]: Base table not found'))));

        Route::middleware('web')->post('/_test/deadlock', fn () => throw $deadlock);

        $this->from('/inventory/movements/create')->post('/_test/deadlock', ['reference' => 'SJ-1'])
            ->assertRedirect('/inventory/movements/create')
            ->assertSessionHas('error', 'Data sedang diproses pengguna lain. Silakan coba lagi.')
            ->assertSessionHasInput('reference', 'SJ-1');
    }
}
