<x-layouts.app title="Zona Gudang">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('master.warehouses.index') }}" class="hover:text-slate-700">Master Gudang</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Zona</span>
    </x-slot:breadcrumb>

    <x-page-header title="Zona Gudang" description="Pembagian area di setiap gudang." icon="heroicon-o-squares-2x2">
        @can('warehouses.create')
            <a href="{{ route('master.locations.import') }}" class="btn-secondary">
                <x-heroicon-o-arrow-up-tray class="size-5" /> Impor Excel / CSV
            </a>
            <a href="{{ route('master.zones.create', ['warehouse' => request('warehouse')]) }}" class="btn-primary">
                <x-heroicon-o-plus class="size-5" /> Tambah Zona
            </a>
        @endcan
    </x-page-header>

    <x-master.warehouse-tabs />

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 md:flex-row md:items-center">
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari kode atau nama zona..." class="form-input pl-8">
            </div>
            <select name="warehouse" class="form-input md:w-56" onchange="this.form.submit()">
                <option value="">Semua gudang</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected(request('warehouse') == $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                @endforeach
            </select>
            <select name="type" class="form-input md:w-52" onchange="this.form.submit()">
                <option value="">Semua jenis</option>
                @foreach (\App\Models\WarehouseZone::TYPES as $value => $label)
                    <option value="{{ $value }}" @selected(request('type') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'warehouse', 'type']))
                    <a href="{{ route('master.zones.index') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">Zona</th>
                        <th class="h-8 px-4 py-0 font-normal">Gudang</th>
                        <th class="h-8 px-4 py-0 font-normal"><span class="whitespace-nowrap">Jenis <x-hint text="Fungsi area, misalnya Penerimaan, Penyimpanan, Picking, Packing, Pengiriman, Karantina atau Retur." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal text-center"><span class="whitespace-nowrap">Rak <x-hint text="Jumlah rak di zona ini." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal"><span class="whitespace-nowrap">Status <x-hint text="Data nonaktif tidak bisa dipilih di transaksi baru, tapi riwayatnya tetap tersimpan." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($zones as $zone)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5">
                                <p class="font-medium text-slate-900">{{ $zone->name }}</p>
                                <p class="font-mono text-xs text-slate-500">{{ $zone->code }}</p>
                            </td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $zone->warehouse->code }} · {{ $zone->warehouse->name }}</td>
                            <td class="px-4 py-2.5">
                                <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">{{ $zone->typeLabel() }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <a href="{{ route('master.racks.index', ['zone' => $zone->id]) }}" class="font-medium text-slate-900 underline decoration-slate-300 underline-offset-2 hover:decoration-slate-900">{{ $zone->racks_count }}</a>
                            </td>
                            <td class="px-4 py-2.5"><x-status-badge :active="$zone->is_active" /></td>
                            <td class="px-4 py-2.5">
                                <div class="flex items-center justify-end gap-1">
                                    @can('warehouses.create')
                                        <a href="{{ route('master.racks.create', ['zone' => $zone->id]) }}" class="btn-icon" title="Tambah rak di zona ini">
                                            <x-heroicon-o-plus-circle class="size-5" />
                                        </a>
                                    @endcan
                                    @can('warehouses.edit')
                                        <a href="{{ route('master.zones.edit', $zone) }}" class="btn-icon" title="Edit">
                                            <x-heroicon-o-pencil-square class="size-5" />
                                        </a>
                                    @endcan
                                    @can('warehouses.delete')
                                        <form id="delete-{{ $zone->id }}" method="POST" action="{{ route('master.zones.destroy', $zone) }}">
                                            @csrf @method('DELETE')
                                        </form>
                                        <button type="button" class="btn-icon hover:bg-rose-50 hover:text-rose-600" title="Hapus"
                                                @click="$dispatch('confirm', {
                                                    form: 'delete-{{ $zone->id }}',
                                                    title: 'Hapus zona?',
                                                    message: @js("Zona {$zone->name} akan dihapus permanen."),
                                                    confirmText: 'Hapus',
                                                })">
                                            <x-heroicon-o-trash class="size-5" />
                                        </button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="6" icon="heroicon-o-squares-2x2" title="Belum ada zona" description="Tambahkan zona untuk membagi area gudang." :ignore="['warehouse']" :reset-url="route('master.zones.index', ['warehouse' => request('warehouse')])">
                            @can('warehouses.create')
                                <a href="{{ route('master.zones.create', ['warehouse' => request('warehouse')]) }}" class="btn-primary"><x-heroicon-o-plus class="size-5" /> Tambah Zona</a>
                            @endcan
                        </x-empty-state>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($zones->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $zones->links() }}</div>
        @endif
    </div>
</x-layouts.app>
