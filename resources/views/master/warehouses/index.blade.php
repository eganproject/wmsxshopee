<x-layouts.app title="Master Gudang">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Master Data</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Gudang</span>
    </x-slot:breadcrumb>

    <x-page-header title="Master Gudang" description="Kelola gudang beserta struktur zona, rak, dan lokasi bin." icon="heroicon-o-building-office-2">
        @can('warehouses.create')
            <a href="{{ route('master.warehouses.create') }}" class="btn-primary">
                <x-heroicon-o-plus class="size-5" /> Tambah Gudang
            </a>
        @endcan
    </x-page-header>

    <x-master.warehouse-tabs />

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 md:flex-row md:items-center">
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari kode, nama, atau kota..." class="form-input pl-8">
            </div>
            <select name="status" class="form-input md:w-40" onchange="this.form.submit()">
                <option value="">Semua status</option>
                <option value="active" @selected(request('status') === 'active')>Aktif</option>
                <option value="inactive" @selected(request('status') === 'inactive')>Nonaktif</option>
            </select>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'status']))
                    <a href="{{ route('master.warehouses.index') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">Gudang</th>
                        <th class="h-8 px-4 py-0 font-normal">Lokasi</th>
                        <th class="h-8 px-4 py-0 font-normal">Penanggung Jawab</th>
                        <th class="h-8 px-4 py-0 font-normal text-center">Zona</th>
                        <th class="h-8 px-4 py-0 font-normal text-center">Rak</th>
                        <th class="h-8 px-4 py-0 font-normal text-center">Bin</th>
                        <th class="h-8 px-4 py-0 font-normal">Status</th>
                        <th class="h-8 px-4 py-0 font-normal text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($warehouses as $warehouse)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5">
                                <p class="font-medium text-slate-900">{{ $warehouse->name }}</p>
                                <p class="font-mono text-xs text-slate-500">{{ $warehouse->code }}</p>
                                @if ($warehouse->supplyWarehouse)
                                    <span class="mt-1 inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-0.5 text-xs text-sky-700" title="Stok gudang cadangan ikut dijual ke marketplace">
                                        <x-heroicon-o-link class="size-3.5" /> Cadangan: {{ $warehouse->supplyWarehouse->code }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-slate-600">
                                {{ collect([$warehouse->city, $warehouse->province])->filter()->join(', ') ?: '—' }}
                            </td>
                            <td class="px-4 py-2.5 text-slate-600">
                                {{ $warehouse->pic_name ?? '—' }}
                                @if ($warehouse->phone)
                                    <p class="text-xs text-slate-400">{{ $warehouse->phone }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <a href="{{ route('master.zones.index', ['warehouse' => $warehouse->id]) }}" class="font-medium text-slate-900 underline decoration-slate-300 underline-offset-2 hover:decoration-slate-900">{{ $warehouse->zones_count }}</a>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <a href="{{ route('master.racks.index', ['warehouse' => $warehouse->id]) }}" class="font-medium text-slate-900 underline decoration-slate-300 underline-offset-2 hover:decoration-slate-900">{{ $warehouse->racks_count }}</a>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <a href="{{ route('master.locations.index', ['warehouse' => $warehouse->id]) }}" class="font-medium text-slate-900 underline decoration-slate-300 underline-offset-2 hover:decoration-slate-900">{{ $warehouse->locations_count }}</a>
                            </td>
                            <td class="px-4 py-2.5"><x-status-badge :active="$warehouse->is_active" /></td>
                            <td class="px-4 py-2.5">
                                <div class="flex items-center justify-end gap-1">
                                    @can('warehouses.edit')
                                        <a href="{{ route('master.warehouses.edit', $warehouse) }}" class="btn-icon" title="Edit">
                                            <x-heroicon-o-pencil-square class="size-5" />
                                        </a>
                                    @endcan
                                    @can('warehouses.delete')
                                        <form id="delete-{{ $warehouse->id }}" method="POST" action="{{ route('master.warehouses.destroy', $warehouse) }}">
                                            @csrf @method('DELETE')
                                        </form>
                                        <button type="button" class="btn-icon hover:bg-rose-50 hover:text-rose-600" title="Hapus"
                                                @click="$dispatch('confirm', {
                                                    form: 'delete-{{ $warehouse->id }}',
                                                    title: 'Hapus gudang?',
                                                    message: @js("Gudang {$warehouse->name} akan dihapus permanen."),
                                                    confirmText: 'Hapus',
                                                })">
                                            <x-heroicon-o-trash class="size-5" />
                                        </button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="8" icon="heroicon-o-building-office-2" title="Belum ada gudang" description="Tambahkan gudang pertama untuk mulai menyusun zona, rak, dan lokasi bin.">
                            @can('warehouses.create')
                                <a href="{{ route('master.warehouses.create') }}" class="btn-primary"><x-heroicon-o-plus class="size-5" /> Tambah Gudang</a>
                            @endcan
                        </x-empty-state>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($warehouses->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $warehouses->links() }}</div>
        @endif
    </div>
</x-layouts.app>
