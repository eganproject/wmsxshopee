<x-layouts.app title="Role & Permission">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('settings') }}" class="hover:text-slate-700">Pengaturan</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Role & Permission</span>
    </x-slot:breadcrumb>

    <x-page-header title="Role & Permission" description="Kelompokkan hak akses ke dalam role, lalu berikan ke user." icon="heroicon-o-shield-check">
        @can('roles.create')
            <a href="{{ route('admin.roles.create') }}" class="btn-primary"><x-heroicon-o-plus class="size-5" /> Buat Role</a>
        @endcan
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($roles as $role)
            @php($isSuper = $role->name === config('permissions.super_admin_role'))
            <div class="card flex flex-col p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <span @class([
                            'flex size-9 items-center justify-center rounded-lg',
                            'bg-slate-900 text-white' => $isSuper,
                            'bg-slate-100 text-slate-600' => ! $isSuper,
                        ])>
                            @if ($isSuper)
                                <x-heroicon-o-shield-exclamation class="size-5" />
                            @else
                                <x-heroicon-o-shield-check class="size-5" />
                            @endif
                        </span>
                        <div>
                            <h3 class="font-semibold text-slate-900">{{ $role->name }}</h3>
                            <p class="text-xs text-slate-500">
                                {{ $isSuper ? 'Akses penuh (otomatis)' : "{$role->permissions_count} dari {$totalPermissions} permission" }}
                            </p>
                        </div>
                    </div>

                    @unless ($isSuper)
                        <div class="flex gap-1">
                            @can('roles.edit')
                                <a href="{{ route('admin.roles.edit', $role) }}" class="btn-icon" title="Edit"><x-heroicon-o-pencil-square class="size-5" /></a>
                            @endcan
                            @can('roles.delete')
                                <form id="delete-role-{{ $role->id }}" method="POST" action="{{ route('admin.roles.destroy', $role) }}">
                                    @csrf @method('DELETE')
                                </form>
                                <button type="button" class="btn-icon hover:bg-rose-50 hover:text-rose-600" title="Hapus"
                                        @click="$dispatch('confirm', {
                                            form: 'delete-role-{{ $role->id }}',
                                            title: 'Hapus role?',
                                            message: @js("Role {$role->name} akan dihapus permanen."),
                                            confirmText: 'Hapus',
                                        })">
                                    <x-heroicon-o-trash class="size-5" />
                                </button>
                            @endcan
                        </div>
                    @endunless
                </div>

                @unless ($isSuper)
                    <div class="mt-5">
                        <div class="h-1.5 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full bg-violet-500" style="width: {{ $totalPermissions ? round($role->permissions_count / $totalPermissions * 100) : 0 }}%"></div>
                        </div>
                    </div>
                @endunless

                <div class="min-h-5 flex-1"></div>
                <div class="flex items-center gap-2 border-t border-slate-100 pt-4 text-sm text-slate-500">
                    <x-heroicon-o-user-group class="size-5 text-slate-400" />
                    {{ $role->users_count }} user
                    @can('users.view')
                        <a href="{{ route('admin.users.index', ['role' => $role->name]) }}" class="ml-auto text-sm text-slate-600 hover:text-slate-900">Lihat user</a>
                    @endcan
                </div>
            </div>
        @endforeach
    </div>
</x-layouts.app>
