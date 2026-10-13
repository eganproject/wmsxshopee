<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseRack;
use App\Models\WarehouseZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseMasterTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function superAdmin(): User
    {
        return User::where('username', 'superadmin')->first();
    }

    /**
     * @return array{Warehouse, WarehouseZone, WarehouseRack}
     */
    private function structure(): array
    {
        $warehouse = Warehouse::create(['code' => 'JKT01', 'name' => 'Gudang Jakarta', 'is_active' => true]);
        $zone = $warehouse->zones()->create(['code' => 'A', 'name' => 'Penyimpanan A', 'type' => 'storage', 'is_active' => true]);
        $rack = $zone->racks()->create(['code' => 'A01', 'levels' => 3, 'is_active' => true]);

        return [$warehouse, $zone, $rack];
    }

    public function test_all_master_pages_render_for_super_admin(): void
    {
        [$warehouse, $zone, $rack] = $this->structure();
        $location = $rack->locations()->create(['warehouse_id' => $warehouse->id, 'code' => 'A01-01-01', 'type' => 'bin', 'is_active' => true]);
        $this->actingAs($this->superAdmin());

        foreach ([
            '/master/warehouses', '/master/warehouses/create', "/master/warehouses/{$warehouse->id}/edit",
            '/master/zones', '/master/zones/create', "/master/zones/{$zone->id}/edit",
            '/master/racks', '/master/racks/create', "/master/racks/{$rack->id}/edit",
            '/master/locations', '/master/locations/create', "/master/locations/{$location->id}/edit",
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_staff_can_view_but_not_change_master_data(): void
    {
        [$warehouse] = $this->structure();
        $staff = User::factory()->create()->assignRole('Staff Gudang');

        $this->actingAs($staff)->get('/master/warehouses')->assertOk()->assertSee('JKT01');
        $this->get('/master/warehouses/create')->assertForbidden();
        $this->post('/master/warehouses', ['code' => 'X', 'name' => 'X'])->assertForbidden();
        $this->delete("/master/warehouses/{$warehouse->id}")->assertForbidden();
        $this->get('/master/zones/create')->assertForbidden();
    }

    public function test_user_without_permission_cannot_open_master_data(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/master/warehouses')->assertForbidden();
        $this->get('/master/locations')->assertForbidden();
    }

    public function test_can_create_warehouse_with_uppercased_unique_code(): void
    {
        $this->actingAs($this->superAdmin());

        $this->post('/master/warehouses', [
            'code' => 'sby01',
            'name' => 'Gudang Surabaya',
            'city' => 'Surabaya',
            'is_active' => '1',
        ])->assertRedirect(route('master.warehouses.index'));

        $this->assertDatabaseHas('warehouses', ['code' => 'SBY01', 'city' => 'Surabaya', 'is_active' => true]);

        $this->post('/master/warehouses', ['code' => 'SBY01', 'name' => 'Duplikat'])->assertSessionHasErrors('code');
    }

    public function test_zone_code_is_unique_per_warehouse_only(): void
    {
        [$warehouse] = $this->structure();
        $other = Warehouse::create(['code' => 'BDG01', 'name' => 'Gudang Bandung']);
        $this->actingAs($this->superAdmin());

        $this->post('/master/zones', ['warehouse_id' => $warehouse->id, 'code' => 'A', 'name' => 'Dobel', 'type' => 'storage'])
            ->assertSessionHasErrors('code');

        $this->post('/master/zones', ['warehouse_id' => $other->id, 'code' => 'a', 'name' => 'Zona A Bandung', 'type' => 'picking'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('warehouse_zones', ['warehouse_id' => $other->id, 'code' => 'A', 'type' => 'picking']);
    }

    public function test_creating_rack_can_generate_bin_locations(): void
    {
        [$warehouse, $zone] = $this->structure();
        $this->actingAs($this->superAdmin());

        $this->post('/master/racks', [
            'warehouse_zone_id' => $zone->id,
            'code' => 'b02',
            'levels' => 2,
            'is_active' => '1',
            'generate_locations' => '1',
            'positions' => 3,
        ])->assertSessionHasNoErrors();

        $rack = WarehouseRack::where('code', 'B02')->first();
        $this->assertSame(6, $rack->locations()->count());
        $this->assertDatabaseHas('warehouse_locations', [
            'warehouse_id' => $warehouse->id, 'warehouse_rack_id' => $rack->id, 'code' => 'B02-02-03', 'level' => 2, 'position' => 3,
        ]);

        // Generate ulang setelah level ditambah hanya membuat lokasi yang belum ada.
        $this->put("/master/racks/{$rack->id}", [
            'warehouse_zone_id' => $zone->id,
            'code' => 'B02',
            'levels' => 3,
            'is_active' => '1',
            'generate_locations' => '1',
            'positions' => 3,
        ])->assertSessionHasNoErrors();

        $this->assertSame(9, $rack->locations()->count());
    }

    public function test_rack_code_must_be_unique_across_zones_in_same_warehouse(): void
    {
        [$warehouse] = $this->structure();
        $zoneB = $warehouse->zones()->create(['code' => 'B', 'name' => 'Zona B', 'type' => 'storage']);
        $this->actingAs($this->superAdmin());

        $this->post('/master/racks', ['warehouse_zone_id' => $zoneB->id, 'code' => 'A01', 'levels' => 1])
            ->assertSessionHasErrors('code');
    }

    public function test_can_create_location_manually(): void
    {
        [$warehouse, , $rack] = $this->structure();
        $this->actingAs($this->superAdmin());

        $this->post('/master/locations', [
            'warehouse_rack_id' => $rack->id,
            'code' => 'a01-03-01',
            'level' => 3,
            'position' => 1,
            'type' => 'pallet',
            'max_qty' => 100,
            'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('warehouse_locations', [
            'warehouse_id' => $warehouse->id, 'code' => 'A01-03-01', 'type' => 'pallet', 'max_qty' => 100,
        ]);

        // Level tidak boleh melebihi jumlah level rak.
        $this->post('/master/locations', [
            'warehouse_rack_id' => $rack->id, 'code' => 'A01-09-01', 'level' => 9, 'type' => 'bin',
        ])->assertSessionHasErrors('level');
    }

    public function test_parent_with_children_cannot_be_deleted(): void
    {
        [$warehouse, $zone, $rack] = $this->structure();
        $location = $rack->locations()->create(['warehouse_id' => $warehouse->id, 'code' => 'A01-01-01', 'type' => 'bin']);
        $this->actingAs($this->superAdmin());

        $this->delete("/master/warehouses/{$warehouse->id}")->assertSessionHas('error');
        $this->delete("/master/zones/{$zone->id}")->assertSessionHas('error');
        $this->delete("/master/racks/{$rack->id}")->assertSessionHas('error');
        $this->assertModelExists($warehouse);

        // Hapus dari bawah ke atas berhasil.
        $this->delete("/master/locations/{$location->id}");
        $this->delete("/master/racks/{$rack->id}");
        $this->delete("/master/zones/{$zone->id}");
        $this->delete("/master/warehouses/{$warehouse->id}")->assertRedirect(route('master.warehouses.index'));

        $this->assertModelMissing($warehouse);
        $this->assertSame(0, WarehouseLocation::count());
    }
}
