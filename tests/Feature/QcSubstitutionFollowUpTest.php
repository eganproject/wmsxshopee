<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\Courier;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\ShopeeShop;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\FulfillmentScan;
use App\Services\ProductImporter;
use App\Services\QcControl;
use App\Services\SalesOrderService;
use App\Services\Shopee\ShopeeReturnSync;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Temuan audit 11 Nov 2026: Ganti SKU di QC, scan paket, dan bentrok barcode alias. */
class QcSubstitutionFollowUpTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $warehouse;

    private ProductVariant $m;

    private ProductVariant $l;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::create(['code' => 'JKT01', 'name' => 'Gudang Jakarta', 'address' => 'Jl. 1', 'city' => 'Jakarta', 'pic_name' => 'Rina', 'is_active' => true]);
        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => true, 'is_active' => true]);
        $this->m = $product->variants()->create(['sku' => 'KAOS-M', 'name' => 'M', 'is_active' => true]);
        $this->l = $product->variants()->create(['sku' => 'KAOS-L', 'name' => 'L', 'is_active' => true]);
        app(StockService::class)->record(['type' => 'in', 'warehouse_id' => $this->warehouse->id, 'movement_date' => now()->subDay()->toDateString()], [
            ['product_variant_id' => $this->m->id, 'to_location_id' => null, 'qty' => 20],
            ['product_variant_id' => $this->l->id, 'to_location_id' => null, 'qty' => 20],
        ]);
        $this->admin = User::where('username', 'superadmin')->first();
        $this->actingAs($this->admin);
    }

    /** Pesanan Sedang Diambil dengan baris barang dan/atau paket. */
    private function picked(array $items = [], array $bundles = [], array $extra = []): SalesOrder
    {
        $service = app(SalesOrderService::class);
        $order = SalesOrder::create(['number' => SalesOrder::nextNumber(), 'status' => 'draft', 'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(), 'customer_name' => 'Budi', 'tracking_number' => 'R'.uniqid(), ...$extra]);
        $service->fillLines($order, $items, $bundles);
        $service->confirm($order->fresh());
        $service->pick($order->fresh(), $service->suggestPicks($order->fresh()));

        return $order->fresh();
    }

    private function shop(): ShopeeShop
    {
        $shop = ShopeeShop::create(['environment' => 'test', 'shop_id' => 555, 'shop_name' => 'Toko', 'warehouse_id' => $this->warehouse->id,
            'status' => 'connected', 'access_token' => 'a', 'refresh_token' => 'r', 'access_token_expires_at' => now()->addHour()]);
        $shop->mappings()->create(['item_id' => 11, 'model_id' => 101, 'shopee_sku' => 'KAOS-M', 'product_variant_id' => $this->m->id]);

        return $shop;
    }

    private function qcAndShip(SalesOrder $order): void
    {
        $scan = app(FulfillmentScan::class);
        $scanned = collect($scan->expected($order->fresh())['lines'])->mapWithKeys(fn ($line) => [$line['variant_id'] => $line['qty']])->all();
        $scan->completeQc($order->fresh(), $scanned, $this->admin->id);
        app(SalesOrderService::class)->ship($order->fresh(), ['courier_id' => Courier::firstOrFail()->id, 'tracking_number' => 'X'.$order->id], $this->admin->id);
    }

    public function test_marketplace_return_after_qc_substitution_returns_the_replacement(): void
    {
        $shop = $this->shop();
        $order = $this->picked([['product_variant_id' => $this->m->id, 'qty' => 2, 'price' => 1000]], [], ['shopee_shop_id' => $shop->id, 'external_number' => 'SN1']);
        app(QcControl::class)->substitute($order, $this->m->id, $this->l->id, 1, 'warna habis', $this->admin->id);
        $this->qcAndShip($order);

        $result = app(ShopeeReturnSync::class)->apply($shop, ['return_sn' => 'RET1', 'order_sn' => 'SN1', 'status' => 'REQUESTED', 'reason' => 'WRONG_ITEM',
            'item' => [['item_id' => 11, 'model_id' => 101, 'amount' => 2]]]);

        $this->assertSame('created', $result);
        $lines = SalesReturn::sole()->items->mapWithKeys(fn ($item) => [$item->product_variant_id => $item->qty_good])->all();
        // Yang kembali: 1 KAOS-M yang tidak diganti + 1 KAOS-L pengganti.
        $this->assertEquals([$this->m->id => 1, $this->l->id => 1], $lines);
    }

    public function test_substituted_bundle_component_stays_in_the_bundle(): void
    {
        $bundle = Bundle::create(['sku' => 'PAKET', 'name' => 'Paket', 'is_active' => true]);
        $bundle->items()->create(['product_variant_id' => $this->m->id, 'qty' => 2, 'sort_order' => 0]);
        $order = $this->picked([], [['bundle_id' => $bundle->id, 'qty' => 1, 'price' => 1000]]);

        app(QcControl::class)->substitute($order, $this->m->id, $this->l->id, 2, 'warna habis', $this->admin->id);

        $line = $order->fresh()->items->sole();
        $this->assertSame([$this->l->id, 2, 2], [$line->product_variant_id, $line->qty, $line->bundle_component_qty]);
        $this->assertNotNull($line->sales_order_bundle_id);
        $this->assertSame(2, (int) $line->picks->sum('qty'));
        // Scan barcode paket menghitung barang pengganti.
        $this->assertSame([$this->l->id => 2], app(FulfillmentScan::class)->expected($order->fresh())['bundles']['paket']);
    }

    public function test_bundle_scan_uses_contents_at_order_time(): void
    {
        $bundle = Bundle::create(['sku' => 'PAKET', 'name' => 'Paket', 'is_active' => true]);
        $bundle->items()->create(['product_variant_id' => $this->m->id, 'qty' => 2, 'sort_order' => 0]);
        $order = $this->picked([], [['bundle_id' => $bundle->id, 'qty' => 2, 'price' => 1000]]);

        // Master paket diubah setelah pesanan masuk.
        $bundle->items()->delete();
        $bundle->items()->create(['product_variant_id' => $this->l->id, 'qty' => 1, 'sort_order' => 0]);

        $this->assertSame([$this->m->id => 2], app(FulfillmentScan::class)->expected($order->fresh())['bundles']['paket']);

        // Sebagian isi satu paket diganti: isi tiap paket tidak sama lagi, scan paket dimatikan.
        app(QcControl::class)->substitute($order->fresh(), $this->m->id, $this->l->id, 1, 'cacat', $this->admin->id);
        $this->assertArrayNotHasKey('paket', app(FulfillmentScan::class)->expected($order->fresh())['bundles']);
    }

    public function test_importer_rejects_barcode_used_as_alias(): void
    {
        ProductBarcode::create(['product_variant_id' => $this->m->id, 'barcode' => 'ALIAS-1']);
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, "sku_induk,nama_produk,satuan,barcode\nKAOS-X,Kaos X,PCS,ALIAS-1\nALIAS-1,Kaos Y,PCS,\n");

        try {
            app(ProductImporter::class)->import($path, 'csv', true);
            $this->fail('Import seharusnya ditolak.');
        } catch (ValidationException $e) {
            $text = implode(' ', array_merge(...array_values($e->errors())));
            $this->assertStringContainsString('barcode ALIAS-1 sudah dipakai sebagai barcode alias', $text);
            $this->assertStringContainsString('SKU ALIAS-1 sudah dipakai sebagai barcode alias', $text);
        } finally {
            @unlink($path);
        }

        $this->assertNull(ProductVariant::where('sku', 'KAOS-X')->first());
    }

    public function test_qc_hold_is_cleared_when_order_goes_back_to_draft_or_is_cancelled(): void
    {
        $service = app(SalesOrderService::class);
        $order = $this->picked([['product_variant_id' => $this->m->id, 'qty' => 1, 'price' => 1000]]);
        app(QcControl::class)->hold($order, 'Barang penyok', $this->admin->id);

        $service->reopen($order->fresh());
        $this->assertFalse($order->fresh()->isQcHeld());

        $service->confirm($order->fresh());
        $service->pick($order->fresh(), $service->suggestPicks($order->fresh()));
        app(QcControl::class)->hold($order->fresh(), 'Ragu', $this->admin->id);
        $service->cancel($order->fresh(), 'batal');
        $this->assertNull($order->fresh()->qc_hold_reason);
    }

    public function test_qc_queue_page_has_no_statement_in_x_init(): void
    {
        $this->get('/sales/qc')->assertOk()->assertDontSee('x-init="try', false);
    }
}
