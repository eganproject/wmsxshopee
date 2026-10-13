<x-layouts.app title="Rak Gudang">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('master.warehouses.index') }}" class="hover:text-slate-700">Master Gudang</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Rak</span>
    </x-slot:breadcrumb>

    <x-page-header title="Rak Gudang" description="Rak di setiap zona beserta jumlah lokasi bin-nya." icon="heroicon-o-view-columns">
        @can('warehouses.create')
            <a href="{{ route('master.racks.create', ['zone' => request('zone')]) }}" class="btn-primary">
                <x-heroicon-o-plus class="size-5" /> Tambah Rak
            </a>
        @endcan
    </x-page-header>

    <x-master.warehouse-tabs />

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 md:flex-row md:items-center">
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari kode atau nama rak..." class="form-input pl-11">
            </div>
            <select name="warehouse" class="form-input md:w-56" onchange="this.form.submit()">
                <option value="">Semua gudang</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected(request('warehouse') == $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                @endforeach
            </select>
            <select name="zone" class="form-input md:w-56" onchange="this.form.submit()">
                <option value="">Semua zona</option>
                @foreach ($zones as $zone)
                    <option value="{{ $zone->id }}" @selected(request('zone') == $zone->id)>{{ $zone->warehouse->code }} · {{ $zone->code }} · {{ $zone->name }}</option>
                @endforeach
            </select>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'warehouse', 'zone']))
                    <a href="{{ route('master.racks.index') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold tracking-wider text-slate-500 uppercase">
                    <tr>
                        <th class="px-5 py-3">Rak</th>
                        <th class="px-5 py-3">Gudang / Zona</th>
                        <th class="px-5 py-3 text-center">Level</th>
                        <th class="px-5 py-3 text-center">Lokasi Bin</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($racks as $rack)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-5 py-3.5">
                                <p class="font-mono font-medium text-slate-900">{{ $rack->code }}</p>
                                @if ($rack->name)
                                    <p class="text-xs text-slate-500">{{ $rack->name }}</p>
                                @endif
                            </td>
                            <td class="px-5 py-3.5 text-slate-600">
                                {{ $rack->zone->warehouse->code }}
                                <p class="text-xs text-slate-500">Zona {{ $rack->zone->code }} · {{ $rack->zone->name }}</p>
                            </td>
                            <td class="px-5 py-3.5 text-center text-slate-700">{{ $rack->levels }}</td>
                            <td class="px-5 py-3.5 text-center">
                                <a href="{{ route('master.locations.index', ['rack' => $rack->id]) }}" class="font-medium text-brand-600 hover:underline">{{ $rack->locations_count }}</a>
                            </td>
                            <td class="px-5 py-3.5"><x-status-badge :active="$rack->is_active" /></td>
                            <td class="px-5 py-3.5">
                                <div class="flex items-center justify-end gap-1">
                                    @can('warehouses.create')
                                        <a href="{{ route('master.locations.create', ['rack' => $rack->id]) }}" class="btn-icon" title="Tambah lokasi di rak ini">
                                            <x-heroicon-o-plus-circle class="size-5" />
                                        </a>
                                    @endcan
                                    @can('warehouses.edit')
                                        <a href="{{ route('master.racks.edit', $rack) }}" class="btn-icon" title="Edit">
                                            <x-heroicon-o-pencil-square class="size-5" />
                                        </a>
                                    @endcan
                                    @can('warehouses.delete')
                                        <form id="delete-{{ $rack->id }}" method="POST" action="{{ route('master.racks.destroy', $rack) }}">
                                            @csrf @method('DELETE')
                                        </form>
                                        <button type="button" class="btn-icon hover:bg-rose-50 hover:text-rose-600" title="Hapus"
                                                @click="$dispatch('confirm', {
                                                    form: 'delete-{{ $rack->id }}',
                                                    title: 'Hapus rak?',
                                                    message: @js("Rak {$rack->code} akan dihapus permanen."),
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
                            <td colspan="6" class="px-5 py-16 text-center">
                                <x-heroicon-o-view-columns class="mx-auto size-12 text-slate-300" />
                                <p class="mt-3 font-medium text-slate-700">Belum ada rak</p>
                                <p class="text-sm text-slate-500">Tambahkan rak dan buat lokasi bin-nya sekaligus.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($racks->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $racks->links() }}</div>
        @endif
    </div>
</x-layouts.app>
