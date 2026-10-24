<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Catatan kecil dari uji alur (24 Okt 2026): nomor dokumen tidak dipakai ulang,
 * grafik dashboard tidak menghitung transfer antar gudang dua kali, dan cek
 * no. pesanan ganda melihat semua gudang.
 */
class SmallDecisionsTest extends TestCase
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
        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'is_active' => true]);
        $this->variant = $product->variants()->create(['sku' => 'KAOS', 'is_active' => true]);

        app(StockService::class)->record(['type' => 'in', 'warehouse_id' => $this->big->id, 'movement_date' => now()->toDateString()], [
            ['product_variant_id' => $this->variant->id, 'to_location_id' => null, 'qty' => 50],
        ]);
    }

    private function draftOrder(Warehouse $warehouse, string $external = ''): TestResponse
    {
        return $this->post('/sales/orders', [
            'warehouse_id' => $warehouse->id, 'order_date' => now()->toDateString(), 'customer_name' => 'Budi',
            'channel_name' => 'WhatsApp', 'external_number' => $external,
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1, 'price' => 0]],
        ]);
    }

    private function smallWarehouseStaff(): User
    {
        $role = Role::create(['name' => 'Staf GDK'])->givePermissionTo(['dashboard.view', 'sales.view', 'sales.create', 'inventory.view']);
        $staff = User::factory()->create()->assignRole($role);
        $staff->syncWarehouseAccess(false, [$this->small->id]);

        return $staff;
    }

    public function test_number_of_deleted_draft_is_not_reused(): void
    {
        $this->actingAs($this->admin);
        $this->draftOrder($this->big)->assertSessionHasNoErrors();
        $this->draftOrder($this->big)->assertSessionHasNoErrors();
        $second = SalesOrder::latest('id')->first();
        $this->assertStringEndsWith('-002', $second->number);

        $this->delete("/sales/orders/{$second->id}")->assertSessionHasNoErrors();
        $this->assertModelMissing($second);

        $this->draftOrder($this->big)->assertSessionHasNoErrors();
        $this->assertStringEndsWith('-003', SalesOrder::latest('id')->value('number'));
    }

    public function test_sequence_continues_from_existing_documents(): void
    {
        // Data lama sebelum tabel urutan ada: lanjut dari nomor terbesar yang tersimpan.
        $prefix = 'SO'.now()->format('ym').'-';
        SalesOrder::create(['number' => $prefix.'041', 'warehouse_id' => $this->big->id, 'order_date' => now(), 'status' => 'draft', 'customer_name' => 'X']);

        $this->actingAs($this->admin);
        $this->draftOrder($this->big)->assertSessionHasNoErrors();
        $this->assertSame($prefix.'042', SalesOrder::latest('id')->value('number'));
    }

    public function test_all_warehouse_chart_does_not_count_inter_warehouse_transfer_twice(): void
    {
        $this->actingAs($this->admin)->post('/inventory/transfers', [
            'from_warehouse_id' => $this->big->id, 'to_warehouse_id' => $this->small->id, 'transfer_date' => now()->toDateString(),
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 20]],
        ])->assertSessionHasNoErrors();
        $transfer = WarehouseTransfer::latest('id')->first();
        $this->post("/inventory/transfers/{$transfer->id}/receive", ['items' => [$transfer->items()->first()->id => ['received_qty' => 20]]])
            ->assertSessionHasNoErrors();
        $this->flushSession();

        // Semua gudang: hanya 50 masuk dari penerimaan awal, transfer tidak dihitung.
        $this->get('/dashboard')->assertOk()->assertViewHas('chart', fn ($chart) => $chart['in'] === 50 && $chart['out'] === 0);

        // Per gudang: transfer tetap terlihat sebagai keluar / masuk.
        $this->get("/dashboard?warehouse={$this->big->id}")->assertViewHas('chart', fn ($chart) => $chart['in'] === 50 && $chart['out'] === 20);
        $this->get("/dashboard?warehouse={$this->small->id}")->assertViewHas('chart', fn ($chart) => $chart['in'] === 20 && $chart['out'] === 0);

        // User yang hanya melihat gudang kecil tetap melihat barang masuk dari transfer.
        $staff = $this->smallWarehouseStaff();
        $this->actingAs($staff)->get('/dashboard')->assertViewHas('chart', fn ($chart) => $chart['in'] === 20);
    }

    public function test_duplicate_external_number_is_checked_across_all_warehouses(): void
    {
        $this->actingAs($this->admin);
        $this->draftOrder($this->big, 'WA-77')->assertSessionHasNoErrors();

        $staff = $this->smallWarehouseStaff();
        $this->actingAs($staff);
        $this->draftOrder($this->small, 'WA-77')->assertSessionHasErrors('external_number');
        $this->assertSame(1, SalesOrder::withoutGlobalScope('warehouse_access')->where('external_number', 'WA-77')->count());
    }
}
