<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function superAdmin(): User
    {
        return User::where('username', 'superadmin')->first();
    }

    public function test_super_admin_can_create_role_with_permissions(): void
    {
        $this->actingAs($this->superAdmin())->post('/admin/roles', [
            'name' => 'Staff Packing',
            'permissions' => ['dashboard.view', 'users.view'],
        ])->assertRedirect(route('admin.roles.index'));

        $role = Role::findByName('Staff Packing');
        $this->assertEqualsCanonicalizing(['dashboard.view', 'users.view'], $role->permissions->pluck('name')->all());
    }

    public function test_super_admin_role_cannot_be_modified_or_deleted(): void
    {
        $role = Role::findByName('Super Admin');
        $this->actingAs($this->superAdmin());

        $this->get("/admin/roles/{$role->id}/edit")->assertRedirect(route('admin.roles.index'));
        $this->put("/admin/roles/{$role->id}", ['name' => 'Hacked'])->assertForbidden();
        $this->delete("/admin/roles/{$role->id}")->assertForbidden();
    }

    public function test_role_in_use_cannot_be_deleted(): void
    {
        User::factory()->create()->assignRole('Staff Gudang');
        $role = Role::findByName('Staff Gudang');

        $this->actingAs($this->superAdmin())->delete("/admin/roles/{$role->id}")
            ->assertSessionHas('error');
        $this->assertModelExists($role);
    }

    public function test_role_editor_cannot_add_permissions_they_lack(): void
    {
        $editor = User::factory()->create();
        $editor->givePermissionTo(['roles.view', 'roles.edit']);
        $role = Role::findByName('Staff Gudang');
        $editor->givePermissionTo($role->permissions);

        $this->actingAs($editor)->put("/admin/roles/{$role->id}", [
            'name' => 'Staff Gudang',
            'permissions' => ['users.delete', ...$role->permissions->pluck('name')],
        ])->assertSessionHasErrors('permissions.0');

        $this->assertFalse($role->fresh()->hasPermissionTo('users.delete'));
    }
}
