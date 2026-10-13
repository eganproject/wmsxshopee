<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Activity;
use App\Support\PermissionGroups;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(): View
    {
        return view('admin.roles.index', [
            'roles' => Role::withCount(['users', 'permissions'])->orderBy('name')->get(),
            'totalPermissions' => PermissionGroups::all()->sum(fn ($group) => $group['permissions']->count()),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.roles.create', $this->formData($request->user(), new Role));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
        $role->syncPermissions($data['permissions'] ?? []);

        Activity::record('created', 'roles', "Role {$role->name} dibuat", $role, [], $this->snapshot($role));

        return redirect()->route('admin.roles.index')
            ->with('success', "Role {$role->name} berhasil dibuat.");
    }

    public function edit(Request $request, Role $role): View|RedirectResponse
    {
        if ($this->isSuperAdminRole($role)) {
            return redirect()->route('admin.roles.index')
                ->with('info', 'Role Super Admin otomatis memiliki semua akses dan tidak bisa diubah.');
        }

        $this->ensureCanManage($request->user(), $role);

        return view('admin.roles.edit', $this->formData($request->user(), $role));
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_if($this->isSuperAdminRole($role), 403, 'Role Super Admin tidak bisa diubah.');
        $this->ensureCanManage($request->user(), $role);

        $data = $this->validated($request, $role);

        $before = $this->snapshot($role);

        $role->update(['name' => $data['name']]);
        $role->syncPermissions($data['permissions'] ?? []);

        [$old, $new] = Activity::diff($before, $this->snapshot($role));

        if ($old || $new) {
            Activity::record('updated', 'roles', "Role {$role->name} diubah", $role, $old, $new);
        }

        return redirect()->route('admin.roles.index')
            ->with('success', "Role {$role->name} berhasil diperbarui.");
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        abort_if($this->isSuperAdminRole($role), 403, 'Role Super Admin tidak bisa dihapus.');
        $this->ensureCanManage($request->user(), $role);

        if ($role->users()->exists()) {
            return back()->with('error', "Role {$role->name} masih digunakan oleh user. Pindahkan user terlebih dahulu.");
        }

        $snapshot = $this->snapshot($role);
        $role->delete();

        Activity::record('deleted', 'roles', "Role {$role->name} dihapus", $role, $snapshot, []);

        return redirect()->route('admin.roles.index')
            ->with('success', "Role {$role->name} berhasil dihapus.");
    }

    /**
     * Isi role untuk log aktivitas; role milik Spatie sehingga dicatat manual.
     *
     * @return array{Nama: string, Permission: array<int, string>}
     */
    private function snapshot(Role $role): array
    {
        return [
            'Nama' => $role->name,
            'Permission' => $role->permissions()->orderBy('name')->pluck('name')->all(),
        ];
    }

    private function isSuperAdminRole(Role $role): bool
    {
        return $role->name === config('permissions.super_admin_role');
    }

    private function ensureCanManage(User $actor, Role $role): void
    {
        $outsideActor = $role->permissions->pluck('name')
            ->diff($actor->grantablePermissions()->pluck('name'));

        abort_unless($outsideActor->isEmpty(), 403, 'Role ini memiliki akses yang melebihi akses Anda.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles')->ignore($role)],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in($request->user()->grantablePermissions()->pluck('name'))],
        ], [
            'permissions.*.in' => 'Anda tidak bisa memberikan permission yang tidak Anda miliki.',
        ], [
            'name' => 'nama role',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(User $actor, Role $role): array
    {
        return [
            'role' => $role,
            'permissionGroups' => PermissionGroups::all($actor->grantablePermissions()->pluck('name')),
            'selectedPermissions' => old('permissions', $role->exists ? $role->permissions->pluck('name')->all() : []),
        ];
    }
}
