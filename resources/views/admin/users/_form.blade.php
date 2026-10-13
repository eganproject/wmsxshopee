@php
    $rolePermissions = $roles->mapWithKeys(fn ($role) => [$role->id => $role->permissions->pluck('name')]);
@endphp

<div class="space-y-6"
     x-data="{
        selectedRoles: @js(array_map('intval', $selectedRoles)),
        rolePermissions: @js($rolePermissions),
        fromRole(name) {
            return this.selectedRoles.some(id => (this.rolePermissions[id] ?? []).includes(name));
        },
     }">

    {{-- Informasi akun --}}
    <div class="card">
        <div class="border-b border-slate-100 px-5 py-3">
            <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                Informasi Akun
            </h2>
        </div>
        <div class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2">
            <x-input name="name" label="Nama Lengkap" icon="heroicon-o-user" :value="$user->name" placeholder="cth. Budi Santoso" required />
            <x-input name="username" label="Username" icon="heroicon-o-at-symbol" :value="$user->username" placeholder="cth. budi_santoso"
                     hint="Hanya huruf, angka, tanda - dan _ (tanpa spasi)." required />
            <x-input name="email" type="email" label="Email" icon="heroicon-o-envelope" :value="$user->email" placeholder="budi@perusahaan.com" required />
            <x-input name="phone" label="No. Telepon" icon="heroicon-o-phone" :value="$user->phone" placeholder="08xxxxxxxxxx" />
        </div>
    </div>

    {{-- Password --}}
    <div class="card">
        <div class="border-b border-slate-100 px-5 py-3">
            <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                Password
            </h2>
            @if ($user->exists)
                <p class="mt-0.5 text-sm text-slate-500">Kosongkan jika tidak ingin mengubah password.</p>
            @endif
        </div>
        <div class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2">
            <x-input name="password" type="password" label="Password" autocomplete="new-password"
                     hint="Minimal 8 karakter, kombinasi huruf & angka." :required="! $user->exists" />
            <x-input name="password_confirmation" type="password" label="Konfirmasi Password" autocomplete="new-password" :required="! $user->exists" />
        </div>
    </div>

    @if ($isSelf)
        <div class="flex items-start gap-3 rounded-xl bg-sky-50 p-4 text-sm text-sky-800 ring-1 ring-sky-200">
            <x-heroicon-o-information-circle class="size-5 shrink-0" />
            Anda sedang mengedit akun sendiri. Status, role, dan permission tidak bisa diubah sendiri untuk mencegah akun terkunci.
        </div>
    @else
        {{-- Status --}}
        <div class="card flex items-center justify-between gap-4 p-6">
            <div class="flex items-start gap-3">
                <x-heroicon-o-power class="size-6 shrink-0 text-slate-400" />
                <div>
                    <p class="font-semibold text-slate-900">Status Akun</p>
                    <p class="text-sm text-slate-500">Akun nonaktif tidak bisa login ke sistem.</p>
                </div>
            </div>
            <label class="relative inline-flex cursor-pointer items-center">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" class="peer sr-only" @checked(old('is_active', $user->is_active))>
                <span class="h-5 w-9 rounded-full bg-slate-300 transition peer-checked:bg-slate-900 peer-focus-visible:ring-2 peer-focus-visible:ring-slate-300 peer-focus-visible:ring-offset-2"></span>
                <span class="absolute left-0.5 size-4 rounded-full bg-white shadow-sm transition peer-checked:translate-x-4"></span>
            </label>
        </div>

        {{-- Role --}}
        <div class="card">
            <div class="border-b border-slate-100 px-5 py-3">
                <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                    Role
                </h2>
                <p class="mt-0.5 text-sm text-slate-500">User otomatis mendapatkan semua permission dari role yang dipilih.</p>
            </div>
            <div class="p-5">
                @error('roles.*')
                    <p class="mb-3 flex items-center gap-1 text-sm text-rose-600"><x-heroicon-o-exclamation-triangle class="size-4" /> {{ $message }}</p>
                @enderror
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @forelse ($roles as $role)
                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-3 transition hover:border-slate-300"
                               :class="selectedRoles.includes({{ $role->id }}) && 'border-slate-900 ring-1 ring-slate-900'">
                            <input type="checkbox" name="roles[]" value="{{ $role->id }}" class="form-checkbox mt-0.5" x-model.number="selectedRoles">
                            <span>
                                <span class="block text-sm font-medium text-slate-900">{{ $role->name }}</span>
                                <span class="block text-xs text-slate-500">
                                    @if ($role->name === config('permissions.super_admin_role'))
                                        Akses penuh ke semua fitur
                                    @else
                                        {{ $role->permissions->count() }} permission
                                    @endif
                                </span>
                            </span>
                        </label>
                    @empty
                        <p class="text-sm text-slate-500">Belum ada role yang dapat Anda berikan.</p>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Permission tambahan --}}
        <div class="card" x-data="{ open: @js(count($selectedPermissions) > 0) }">
            <button type="button" class="flex w-full items-center justify-between gap-4 px-5 py-3 text-left" @click="open = !open">
                <span>
                    <span class="flex items-center gap-2 font-semibold text-slate-900">
                        <x-heroicon-o-key class="size-5 text-slate-400" /> Permission Tambahan
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">opsional</span>
                    </span>
                    <span class="mt-0.5 block text-sm text-slate-500">Berikan akses khusus untuk user ini di luar permission dari role.</span>
                </span>
                <x-heroicon-o-chevron-down class="size-5 shrink-0 text-slate-400 transition" ::class="open && 'rotate-180'" />
            </button>
            <div x-show="open" x-collapse x-cloak>
                <div class="border-t border-slate-100 p-5">
                    @error('permissions.*')
                        <p class="mb-3 flex items-center gap-1 text-sm text-rose-600"><x-heroicon-o-exclamation-triangle class="size-4" /> {{ $message }}</p>
                    @enderror
                    <x-permission-matrix :groups="$permissionGroups" :selected="$selectedPermissions" inherited="fromRole" />
                </div>
            </div>
        </div>
    @endif
</div>
