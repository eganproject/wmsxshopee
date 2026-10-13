<x-layouts.app title="Satuan">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Master Data</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Satuan</span>
    </x-slot:breadcrumb>

    <x-page-header title="Master Produk" description="Satuan barang yang dipakai produk, cth. PCS, BOX, LUSIN." icon="heroicon-o-cube">
        @can('products.create')
            <a href="{{ route('master.units.create') }}" class="btn-primary">
                <x-heroicon-o-plus class="size-5" /> Tambah Satuan
            </a>
        @endcan
    </x-page-header>

    <x-master.product-tabs />

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 md:flex-row md:items-center">
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari kode atau nama satuan..." class="form-input pl-11">
            </div>
            <select name="status" class="form-input md:w-40" onchange="this.form.submit()">
                <option value="">Semua status</option>
                <option value="active" @selected(request('status') === 'active')>Aktif</option>
                <option value="inactive" @selected(request('status') === 'inactive')>Nonaktif</option>
            </select>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'status']))
                    <a href="{{ route('master.units.index') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold tracking-wider text-slate-500 uppercase">
                    <tr>
                        <th class="px-5 py-3">Kode</th>
                        <th class="px-5 py-3">Nama Satuan</th>
                        <th class="px-5 py-3 text-center">Produk</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($units as $unit)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-5 py-3.5 font-mono font-medium text-slate-900">{{ $unit->code }}</td>
                            <td class="px-5 py-3.5 text-slate-600">{{ $unit->name }}</td>
                            <td class="px-5 py-3.5 text-center text-slate-600">{{ $unit->products_count }}</td>
                            <td class="px-5 py-3.5"><x-status-badge :active="$unit->is_active" /></td>
                            <td class="px-5 py-3.5">
                                <div class="flex items-center justify-end gap-1">
                                    @can('products.edit')
                                        <a href="{{ route('master.units.edit', $unit) }}" class="btn-icon" title="Edit">
                                            <x-heroicon-o-pencil-square class="size-5" />
                                        </a>
                                    @endcan
                                    @can('products.delete')
                                        <form id="delete-{{ $unit->id }}" method="POST" action="{{ route('master.units.destroy', $unit) }}">
                                            @csrf @method('DELETE')
                                        </form>
                                        <button type="button" class="btn-icon hover:bg-rose-50 hover:text-rose-600" title="Hapus"
                                                @click="$dispatch('confirm', {
                                                    form: 'delete-{{ $unit->id }}',
                                                    title: 'Hapus satuan?',
                                                    message: @js("Satuan {$unit->name} akan dihapus permanen."),
                                                    confirmText: 'Hapus',
                                                })">
                                            <x-heroicon-o-trash class="size-5" />
                                        </button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-16 text-center">
                                <x-heroicon-o-scale class="mx-auto size-12 text-slate-300" />
                                <p class="mt-3 font-medium text-slate-700">Belum ada satuan</p>
                                <p class="text-sm text-slate-500">Tambahkan satuan seperti PCS atau BOX.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($units->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $units->links() }}</div>
        @endif
    </div>
</x-layouts.app>
