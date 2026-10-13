<x-layouts.app title="Lokasi Bin">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('master.warehouses.index') }}" class="hover:text-slate-700">Master Gudang</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Lokasi Bin</span>
    </x-slot:breadcrumb>

    <x-page-header title="Lokasi Bin" description="Titik penyimpanan barang di setiap rak." icon="heroicon-o-map-pin">
        <x-reports.export-button />
        @if ($locations->total())
            <a href="{{ route('labels.locations', request()->only(['search', 'warehouse', 'rack', 'status']) + ['all' => 1]) }}" class="btn-secondary"
               title="Cetak label semua lokasi sesuai filter">
                <x-heroicon-o-qr-code class="size-5" /> Cetak Label ({{ number_format($locations->total(), 0, ',', '.') }})
            </a>
        @endif
        @can('warehouses.create')
            <a href="{{ route('master.locations.create', ['rack' => request('rack')]) }}" class="btn-primary">
                <x-heroicon-o-plus class="size-5" /> Tambah Lokasi
            </a>
        @endcan
    </x-page-header>

    <x-master.warehouse-tabs />

    <div class="card" x-data="{ selected: [] }">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 md:flex-row md:items-center">
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari kode lokasi..." class="form-input pl-8">
            </div>
            <select name="warehouse" class="form-input md:w-52" onchange="this.form.submit()">
                <option value="">Semua gudang</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected(request('warehouse') == $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                @endforeach
            </select>
            <select name="rack" class="form-input md:w-52" onchange="this.form.submit()">
                <option value="">Semua rak</option>
                @foreach ($racks as $rack)
                    <option value="{{ $rack->id }}" @selected(request('rack') == $rack->id)>{{ $rack->zone->warehouse->code }} · Rak {{ $rack->code }}</option>
                @endforeach
            </select>
            <select name="status" class="form-input md:w-36" onchange="this.form.submit()">
                <option value="">Semua status</option>
                <option value="active" @selected(request('status') === 'active')>Aktif</option>
                <option value="inactive" @selected(request('status') === 'inactive')>Nonaktif</option>
            </select>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'warehouse', 'rack', 'status']))
                    <a href="{{ route('master.locations.index') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <x-labels.selection-bar route="labels.locations" param="locations" />

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <x-labels.select-all :ids="$locations->pluck('id')" />
                        <th class="h-8 px-4 py-0 font-normal">Kode Lokasi</th>
                        <th class="h-8 px-4 py-0 font-normal">Gudang / Zona / Rak</th>
                        <th class="h-8 px-4 py-0 font-normal text-center">Level</th>
                        <th class="h-8 px-4 py-0 font-normal text-center">Posisi</th>
                        <th class="h-8 px-4 py-0 font-normal">Jenis</th>
                        <th class="h-8 px-4 py-0 font-normal">Kapasitas</th>
                        <th class="h-8 px-4 py-0 font-normal">Status</th>
                        <th class="h-8 px-4 py-0 font-normal text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($locations as $location)
                        <tr class="hover:bg-slate-50/60">
                            <td class="w-10 py-2.5 pr-0 pl-4"><input type="checkbox" class="form-checkbox block" value="{{ $location->id }}" x-model="selected" aria-label="Pilih"></td>
                            <td class="px-4 py-2.5 font-mono font-medium text-slate-900">{{ $location->code }}</td>
                            <td class="px-4 py-2.5 text-slate-600">
                                {{ $location->warehouse->code }} · Zona {{ $location->rack->zone->code }} · Rak {{ $location->rack->code }}
                            </td>
                            <td class="px-4 py-2.5 text-center text-slate-700">{{ $location->level ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-center text-slate-700">{{ $location->position ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $location->typeLabel() }}</td>
                            <td class="px-4 py-2.5 text-slate-600">
                                @if ($location->max_qty || $location->max_weight_kg)
                                    {{ collect([
                                        $location->max_qty ? number_format($location->max_qty, 0, ',', '.').' pcs' : null,
                                        $location->max_weight_kg ? rtrim(rtrim(number_format($location->max_weight_kg, 2, ',', '.'), '0'), ',').' kg' : null,
                                    ])->filter()->join(' · ') }}
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5"><x-status-badge :active="$location->is_active" /></td>
                            <td class="px-4 py-2.5">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="{{ route('labels.locations', ['locations' => [$location->id]]) }}" class="btn-icon" title="Cetak label">
                                        <x-heroicon-o-qr-code class="size-5" />
                                    </a>
                                    @can('warehouses.edit')
                                        <a href="{{ route('master.locations.edit', $location) }}" class="btn-icon" title="Edit">
                                            <x-heroicon-o-pencil-square class="size-5" />
                                        </a>
                                    @endcan
                                    @can('warehouses.delete')
                                        <form id="delete-{{ $location->id }}" method="POST" action="{{ route('master.locations.destroy', $location) }}">
                                            @csrf @method('DELETE')
                                        </form>
                                        <button type="button" class="btn-icon hover:bg-rose-50 hover:text-rose-600" title="Hapus"
                                                @click="$dispatch('confirm', {
                                                    form: 'delete-{{ $location->id }}',
                                                    title: 'Hapus lokasi?',
                                                    message: @js("Lokasi {$location->code} akan dihapus permanen."),
                                                    confirmText: 'Hapus',
                                                })">
                                            <x-heroicon-o-trash class="size-5" />
                                        </button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="9" icon="heroicon-o-map-pin" title="Belum ada lokasi bin" description="Buat lokasi otomatis dari halaman rak, atau tambahkan satu per satu." :ignore="['rack']" :reset-url="route('master.locations.index', ['rack' => request('rack')])">
                            @can('warehouses.create')
                                <a href="{{ route('master.locations.create', ['rack' => request('rack')]) }}" class="btn-primary"><x-heroicon-o-plus class="size-5" /> Tambah Lokasi</a>
                            @endcan
                        </x-empty-state>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($locations->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $locations->links() }}</div>
        @endif
    </div>
</x-layouts.app>
