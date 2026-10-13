<div class="space-y-6">
    <div class="card p-6">
        <x-input name="name" label="Nama Role" icon="heroicon-o-shield-check" :value="$role->name" placeholder="cth. Staff Packing" required />
    </div>

    <div class="card">
        <div class="border-b border-slate-100 px-6 py-4">
            <h2 class="flex items-center gap-2 font-semibold text-slate-900">
                <x-heroicon-o-key class="size-5 text-slate-400" /> Permission
            </h2>
            <p class="mt-0.5 text-sm text-slate-500">Centang hak akses yang dimiliki role ini.</p>
        </div>
        <div class="p-6">
            @error('permissions.*')
                <p class="mb-3 flex items-center gap-1 text-sm text-rose-600"><x-heroicon-o-exclamation-triangle class="size-4" /> {{ $message }}</p>
            @enderror
            <x-permission-matrix :groups="$permissionGroups" :selected="$selectedPermissions" />
        </div>
    </div>
</div>
