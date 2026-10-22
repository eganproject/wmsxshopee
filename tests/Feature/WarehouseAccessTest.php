<?php

namespace Tests\Feature;

use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WarehouseAccessTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Warehouse $jakarta;

    private Warehouse $bandung;

    private WarehouseLocation $binJkt;

    private WarehouseLocation $binBdg;

    private ProductVariant $variant;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->jakarta, $this->binJkt] = $this->warehouse('JKT01', 'Gudang Jakarta');
        [$this->bandung, $this->binBdg] = $this->warehouse('BDG01', 'Gudang Bandung');

        $product = Product::create(['sku' => 'KAOS', 'name' => 'Kaos Polos', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'has_variants' => true, 'is_active' => true]);
        $this->variant = $product->variants()->create(['sku' => 'KAOS-M', 'name' => 'M', 'is_active' => true, 'min_stock' => 100]);
        $this->supplier = Supplier::create(['code' => 'SUP01', 'name' => 'CV Sumber Textile', 'is_active' => true]);

        foreach ([[$this->jakarta, $this->binJkt, 7], [$this->bandung, $this->binBdg, 30]] as [$warehouse, $bin, $qty]) {
            Stock::create(['product_variant_id' => $this->variant->id, 'warehouse_id' => $warehouse->id, 'warehouse_location_id' => $bin->id, 'qty' => $qty]);
        }
    }

    /**
     * @return array{Warehouse, WarehouseLocation}
     */
    private function warehouse(string $code, string $name): array
    {
        $warehouse = Warehouse::create(['code' => $code, 'name' => $name, 'is_active' => true]);
        $zone = $warehouse->zones()->create(['code' => 'A', 'name' => 'Zona A', 'type' => 'storage', 'is_active' => true]);
        $rack = $zone->racks()->create(['code' => 'A01', 'levels' => 3, 'is_active' => true]);
        $bin = $rack->locations()->create(['warehouse_id' => $warehouse->id, 'code' => "{$code}-A01-01", 'type' => 'bin', 'is_active' => true]);

        return [$warehouse, $bin];
    }

    private function superAdmin(): User
    {
        return User::where('username', 'superadmin')->first();
    }

    /**
     * Staf yang hanya boleh mengakses gudang Jakarta.
     */
    private function jakartaStaff(string $role = 'Staff Gudang'): User
    {
        $user = User::factory()->create()->assignRole($role);
        $user->syncWarehouseAccess(false, [$this->jakarta->id]);

        return $user->fresh();
    }

    private function receipt(Warehouse $warehouse, WarehouseLocation $bin): GoodsReceipt
    {
        $this->actingAs($this->superAdmin())->post('/inventory/receipts', [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $warehouse->id,
            'receipt_date' => now()->toDateString(),
            'action' => 'draft',
            'items' => [['product_variant_id' => $this->variant->id, 'warehouse_location_id' => $bin->id, 'qty_received' => 3]],
        ])->assertSessionHasNoErrors();

        return GoodsReceipt::withoutGlobalScopes()->latest('id')->first();
    }

    public function test_existing_and_new_users_default_to_all_warehouses(): void
    {
        $user = User::factory()->create()->assignRole('Staff Gudang');

        $this->assertTrue($user->fresh()->all_warehouses);
        $this->actingAs($user)->get('/master/warehouses')->assertOk()->assertSee('JKT01')->assertSee('BDG01');
    }

    public function test_limited_user_only_sees_assigned_warehouses(): void
    {
        $this->actingAs($this->jakartaStaff());

        $this->get('/master/warehouses')->assertOk()->assertSee('JKT01')->assertDontSee('BDG01');
        $this->get('/master/locations')->assertOk()->assertSee('JKT01-A01-01')->assertDontSee('BDG01-A01-01');
        $this->get('/inventory/stocks?view=location')->assertOk()->assertSee('JKT01-A01-01')->assertDontSee('BDG01-A01-01');
        $this->get('/inventory/receipts/create')->assertOk()->assertSee('Gudang Jakarta')->assertDontSee('Gudang Bandung');

        $this->assertSame([$this->jakarta->id], Warehouse::pluck('id')->all());
        $this->assertSame(7, (int) Stock::sum('qty'));
    }

    public function test_limited_user_cannot_open_other_warehouse_documents(): void
    {
        $own = $this->receipt($this->jakarta, $this->binJkt);
        $other = $this->receipt($this->bandung, $this->binBdg);

        $this->actingAs($this->jakartaStaff());

        $this->get("/inventory/receipts/{$own->id}")->assertOk();
        $this->get("/inventory/receipts/{$other->id}")->assertNotFound();
        $this->put("/inventory/receipts/{$other->id}", [])->assertNotFound();
        $this->get('/inventory/receipts')->assertOk()->assertSee($own->number)->assertDontSee($other->number);
    }

    public function test_limited_user_cannot_post_to_other_warehouse(): void
    {
        $this->actingAs($this->jakartaStaff());

        $this->post('/inventory/receipts', [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->bandung->id,
            'receipt_date' => now()->toDateString(),
            'action' => 'draft',
            'items' => [['product_variant_id' => $this->variant->id, 'warehouse_location_id' => $this->binBdg->id, 'qty_received' => 3]],
        ])->assertSessionHasErrors('warehouse_id');

        $this->assertSame(0, GoodsReceipt::withoutGlobalScopes()->count());
    }

    public function test_min_stock_report_only_counts_accessible_warehouses(): void
    {
        $role = Role::create(['name' => 'Supervisor'])->givePermissionTo(['reports.view', 'inventory.view']);
        $user = User::factory()->create()->assignRole($role);
        $user->syncWarehouseAccess(false, [$this->jakarta->id]);

        $this->actingAs($user->fresh())->get('/reports/min-stock')->assertOk()
            ->assertViewHas('rows', fn ($rows) => (int) $rows->first()->on_hand === 7);
    }

    public function test_admin_assigns_warehouses_on_user_form(): void
    {
        $this->actingAs($this->superAdmin());
        $this->get('/admin/users/create')->assertOk()->assertSee('Akses Gudang');

        $payload = [
            'name' => 'Budi', 'username' => 'budi', 'email' => 'budi@example.com',
            'password' => 'rahasia123', 'password_confirmation' => 'rahasia123', 'is_active' => '1',
            'roles' => [Role::where('name', 'Staff Gudang')->value('id')],
            'all_warehouses' => '0',
        ];

        $this->post('/admin/users', $payload)->assertSessionHasErrors('warehouses');

        $this->post('/admin/users', [...$payload, 'warehouses' => [$this->bandung->id]])->assertSessionHasNoErrors();

        $budi = User::where('username', 'budi')->first();
        $this->assertFalse($budi->all_warehouses);
        $this->assertSame([$this->bandung->id], $budi->accessibleWarehouseIds());
        $this->get('/admin/users')->assertOk()->assertSee('BDG01');
    }

    public function test_limited_admin_cannot_grant_more_warehouses_than_own(): void
    {
        $role = Role::create(['name' => 'Kepala Gudang'])->givePermissionTo(['users.view', 'users.create', 'users.edit']);
        $admin = $this->jakartaStaff('Kepala Gudang');
        $this->actingAs($admin);

        $payload = [
            'name' => 'Sari', 'username' => 'sari', 'email' => 'sari@example.com',
            'password' => 'rahasia123', 'password_confirmation' => 'rahasia123', 'is_active' => '1',
        ];

        $this->post('/admin/users', [...$payload, 'all_warehouses' => '1'])->assertSessionHasErrors('all_warehouses');
        $this->post('/admin/users', [...$payload, 'all_warehouses' => '0', 'warehouses' => [$this->bandung->id]])->assertSessionHasErrors('warehouses.0');
        $this->post('/admin/users', [...$payload, 'all_warehouses' => '0', 'warehouses' => [$this->jakarta->id]])->assertSessionHasNoErrors();

        // User dengan akses semua gudang tidak bisa dikelola admin terbatas.
        $wide = User::factory()->create();
        $this->get("/admin/users/{$wide->id}/edit")->assertForbidden();
        $this->get('/admin/users/'.User::where('username', 'sari')->value('id').'/edit')->assertOk();
    }

    public function test_limited_user_gets_access_to_warehouse_they_create(): void
    {
        $role = Role::create(['name' => 'Admin Master'])->givePermissionTo(['warehouses.view', 'warehouses.create']);
        $user = $this->jakartaStaff('Admin Master');

        $this->actingAs($user)->post('/master/warehouses', ['code' => 'SBY01', 'name' => 'Gudang Surabaya', 'is_active' => '1'])
            ->assertSessionHasNoErrors();

        $this->get('/master/warehouses')->assertSee('SBY01')->assertSee('JKT01')->assertDontSee('BDG01');
    }
}
