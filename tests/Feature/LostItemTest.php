<?php

namespace Tests\Feature;

use App\Models\LostItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\Stock;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Kekurangan saat terima transfer & barang hilang di retur tercatat di laporan Barang Hilang. */
class LostItemTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private User $admin;

    private Warehouse $big;

    private Warehouse $small;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::where('username', 'superadmin')->first();
        $this->big = Warehouse::create(['code' => 'GDB', 'name' => 'Gudang Besar', 'is_active' => true]);
        $this->small = Warehouse::create(['code' => 'GDK', 'name' => 'Gudang Kecil', 'is_active' => true]);
        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'is_active' => true]);
        $this->variant = $product->variants()->create(['sku' => 'KAOS', 'is_active' => true]);

        app(StockService::class)->record(['type' => 'in', 'warehouse_id' => $this->big->id, 'movement_date' => now()->toDateString()], [
            ['product_variant_id' => $this->variant->id, 'to_location_id' => null, 'qty' => 50],
        ]);
    }

    private function sendTransfer(int $qty): WarehouseTransfer
    {
        $this->actingAs($this->admin)->post('/inventory/transfers', [
            'from_warehouse_id' => $this->big->id, 'to_warehouse_id' => $this->small->id, 'transfer_date' => now()->toDateString(),
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => $qty]],
        ])->assertSessionHasNoErrors();

        return WarehouseTransfer::latest('id')->first();
    }

    public function test_transfer_shortage_is_recorded_as_lost_and_shown_in_report(): void
    {
        $transfer = $this->sendTransfer(20);
        $item = $transfer->items()->first();

        $this->post("/inventory/transfers/{$transfer->id}/receive", ['items' => [$item->id => ['received_qty' => 17]]])
            ->assertSessionHasNoErrors();

        $this->assertSame(17, (int) Stock::where('warehouse_id', $this->small->id)->sum('qty'));
        $lost = LostItem::sole();
        $this->assertSame(3, $lost->qty);
        $this->assertSame('transfer', $lost->source);
        $this->assertSame($this->small->id, $lost->warehouse_id);
        $this->assertSame($this->big->id, $lost->from_warehouse_id);
        $this->assertSame($transfer->number, $lost->reference);

        $this->get("/inventory/transfers/{$transfer->id}")->assertOk()->assertSee('tercatat hilang');
        $this->get('/reports/lost-items')->assertOk()
            ->assertSee($transfer->number)->assertSee('Kurang saat terima transfer')->assertSee('Dikirim 20, diterima 17');
        $this->get('/reports/lost-items?export=xlsx')->assertOk();
    }

    public function test_full_receipt_records_nothing(): void
    {
        $transfer = $this->sendTransfer(5);
        $item = $transfer->items()->first();

        $this->post("/inventory/transfers/{$transfer->id}/receive", ['items' => [$item->id => ['received_qty' => 5]]])->assertSessionHasNoErrors();

        $this->assertSame(0, LostItem::count());
        $this->get('/reports/lost-items')->assertOk()->assertSee('Tidak ada barang hilang');
    }

    public function test_missing_items_in_a_return_are_recorded_as_lost(): void
    {
        $order = SalesOrder::create(['number' => 'SO-1', 'warehouse_id' => $this->big->id, 'order_date' => now(), 'status' => 'shipped', 'customer_name' => 'Andi', 'shipped_at' => now()]);
        $line = $order->items()->create(['product_variant_id' => $this->variant->id, 'qty' => 3, 'price' => 0]);

        $this->actingAs($this->admin)->post('/sales/returns', [
            'action' => 'receive', 'source' => 'order', 'sales_order_id' => $order->id, 'warehouse_id' => $this->big->id, 'return_date' => now()->toDateString(),
            'items' => [['sales_order_item_id' => $line->id, 'qty_good' => 1, 'qty_missing' => 2, 'reason' => 'missing']],
        ])->assertSessionHasNoErrors();

        $lost = LostItem::sole();
        $this->assertSame(2, $lost->qty);
        $this->assertSame('return', $lost->source);
        $this->assertSame(SalesReturn::latest('id')->value('number'), $lost->reference);
        $this->get('/reports/lost-items?source=return')->assertOk()->assertSee($lost->reference);
    }

    public function test_report_follows_warehouse_access_and_permission(): void
    {
        $transfer = $this->sendTransfer(10);
        $this->post("/inventory/transfers/{$transfer->id}/receive", ['items' => [$transfer->items()->first()->id => ['received_qty' => 8]]]);
        $this->flushSession(); // pesan sukses terima transfer jangan ikut ke request berikutnya

        $role = Role::create(['name' => 'Pelapor'])->givePermissionTo('reports.view');
        $bigOnly = User::factory()->create()->assignRole($role);
        $bigOnly->syncWarehouseAccess(false, [$this->big->id]);
        $this->actingAs($bigOnly)->get('/reports/lost-items')->assertOk()->assertDontSee($transfer->number);

        $smallOnly = User::factory()->create()->assignRole($role);
        $smallOnly->syncWarehouseAccess(false, [$this->small->id]);
        $this->actingAs($smallOnly)->get('/reports/lost-items')->assertOk()->assertSee($transfer->number);

        $noReports = User::factory()->create();
        $this->actingAs($noReports)->get('/reports/lost-items')->assertForbidden();
    }
}
