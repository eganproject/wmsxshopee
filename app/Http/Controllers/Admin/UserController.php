<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PermissionGroups;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $users = User::query()
            ->with(['roles', 'creator'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search');
                $query->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"));
            })
            ->when($request->filled('role'), fn ($query) => $query->role($request->string('role')->toString()))
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->input('status') === 'active'))
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'roles' => Role::orderBy('name')->pluck('name'),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.users.create', $this->formData($request->user(), new User(['is_active' => true])));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $data = $this->validated($request, $actor);

        DB::transaction(function () use ($data, $actor) {
            $user = User::create([
                ...collect($data)->only(['name', 'username', 'email', 'phone', 'password'])->all(),
                'is_active' => $data['is_active'] ?? false,
                'created_by' => $actor->id,
            ]);

            $user->syncRoles(Role::whereIn('id', $data['roles'] ?? [])->get());
            $user->syncPermissions($data['permissions'] ?? []);
        });

        return redirect()->route('admin.users.index')
            ->with('success', "Akun {$data['name']} berhasil dibuat.");
    }

    public function edit(Request $request, User $user): View
    {
        $this->ensureCanManage($request->user(), $user);

        return view('admin.users.edit', $this->formData($request->user(), $user));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureCanManage($actor, $user);

        $data = $this->validated($request, $actor, $user);
        $isSelf = $actor->is($user);

        DB::transaction(function () use ($data, $user, $isSelf) {
            $user->fill(collect($data)->only(['name', 'username', 'email', 'phone'])->all());

            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }

            // Akun sendiri: status & hak akses tidak bisa diubah agar tidak terkunci.
            if (! $isSelf) {
                $user->is_active = $data['is_active'] ?? false;
                $user->syncRoles(Role::whereIn('id', $data['roles'] ?? [])->get());
                $user->syncPermissions($data['permissions'] ?? []);
            }

            $user->save();
        });

        return redirect()->route('admin.users.index')
            ->with('success', "Akun {$user->name} berhasil diperbarui.");
    }

    public function toggleStatus(Request $request, User $user): RedirectResponse
    {
        $this->ensureCanManage($request->user(), $user);
        abort_if($request->user()->is($user), 403, 'Anda tidak bisa menonaktifkan akun sendiri.');

        $user->update(['is_active' => ! $user->is_active]);

        return back()->with('success', $user->is_active
            ? "Akun {$user->name} diaktifkan."
            : "Akun {$user->name} dinonaktifkan.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->ensureCanManage($request->user(), $user);
        abort_if($request->user()->is($user), 403, 'Anda tidak bisa menghapus akun sendiri.');

        $user->delete();

        return redirect()->route('admin.users.index')
            ->with('success', "Akun {$user->name} berhasil dihapus.");
    }

    private function ensureCanManage(User $actor, User $target): void
    {
        abort_unless($actor->canManage($target), 403, 'Anda tidak memiliki akses untuk mengelola akun ini.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, User $actor, ?User $user = null): array
    {
        $assignableRoles = $actor->assignableRoles()->pluck('id');
        $grantable = $actor->grantablePermissions()->pluck('name');

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'alpha_dash', 'min:3', 'max:50', Rule::unique('users')->ignore($user)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::defaults()],
            'is_active' => ['nullable', 'boolean'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['integer', Rule::in($assignableRoles)],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in($grantable)],
        ], [
            'roles.*.in' => 'Anda tidak bisa memberikan role tersebut.',
            'permissions.*.in' => 'Anda tidak bisa memberikan permission yang tidak Anda miliki.',
        ], [
            'name' => 'nama',
            'phone' => 'no. telepon',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(User $actor, User $user): array
    {
        $grantable = $actor->grantablePermissions()->pluck('name');

        return [
            'user' => $user,
            'isSelf' => $actor->is($user),
            'roles' => $actor->assignableRoles(),
            'permissionGroups' => PermissionGroups::all($grantable),
            'selectedRoles' => old('roles', $user->exists ? $user->roles->pluck('id')->all() : []),
            'selectedPermissions' => old('permissions', $user->exists ? $user->getDirectPermissions()->pluck('name')->all() : []),
        ];
    }
}
