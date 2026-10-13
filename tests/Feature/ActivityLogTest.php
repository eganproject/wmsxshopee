<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function superAdmin(): User
    {
        return User::where('username', 'superadmin')->first();
    }

    private function lastLog(): ActivityLog
    {
        return ActivityLog::latest('id')->first();
    }

    public function test_login_logout_and_failed_login_are_logged_without_password(): void
    {
        $this->post('/login', ['login' => 'superadmin', 'password' => 'salah-sekali'])->assertSessionHasErrors('login');

        $failed = $this->lastLog();
        $this->assertSame('login_failed', $failed->event);
        $this->assertSame('auth', $failed->module);
        $this->assertSame('Password salah', $failed->properties['new']['Alasan']);
        $this->assertStringNotContainsString('salah-sekali', json_encode($failed->toArray()));

        $this->post('/login', ['login' => 'superadmin', 'password' => 'password'])->assertRedirect('/dashboard');

        $login = $this->lastLog();
        $this->assertSame('login', $login->event);
        $this->assertSame($this->superAdmin()->id, $login->user_id);
        $this->assertNotNull($login->ip_address);

        $this->post('/logout');
        $this->assertSame('logout', $this->lastLog()->event);
    }

    public function test_master_data_create_update_delete_record_before_and_after_values(): void
    {
        $this->actingAs($this->superAdmin());

        $this->post('/master/suppliers', ['code' => 'SUP01', 'name' => 'CV Sumber', 'city' => 'Bandung', 'is_active' => 1])->assertSessionHasNoErrors();
        $supplier = Supplier::firstWhere('code', 'SUP01');

        $created = $this->lastLog();
        $this->assertSame(['created', 'suppliers'], [$created->event, $created->module]);
        $this->assertSame('CV Sumber', $created->properties['new']['Nama']);
        $this->assertSame($this->superAdmin()->id, $created->user_id);

        $this->put("/master/suppliers/{$supplier->id}", ['code' => 'SUP01', 'name' => 'CV Sumber Jaya', 'city' => 'Bandung', 'is_active' => 1])->assertSessionHasNoErrors();

        $updated = $this->lastLog();
        $this->assertSame('updated', $updated->event);
        $this->assertSame(['Nama' => 'CV Sumber'], $updated->properties['old']);
        $this->assertSame(['Nama' => 'CV Sumber Jaya'], $updated->properties['new']);

        $this->put("/master/suppliers/{$supplier->id}", ['code' => 'SUP01', 'name' => 'CV Sumber Jaya', 'city' => 'Bandung'])->assertSessionHasNoErrors();
        $this->assertSame('status', $this->lastLog()->event);
        $this->assertStringContainsString('dinonaktifkan', $this->lastLog()->description);

        $this->delete("/master/suppliers/{$supplier->id}");

        $deleted = $this->lastLog();
        $this->assertSame('deleted', $deleted->event);
        $this->assertSame('CV Sumber Jaya', $deleted->properties['old']['Nama']);
        $this->assertSame($supplier->id, $deleted->subject_id);
    }

    public function test_document_edit_is_one_entry_with_item_changes_and_status_change_is_logged(): void
    {
        $this->actingAs($this->superAdmin());

        $warehouse = Warehouse::create(['code' => 'JKT01', 'name' => 'Gudang Jakarta', 'is_active' => true]);
        $zone = $warehouse->zones()->create(['code' => 'A', 'name' => 'Penyimpanan A', 'type' => 'storage', 'is_active' => true]);
        $rack = $zone->racks()->create(['code' => 'A01', 'levels' => 3, 'is_active' => true]);
        $bin = $rack->locations()->create(['warehouse_id' => $warehouse->id, 'code' => 'A01-01-01', 'type' => 'bin', 'is_active' => true]);
        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => true, 'is_active' => true]);
        $variant = $product->variants()->create(['sku' => 'KAOS-M', 'name' => 'M', 'is_active' => true]);
        $supplier = Supplier::create(['code' => 'SUP01', 'name' => 'CV Sumber', 'is_active' => true]);

        $payload = fn (int $qty) => [
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'receipt_date' => now()->toDateString(),
            'action' => 'draft',
            'items' => [['product_variant_id' => $variant->id, 'warehouse_location_id' => $bin->id, 'qty_received' => $qty]],
        ];

        $this->post('/inventory/receipts', $payload(10))->assertSessionHasNoErrors();
        $receipt = GoodsReceipt::first();

        $created = ActivityLog::where('subject_type', $receipt->getMorphClass())->where('event', 'created')->sole();
        $this->assertSame(['KAOS-M · terima 10 · A01-01-01'], $created->properties['new']['Item']);
        $this->assertSame('SUP01 · CV Sumber', $created->properties['new']['Supplier']);

        $before = ActivityLog::count();
        $this->put("/inventory/receipts/{$receipt->id}", $payload(12))->assertSessionHasNoErrors();

        $this->assertSame($before + 1, ActivityLog::count());
        $updated = $this->lastLog();
        $this->assertSame('updated', $updated->event);
        $this->assertSame(['KAOS-M · terima 10 · A01-01-01'], $updated->properties['old']['Item']);
        $this->assertSame(['KAOS-M · terima 12 · A01-01-01'], $updated->properties['new']['Item']);

        $this->post("/inventory/receipts/{$receipt->id}/receive")->assertSessionHasNoErrors();

        $status = ActivityLog::where('subject_type', $receipt->getMorphClass())->where('event', 'status')->sole();
        $this->assertSame("Penerimaan Barang {$receipt->number}: Draft → Diterima", $status->description);

        // Riwayat tampil di halaman detail.
        $this->get("/inventory/receipts/{$receipt->id}")
            ->assertOk()
            ->assertSee('Riwayat')
            ->assertSee("Penerimaan Barang {$receipt->number}: Draft → Diterima");
    }

    public function test_role_permission_changes_are_logged(): void
    {
        $this->actingAs($this->superAdmin());

        $this->post('/admin/roles', ['name' => 'Packer', 'permissions' => ['sales.view']])->assertSessionHasNoErrors();
        $role = Role::findByName('Packer');

        $this->put("/admin/roles/{$role->id}", ['name' => 'Packer', 'permissions' => ['sales.view', 'sales.fulfill']])->assertSessionHasNoErrors();

        $log = $this->lastLog();
        $this->assertSame(['updated', 'roles'], [$log->event, $log->module]);
        $this->assertSame(['sales.view'], $log->properties['old']['Permission']);
        $this->assertSame(['sales.fulfill', 'sales.view'], $log->properties['new']['Permission']);
    }

    public function test_bulk_bin_generation_is_one_summary_entry(): void
    {
        $this->actingAs($this->superAdmin());

        $warehouse = Warehouse::create(['code' => 'JKT01', 'name' => 'Gudang Jakarta', 'is_active' => true]);
        $zone = $warehouse->zones()->create(['code' => 'A', 'name' => 'Penyimpanan A', 'type' => 'storage', 'is_active' => true]);
        $before = ActivityLog::count();

        $this->post('/master/racks', ['warehouse_zone_id' => $zone->id, 'code' => 'B01', 'levels' => 2, 'is_active' => 1, 'generate_locations' => 1, 'positions' => 3])
            ->assertSessionHasNoErrors();

        $this->assertSame(6, WarehouseLocation::count());
        $this->assertSame($before + 2, ActivityLog::count());
        $this->assertSame('6 lokasi bin dibuat untuk rak B01', $this->lastLog()->description);
    }

    public function test_activity_page_filters_and_requires_permission(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        Supplier::create(['code' => 'SUP01', 'name' => 'CV Sumber', 'is_active' => true]);

        $this->get('/admin/activity?module=suppliers&user='.$admin->id)
            ->assertOk()
            ->assertSee('Supplier SUP01 dibuat')
            ->assertDontSee('superadmin login');

        $this->get('/admin/activity?module=auth')->assertOk()->assertDontSee('Supplier SUP01 dibuat');
        $this->get('/admin/activity?from='.now()->addDay()->toDateString())->assertOk()->assertSee('Belum ada aktivitas');

        $staff = User::factory()->create(['is_active' => true]);
        $staff->assignRole('Staff Gudang');
        $this->actingAs($staff)->get('/admin/activity')->assertForbidden();
    }
}
