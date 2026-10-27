<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $warehouse;

    private WarehouseLocation $bin;

    private WarehouseLocation $inactiveBin;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::create(['code' => 'JKT01', 'name' => 'Gudang Jakarta', 'is_active' => true]);
        $zone = $this->warehouse->zones()->create(['code' => 'A', 'name' => 'Penyimpanan A', 'type' => 'storage', 'is_active' => true]);
        $rack = $zone->racks()->create(['code' => 'A01', 'levels' => 3, 'is_active' => true]);
        $this->bin = $rack->locations()->create(['warehouse_id' => $this->warehouse->id, 'code' => 'A01-01-01', 'type' => 'bin', 'is_active' => true]);
        $this->inactiveBin = $rack->locations()->create(['warehouse_id' => $this->warehouse->id, 'code' => 'A01-01-02', 'type' => 'bin', 'is_active' => false]);

        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => true, 'is_active' => true]);
        $this->variant = $product->variants()->create(['sku' => 'KAOS-M', 'name' => 'M', 'barcode' => '8991001', 'is_active' => true]);

        app(StockService::class)->record(['type' => 'in', 'warehouse_id' => $this->warehouse->id, 'movement_date' => now()->toDateString()],
            [['product_variant_id' => $this->variant->id, 'to_location_id' => $this->bin->id, 'qty' => 10]]);
    }

    private function superAdmin(): User
    {
        return User::where('username', 'superadmin')->first();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $overrides
     */
    private function payload(array $items, array $overrides = []): array
    {
        return [
            'warehouse_id' => $this->warehouse->id,
            'adjustment_date' => now()->toDateString(),
            'reason' => 'lost',
            'reference' => 'BA-01',
            'notes' => 'Hitung ulang rak A',
            'items' => $items,
            ...$overrides,
        ];
    }

    private function line(int $qty, ?WarehouseLocation $location = null): array
    {
        return ['product_variant_id' => $this->variant->id, 'warehouse_location_id' => ($location ?? $this->bin)->id, 'qty' => $qty, 'notes' => null];
    }

    private function qty(?WarehouseLocation $location = null): int
    {
        return (int) Stock::where('product_variant_id', $this->variant->id)->where('warehouse_location_id', ($location ?? $this->bin)->id)->value('qty');
    }

    public function test_pages_render_and_menu_links_to_adjustments(): void
    {
        $this->actingAs($this->superAdmin());
        $this->post('/inventory/adjustments', $this->payload([$this->line(-2)]))->assertSessionHasNoErrors();
        $adjustment = StockAdjustment::sole();

        $this->get('/inventory/adjustments')->assertOk()->assertSee($adjustment->number)->assertSee('Barang hilang / kurang');
        $this->get('/inventory/adjustments/create')->assertOk()->assertSee('Simpan & Ajukan', false);
        $this->get("/inventory/adjustments/{$adjustment->id}")->assertOk()->assertSee('KAOS-M')->assertSee('Ajukan');
        $this->get("/inventory/adjustments/{$adjustment->id}/edit")->assertOk()->assertSee($adjustment->number);
        $this->get('/inventory/stocks')->assertOk()->assertSee(route('inventory.adjustments.index'), false);
        $this->get('/inventory/adjustments?export=xlsx')->assertOk()->assertDownload();
        $this->get('/inventory/adjustments?status=approved')->assertOk()->assertDontSee($adjustment->number);
        $this->get('/inventory/adjustments?reason=lost&search=KAOS-M')->assertOk()->assertSee($adjustment->number);
    }

    public function test_stock_changes_only_after_approval(): void
    {
        $this->actingAs($this->superAdmin());

        $this->post('/inventory/adjustments', $this->payload([$this->line(-3), array_merge($this->line(4), ['warehouse_location_id' => ''])]))
            ->assertSessionHasNoErrors();
        $adjustment = StockAdjustment::sole();

        $this->assertSame('draft', $adjustment->status);
        $this->assertMatchesRegularExpression('/^PNY\d{4}-001$/', $adjustment->number);
        $this->assertSame(10, $this->qty());
        $this->assertSame(1, StockMovement::count());

        $this->post("/inventory/adjustments/{$adjustment->id}/submit")->assertSessionHasNoErrors();
        $this->assertSame('pending', $adjustment->fresh()->status);
        $this->assertSame(10, $this->qty());

        // Saat menunggu approval dokumen terkunci untuk diubah / dihapus.
        $this->get("/inventory/adjustments/{$adjustment->id}/edit")->assertRedirect("/inventory/adjustments/{$adjustment->id}");
        $this->put("/inventory/adjustments/{$adjustment->id}", $this->payload([$this->line(-1)]))->assertSessionHasErrors('adjustment');
        $this->delete("/inventory/adjustments/{$adjustment->id}");
        $this->assertModelExists($adjustment);

        $this->post("/inventory/adjustments/{$adjustment->id}/approve")->assertSessionHasNoErrors();

        $adjustment->refresh();
        $this->assertSame('approved', $adjustment->status);
        $this->assertSame($this->superAdmin()->id, $adjustment->approved_by);
        $this->assertSame(7, $this->qty());
        $this->assertSame(4, (int) Stock::where('product_variant_id', $this->variant->id)->whereNull('warehouse_location_id')->value('qty'));
        $this->assertSame([10, 0], $adjustment->items->pluck('qty_before')->all());

        $movement = $adjustment->movement;
        $this->assertSame('adjustment', $movement->type);
        $this->assertSame($adjustment->number, $movement->reference);
        $this->assertStringContainsString('Barang hilang', $movement->notes);

        // Tidak bisa disetujui dua kali.
        $this->post("/inventory/adjustments/{$adjustment->id}/approve")->assertSessionHasErrors('adjustment');
        $this->assertSame(7, $this->qty());

        $this->get("/inventory/adjustments/{$adjustment->id}")->assertOk()->assertSee($movement->number)->assertSee('Stok Sebelum');
        $this->assertTrue(ActivityLog::where('subject_type', StockAdjustment::class)->where('event', 'status')->exists());
    }

    public function test_store_with_submit_action_goes_straight_to_pending(): void
    {
        $this->actingAs($this->superAdmin());
        $this->post('/inventory/adjustments', $this->payload([$this->line(2)], ['action' => 'submit']))->assertSessionHasNoErrors();

        $adjustment = StockAdjustment::sole();
        $this->assertSame('pending', $adjustment->status);
        $this->assertNotNull($adjustment->submitted_at);
        $this->assertSame(10, $this->qty());
    }

    public function test_reject_requires_reason_and_leaves_stock(): void
    {
        $this->actingAs($this->superAdmin());
        $this->post('/inventory/adjustments', $this->payload([$this->line(-5)], ['action' => 'submit']));
        $adjustment = StockAdjustment::sole();

        $this->post("/inventory/adjustments/{$adjustment->id}/reject", ['rejection_reason' => ''])->assertSessionHasErrors('rejection_reason');
        $this->post("/inventory/adjustments/{$adjustment->id}/reject", ['rejection_reason' => 'Hitung ulang dulu'])->assertSessionHasNoErrors();

        $adjustment->refresh();
        $this->assertSame('rejected', $adjustment->status);
        $this->assertSame('Hitung ulang dulu', $adjustment->rejection_reason);
        $this->assertSame(10, $this->qty());

        $this->post("/inventory/adjustments/{$adjustment->id}/approve")->assertSessionHasErrors('adjustment');
        $this->assertSame(10, $this->qty());
        $this->get("/inventory/adjustments/{$adjustment->id}")->assertOk()->assertSee('Hitung ulang dulu');
    }

    public function test_recall_returns_to_draft_for_editing(): void
    {
        $this->actingAs($this->superAdmin());
        $this->post('/inventory/adjustments', $this->payload([$this->line(-5)], ['action' => 'submit']));
        $adjustment = StockAdjustment::sole();

        $this->post("/inventory/adjustments/{$adjustment->id}/recall")->assertSessionHasNoErrors();
        $this->assertSame('draft', $adjustment->fresh()->status);

        $this->put("/inventory/adjustments/{$adjustment->id}", $this->payload([$this->line(-1)], ['reason' => 'input_error']))->assertSessionHasNoErrors();
        $adjustment->refresh();
        $this->assertSame('input_error', $adjustment->reason);
        $this->assertSame([-1], $adjustment->items->pluck('qty')->all());

        $this->delete("/inventory/adjustments/{$adjustment->id}")->assertRedirect('/inventory/adjustments');
        $this->assertModelMissing($adjustment);
    }

    public function test_approval_fails_when_stock_is_not_enough(): void
    {
        $this->actingAs($this->superAdmin());
        $this->post('/inventory/adjustments', $this->payload([$this->line(-8)], ['action' => 'submit']));
        $adjustment = StockAdjustment::sole();

        // Stok berkurang setelah diajukan: approval dihitung dari saldo terbaru.
        app(StockService::class)->record(['type' => 'adjustment', 'warehouse_id' => $this->warehouse->id, 'movement_date' => now()->toDateString()],
            [['product_variant_id' => $this->variant->id, 'to_location_id' => $this->bin->id, 'qty' => -5]]);

        $this->post("/inventory/adjustments/{$adjustment->id}/approve")
            ->assertRedirect("/inventory/adjustments/{$adjustment->id}")
            ->assertSessionHasErrors('items.0.qty');

        $this->assertSame('pending', $adjustment->fresh()->status);
        $this->assertSame(5, $this->qty());
        $this->get("/inventory/adjustments/{$adjustment->id}")->assertOk()->assertSee('Saldo saat ini tidak cukup');
    }

    public function test_validation_rules(): void
    {
        $this->actingAs($this->superAdmin());

        $this->post('/inventory/adjustments', $this->payload([$this->line(0)]))->assertSessionHasErrors('items.0.qty');
        $this->post('/inventory/adjustments', $this->payload([$this->line(1, $this->inactiveBin)]))->assertSessionHasErrors('items.0.warehouse_location_id');
        $this->post('/inventory/adjustments', $this->payload([$this->line(1)], ['reason' => 'other', 'notes' => '']))->assertSessionHasErrors('notes');
        $this->post('/inventory/adjustments', $this->payload([$this->line(1)], ['reason' => 'unknown']))->assertSessionHasErrors('reason');
        $this->post('/inventory/adjustments', $this->payload([$this->line(1)], ['adjustment_date' => now()->addDay()->toDateString()]))->assertSessionHasErrors('adjustment_date');
        $this->post('/inventory/adjustments', $this->payload([$this->line(1), $this->line(2)]))->assertSessionHasErrors('items.1.product_variant_id');
        $this->post('/inventory/adjustments', $this->payload([]))->assertSessionHasErrors('items');

        $this->assertSame(0, StockAdjustment::count());

        // Lokasi nonaktif boleh dikurangi (untuk mengosongkan).
        $this->post('/inventory/adjustments', $this->payload([$this->line(-1, $this->inactiveBin)]))->assertSessionHasNoErrors();
    }

    public function test_permissions_split_between_proposing_and_approving(): void
    {
        $this->actingAs($this->superAdmin());
        $this->post('/inventory/adjustments', $this->payload([$this->line(-1)], ['action' => 'submit']));
        $adjustment = StockAdjustment::sole();

        // Staff Gudang boleh membuat & mengajukan, tapi tidak menyetujui.
        $staff = User::factory()->create()->assignRole('Staff Gudang');
        $this->actingAs($staff);
        $this->get('/inventory/adjustments')->assertOk();
        $this->get("/inventory/adjustments/{$adjustment->id}")->assertOk()->assertDontSee('Setujui penyesuaian?');
        $this->post('/inventory/adjustments', $this->payload([$this->line(3)], ['action' => 'submit']))->assertSessionHasNoErrors();
        $this->post("/inventory/adjustments/{$adjustment->id}/approve")->assertForbidden();
        $this->post("/inventory/adjustments/{$adjustment->id}/reject", ['rejection_reason' => 'x'])->assertForbidden();
        $this->assertSame(10, $this->qty());

        // Pemegang hak setujui boleh menyetujui dokumen buatannya sendiri.
        $admin = User::factory()->create()->assignRole('Admin');
        $this->actingAs($admin);
        $this->post('/inventory/adjustments', $this->payload([$this->line(2)], ['action' => 'submit']))->assertSessionHasNoErrors();
        $own = StockAdjustment::latest('id')->first();
        $this->get("/inventory/adjustments/{$own->id}")->assertOk()->assertSee('Setujui penyesuaian?');
        $this->post("/inventory/adjustments/{$own->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame(12, $this->qty());

        // Approver melihat pengajuan yang menunggu di notifikasi.
        $this->get('/notifications')->assertOk()->assertSee('Penyesuaian stok menunggu approval')->assertSee($adjustment->number);

        $this->actingAs(User::factory()->create());
        $this->get('/inventory/adjustments')->assertForbidden();
        $this->get('/inventory/adjustments/create')->assertForbidden();
        $this->post('/inventory/adjustments', $this->payload([$this->line(1)]))->assertForbidden();
    }

    public function test_migration_moves_old_adjust_permission_holders(): void
    {
        $registrar = app(PermissionRegistrar::class);
        Permission::whereIn('name', ['adjustments.view', 'adjustments.create', 'adjustments.approve'])->delete();
        $old = Permission::findOrCreate('inventory.adjust', 'web');
        $role = Role::create(['name' => 'Kepala Gudang', 'guard_name' => 'web'])->givePermissionTo($old);
        $user = User::factory()->create()->givePermissionTo($old);
        $registrar->forgetCachedPermissions();

        (require database_path('migrations/2026_10_27_100100_move_adjust_permission_to_stock_adjustments.php'))->up();

        $this->assertFalse(Permission::where('name', 'inventory.adjust')->exists());
        $this->assertTrue($role->fresh()->hasPermissionTo('adjustments.approve'));
        $this->assertTrue($user->fresh()->hasDirectPermission('adjustments.create'));
        $staff = Role::findByName('Staff Gudang');
        $this->assertTrue($staff->hasPermissionTo('adjustments.create'));
        $this->assertFalse($staff->hasPermissionTo('adjustments.approve'));
    }
}
