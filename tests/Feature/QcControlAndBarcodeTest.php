<?php

namespace Tests\Feature;

use App\Models\BarcodeScanMiss;
use App\Models\Courier;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductVariant;
use App\Models\QcExemptVariant;
use App\Models\QcSubstitution;
use App\Models\SalesOrder;
use App\Models\ScanDuplicateAttempt;
use App\Models\Stock;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesOrderService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QcControlAndBarcodeTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $warehouse;

    private ProductVariant $kaosM;

    private ProductVariant $kaosL;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::create(['code' => 'JKT01', 'name' => 'Gudang Jakarta', 'address' => 'Jl. Gudang 1', 'city' => 'Jakarta', 'pic_name' => 'Rina', 'is_active' => true]);
        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => true, 'is_active' => true]);
        $this->kaosM = $product->variants()->create(['sku' => 'KAOS-M', 'name' => 'M', 'barcode' => '8991001', 'is_active' => true]);
        $this->kaosL = $product->variants()->create(['sku' => 'KAOS-L', 'name' => 'L', 'is_active' => true]);

        app(StockService::class)->record(['type' => 'in', 'warehouse_id' => $this->warehouse->id, 'movement_date' => now()->subDays(10)->toDateString()], [
            ['product_variant_id' => $this->kaosM->id, 'to_location_id' => null, 'qty' => 20],
            ['product_variant_id' => $this->kaosL->id, 'to_location_id' => null, 'qty' => 20],
        ]);

        $this->admin = User::where('username', 'superadmin')->first();
        $this->actingAs($this->admin);
    }

    private function pickedOrder(string $tracking, int $qty = 2): SalesOrder
    {
        $this->post('/sales/orders', [
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'customer_name' => 'Budi',
            'shipping_address' => 'Jl. Merdeka 1, Bandung',
            'items' => [['product_variant_id' => $this->kaosM->id, 'qty' => $qty, 'price' => 75000]],
            'action' => 'confirm',
        ])->assertSessionHasNoErrors();

        $order = SalesOrder::latest('id')->firstOrFail();
        $order->update(['tracking_number' => $tracking]);
        $this->post("/sales/orders/{$order->id}/pick", ['picks' => $order->items->mapWithKeys(fn ($item) => [
            $item->id => [['warehouse_location_id' => '', 'qty' => $item->qty]],
        ])->all()])->assertSessionHasNoErrors();

        return $order->fresh();
    }

    private function ship(SalesOrder $order): void
    {
        app(SalesOrderService::class)->ship($order->fresh(), ['courier_id' => Courier::firstOrFail()->id, 'tracking_number' => $order->tracking_number], $this->admin->id);
    }

    public function test_variant_can_have_many_barcode_aliases(): void
    {
        $this->post('/master/barcodes', ['product_variant_id' => $this->kaosM->id, 'barcode' => 'ALIAS-001'])->assertSessionHasNoErrors();
        $this->post('/master/barcodes', ['product_variant_id' => $this->kaosM->id, 'barcode' => 'ALIAS-002'])->assertSessionHasNoErrors();

        // Tidak boleh bentrok dengan SKU, barcode utama, atau alias lain.
        $this->post('/master/barcodes', ['product_variant_id' => $this->kaosL->id, 'barcode' => 'ALIAS-001'])->assertSessionHasErrors('barcode');
        $this->post('/master/barcodes', ['product_variant_id' => $this->kaosL->id, 'barcode' => '8991001'])->assertSessionHasErrors('barcode');
        $this->post('/master/barcodes', ['product_variant_id' => $this->kaosL->id, 'barcode' => 'KAOS-M'])->assertSessionHasErrors('barcode');

        $this->assertSame(2, $this->kaosM->aliases()->count());
        $this->assertTrue(ProductVariant::findByCode('ALIAS-002')?->is($this->kaosM));
        $this->assertTrue(ProductVariant::findByCode('8991001')?->is($this->kaosM));
        $this->get('/master/barcodes')->assertOk()->assertSee('ALIAS-001')->assertSee('KAOS-M');

        // Barcode utama varian lain tidak boleh memakai alias yang sudah ada.
        $this->assertSame(1, ProductBarcode::where('barcode', 'ALIAS-001')->count());
    }

    public function test_unknown_barcode_is_logged_and_can_be_resolved_as_alias(): void
    {
        $this->postJson('/scan-misses', ['code' => 'XYZ-999', 'context' => 'receipt'])->assertOk();
        $this->postJson('/scan-misses', ['code' => 'XYZ-999', 'context' => 'receipt'])->assertOk();
        // Kode yang dikenali tidak dicatat.
        $this->postJson('/scan-misses', ['code' => 'KAOS-M', 'context' => 'receipt'])->assertOk();

        $miss = BarcodeScanMiss::sole();
        $this->assertSame(['XYZ-999', 2], [$miss->code, $miss->scan_count]);
        $this->get('/master/barcodes/misses')->assertOk()->assertSee('XYZ-999');

        $this->post("/master/barcodes/misses/{$miss->id}/resolve", ['resolution' => 'alias'])->assertSessionHasErrors('product_variant_id');
        $this->post("/master/barcodes/misses/{$miss->id}/resolve", ['resolution' => 'alias', 'product_variant_id' => $this->kaosL->id])->assertSessionHasNoErrors();

        $this->assertNotNull($miss->fresh()->resolved_at);
        $this->assertTrue(ProductVariant::findByCode('XYZ-999')?->is($this->kaosL));
        $this->get('/master/barcodes/misses'); // pesan sukses
        $this->get('/master/barcodes/misses')->assertOk()->assertDontSee('XYZ-999');
        $this->get('/master/barcodes/misses?status=resolved')->assertOk()->assertSee('XYZ-999');
    }

    public function test_qc_scan_accepts_alias_and_skips_exempt_lines(): void
    {
        ProductBarcode::create(['product_variant_id' => $this->kaosM->id, 'barcode' => 'ALIAS-QC']);
        $order = $this->pickedOrder('RESI-ALIAS');

        $this->get("/sales/qc/{$order->id}")->assertOk()->assertSee('ALIAS-QC');

        $this->post('/sales/qc-exempt', ['product_variant_id' => $this->kaosM->id, 'reason' => 'Barang tanpa barcode'])->assertSessionHasNoErrors();
        $this->post('/sales/qc-exempt', ['product_variant_id' => $this->kaosM->id])->assertSessionHasErrors('product_variant_id');
        $this->get('/sales/qc-exempt')->assertOk()->assertSee('KAOS-M');

        // Baris yang dikecualikan tidak perlu discan.
        $this->post("/sales/qc/{$order->id}", ['scanned' => []])->assertSessionHasNoErrors();
        $this->assertSame('packed', $order->fresh()->status);

        $this->delete('/sales/qc-exempt/'.QcExemptVariant::sole()->id)->assertSessionHasNoErrors();
        $this->assertSame(0, QcExemptVariant::count());
    }

    public function test_held_order_cannot_be_packed_until_released(): void
    {
        $order = $this->pickedOrder('RESI-HOLD');

        $this->post("/sales/qc/{$order->id}/hold", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("/sales/qc/{$order->id}/hold", ['reason' => 'Barang penyok'])->assertSessionHasNoErrors();
        $this->assertTrue($order->fresh()->isQcHeld());
        $this->get('/sales/qc?hold=1')->assertOk()->assertSee($order->number);
        $this->get("/sales/qc/{$order->id}")->assertOk()->assertSee('Barang penyok');

        $this->post("/sales/qc/{$order->id}", ['scanned' => [$this->kaosM->id => 2]])->assertSessionHasErrors();
        $this->assertSame('picking', $order->fresh()->status);

        $this->post("/sales/qc/{$order->id}/release")->assertSessionHasNoErrors();
        $this->assertFalse($order->fresh()->isQcHeld());
        $this->post("/sales/qc/{$order->id}", ['scanned' => [$this->kaosM->id => 2]])->assertSessionHasNoErrors();
        $this->assertSame('packed', $order->fresh()->status);
    }

    public function test_substitution_replaces_line_and_stock_follows_replacement(): void
    {
        $order = $this->pickedOrder('RESI-SUB', 3);

        $this->post("/sales/qc/{$order->id}/substitute", ['from_variant_id' => $this->kaosM->id, 'to_code' => 'KAOS-M', 'qty' => 1, 'reason' => 'x'])->assertSessionHasErrors('to_code');
        $this->post("/sales/qc/{$order->id}/substitute", ['from_variant_id' => $this->kaosM->id, 'to_code' => 'KAOS-L', 'qty' => 4, 'reason' => 'x'])->assertSessionHasErrors('qty');
        $this->post("/sales/qc/{$order->id}/substitute", ['from_variant_id' => $this->kaosM->id, 'to_code' => 'KAOS-L', 'qty' => 1, 'reason' => 'Warna habis, pembeli setuju'])->assertSessionHasNoErrors();

        $order->refresh()->load('items');
        $this->assertSame(2, (int) $order->items->where('product_variant_id', $this->kaosM->id)->sum('qty'));
        $this->assertSame(1, (int) $order->items->where('product_variant_id', $this->kaosL->id)->sum('qty'));
        $this->assertSame('Warna habis, pembeli setuju', QcSubstitution::sole()->reason);
        $this->get('/sales/qc-log/substitutions')->assertOk()->assertSee('Warna habis, pembeli setuju');

        $this->post("/sales/qc/{$order->id}", ['scanned' => [$this->kaosM->id => 2, $this->kaosL->id => 1]])->assertSessionHasNoErrors();
        $this->ship($order);

        $this->assertSame(18, (int) Stock::where('product_variant_id', $this->kaosM->id)->sum('qty'));
        $this->assertSame(19, (int) Stock::where('product_variant_id', $this->kaosL->id)->sum('qty'));
    }

    public function test_duplicate_tracking_scan_is_logged(): void
    {
        $order = $this->pickedOrder('RESI-DUP');
        $this->post("/sales/qc/{$order->id}", ['scanned' => [$this->kaosM->id => 2]])->assertSessionHasNoErrors();

        $this->post('/sales/qc', ['code' => 'RESI-DUP'])->assertSessionHas('error');
        $this->assertSame(['RESI-DUP', 'qc', 'packed'], [ScanDuplicateAttempt::sole()->code, ScanDuplicateAttempt::sole()->context, ScanDuplicateAttempt::sole()->order_status]);
        $this->get('/sales/qc-log/duplicates')->assertOk()->assertSee('RESI-DUP');
    }

    public function test_stock_reports_runout_balance_and_moving(): void
    {
        foreach (['R1', 'R2'] as $tracking) {
            $order = $this->pickedOrder($tracking, 5);
            $this->post("/sales/qc/{$order->id}", ['scanned' => [$this->kaosM->id => 5]])->assertSessionHasNoErrors();
            $this->ship($order);
        }

        $this->get('/reports/runout?status=all')->assertOk()->assertSee('KAOS-M');
        $this->get('/reports/runout?export=xlsx')->assertOk();

        // Saldo sebelum barang masuk masih kosong, sesudahnya 20 pcs.
        $this->get('/reports/balance?date='.now()->subDays(20)->toDateString())->assertOk()->assertDontSee('KAOS-M');
        $this->get('/reports/balance?date='.now()->subDays(5)->toDateString())->assertOk()->assertSee('KAOS-M');
        $this->get('/reports/balance?export=xlsx')->assertOk();

        $this->get('/reports/moving')->assertOk()->assertSee('KAOS-M')->assertSee('Fast Moving');
        $this->get('/reports/moving?class=dead')->assertOk()->assertSee('KAOS-L')->assertDontSee('KAOS-M');
        $this->get('/reports/moving?export=xlsx')->assertOk();
    }
}
