<x-layouts.app title="Manajemen User">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Administrasi</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Manajemen User</span>
    </x-slot:breadcrumb>

    <x-page-header title="Manajemen User" description="Kelola akun dan hak akses setiap pengguna." icon="heroicon-o-users">
        @can('users.create')
            <a href="{{ route('admin.users.create') }}" class="btn-primary">
                <x-heroicon-o-user-plus class="size-5" /> Buat Akun
            </a>
        @endcan
    </x-page-header>

    <div class="card">
        {{-- Filter --}}
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 md:flex-row md:items-center">
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari nama, username, atau email..."
                       class="form-input pl-8">
            </div>
            <select name="role" class="form-input md:w-48" onchange="this.form.submit()">
                <option value="">Semua role</option>
                @foreach ($roles as $role)
                    <option value="{{ $role }}" @selected(request('role') === $role)>{{ $role }}</option>
                @endforeach
            </select>
            <select name="status" class="form-input md:w-40" onchange="this.form.submit()">
                <option value="">Semua status</option>
                <option value="active" @selected(request('status') === 'active')>Aktif</option>
                <option value="inactive" @selected(request('status') === 'inactive')>Nonaktif</option>
            </select>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'role', 'status']))
                    <a href="{{ route('admin.users.index') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        {{-- Tabel --}}
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">User</th>
                        <th class="h-8 px-4 py-0 font-normal">Role</th>
                        <th class="h-8 px-4 py-0 font-normal">Status</th>
                        <th class="h-8 px-4 py-0 font-normal">Login Terakhir</th>
                        <th class="h-8 px-4 py-0 font-normal text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($users as $user)
                        @php($manageable = auth()->user()->canManage($user))
                        @php($isSelf = auth()->user()->is($user))
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5">
                                <div class="flex items-center gap-3">
                                    <x-avatar :user="$user" class="size-10 text-sm" />
                                    <div class="min-w-0">
                                        <p class="flex items-center gap-1.5 font-medium text-slate-900">
                                            {{ $user->name }}
                                            @if ($isSelf)
                                                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-500 uppercase">Anda</span>
                                            @endif
                                        </p>
                                        <p class="truncate text-xs text-slate-500">{{ '@'.$user->username }} · {{ $user->email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-2.5">
                                <div class="flex flex-wrap gap-1.5">
                                    @forelse ($user->roles as $role)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700">
                                            <x-heroicon-o-shield-check class="size-3.5" /> {{ $role->name }}
                                        </span>
                                    @empty
                                        <span class="text-xs text-slate-400">—</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="px-4 py-2.5">
                                @if ($user->is_active)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
                                        <span class="size-1.5 rounded-full bg-emerald-500"></span> Aktif
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">
                                        <span class="size-1.5 rounded-full bg-slate-400"></span> Nonaktif
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-slate-500">
                                {{ $user->last_login_at?->diffForHumans() ?? 'Belum pernah' }}
                            </td>
                            <td class="px-4 py-2.5">
                                <div class="flex items-center justify-end gap-1">
                                    @if ($manageable)
                                        @can('users.edit')
                                            <a href="{{ route('admin.users.edit', $user) }}" class="btn-icon" title="Edit">
                                                <x-heroicon-o-pencil-square class="size-5" />
                                            </a>
                                            @unless ($isSelf)
                                                <form id="status-{{ $user->id }}" method="POST" action="{{ route('admin.users.status', $user) }}">
                                                    @csrf @method('PATCH')
                                                </form>
                                                <button type="button" class="btn-icon {{ $user->is_active ? 'hover:text-amber-600' : 'hover:text-emerald-600' }}"
                                                        title="{{ $user->is_active ? 'Nonaktifkan' : 'Aktifkan' }}"
                                                        @click="$dispatch('confirm', {
                                                            form: 'status-{{ $user->id }}',
                                                            variant: 'warning',
                                                            title: @js($user->is_active ? 'Nonaktifkan akun?' : 'Aktifkan akun?'),
                                                            message: @js($user->is_active ? "{$user->name} tidak akan bisa login sampai diaktifkan kembali." : "{$user->name} akan bisa login kembali."),
                                                            confirmText: @js($user->is_active ? 'Nonaktifkan' : 'Aktifkan'),
                                                        })">
                                                    @if ($user->is_active)
                                                        <x-heroicon-o-no-symbol class="size-5" />
                                                    @else
                                                        <x-heroicon-o-power class="size-5" />
                                                    @endif
                                                </button>
                                            @endunless
                                        @endcan
                                        @can('users.delete')
                                            @unless ($isSelf)
                                                <form id="delete-{{ $user->id }}" method="POST" action="{{ route('admin.users.destroy', $user) }}">
                                                    @csrf @method('DELETE')
                                                </form>
                                                <button type="button" class="btn-icon hover:bg-rose-50 hover:text-rose-600" title="Hapus"
                                                        @click="$dispatch('confirm', {
                                                            form: 'delete-{{ $user->id }}',
                                                            title: 'Hapus akun?',
                                                            message: @js("Akun {$user->name} akan dihapus permanen. Tindakan ini tidak bisa dibatalkan."),
                                                            confirmText: 'Hapus',
                                                        })">
                                                    <x-heroicon-o-trash class="size-5" />
                                                </button>
                                            @endunless
                                        @endcan
                                    @else
                                        <span class="inline-flex items-center gap-1 text-xs text-slate-400" title="Hak akses user ini melebihi akses Anda">
                                            <x-heroicon-o-lock-closed class="size-4" /> Terkunci
                                        </span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-16 text-center">
                                <x-heroicon-o-users class="mx-auto size-12 text-slate-300" />
                                <p class="mt-3 font-medium text-slate-700">Tidak ada user ditemukan</p>
                                <p class="text-sm text-slate-500">Coba ubah kata kunci atau filter pencarian.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($users->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $users->links() }}</div>
        @endif
    </div>
</x-layouts.app>
