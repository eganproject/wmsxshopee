@php($fmt = fn ($value) => number_format((int) $value, 0, ',', '.'))

<x-layouts.app title="Stok per Lokasi">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Laporan</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Stok per Lokasi</span>
    </x-slot:breadcrumb>

    <x-page-header title="Stok per Lokasi" description="Saldo stok per gudang, zona, rak dan bin, termasuk stok yang belum punya bin." icon="heroicon-o-map-pin">
        <x-reports.export-button />
    </x-page-header>

    <x-reports.tabs />

    <div class="mb-6 grid gap-4 sm:grid-cols-4">
        <div class="card p-4">
            <p class="text-xs text-slate-500">Total Stok</p>
            <p class="mt-1 text-xl font-semibold tabular-nums text-slate-900">{{ $fmt($summary->qty) }}</p>
        </div>
        <div class="card p-4">
            <p class="text-xs text-slate-500">Jumlah SKU</p>
            <p class="mt-1 text-xl font-semibold tabular-nums text-slate-900">{{ $fmt($summary->skus) }}</p>
        </div>
        <div class="card p-4">
            <p class="text-xs text-slate-500">Bin Terisi</p>
            <p class="mt-1 text-xl font-semibold tabular-nums text-slate-900">{{ $fmt($summary->bins) }}</p>
        </div>
        <div class="card p-4">
            <p class="text-xs text-slate-500">Stok Tanpa Bin</p>
            <p @class(['mt-1 text-xl font-semibold tabular-nums', 'text-amber-600' => $summary->unbinned > 0, 'text-slate-900' => ! $summary->unbinned])>{{ $fmt($summary->unbinned) }}</p>
            @if ($summary->unbinned > 0)
                @can('opname.create')
                    <a href="{{ route('inventory.counts.create') }}" class="text-xs text-brand-600 hover:underline">Taruh ke bin lewat opname</a>
                @endcan
            @endif
        </div>
    </div>

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:items-center">
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari SKU, barcode, nama produk atau kode lokasi..." class="form-input pl-8">
            </div>
            <select name="warehouse" class="form-input lg:w-48" onchange="this.form.zone && (this.form.zone.value = ''); this.form.submit()">
                <option value="">Semua gudang</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected(request('warehouse') == $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                @endforeach
            </select>
            <select name="zone" class="form-input lg:w-48" onchange="this.form.submit()">
                <option value="">Semua zona</option>
                <option value="none" @selected(request('zone') === 'none')>Tanpa bin</option>
                @foreach ($zones as $zone)
                    <option value="{{ $zone->id }}" @selected(request('zone') == $zone->id)>{{ $zone->warehouse->code }} · {{ $zone->code }} {{ $zone->name }}</option>
                @endforeach
            </select>
            <label class="flex items-center gap-2 text-sm whitespace-nowrap text-slate-600">
                <input type="checkbox" name="zero" value="1" class="form-checkbox" @checked(request()->boolean('zero')) onchange="this.form.submit()"> Tampilkan stok 0
            </label>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'warehouse', 'zone', 'zero']))
                    <a href="{{ route('reports.stock-locations') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">Gudang</th>
                        <th class="h-8 px-4 py-0 font-normal">Zona</th>
                        <th class="h-8 px-4 py-0 font-normal">Rak</th>
                        <th class="h-8 px-4 py-0 font-normal">Lokasi</th>
                        <th class="h-8 px-4 py-0 font-normal">SKU</th>
                        <th class="h-8 px-4 py-0 font-normal">Produk / Varian</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Stok</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 text-slate-600">{{ $row->warehouse_code }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $row->zone_code ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $row->rack_code ?? '—' }}</td>
                            <td @class(['px-4 py-2.5', 'font-mono text-slate-700' => $row->location_code, 'text-amber-600' => ! $row->location_code])>{{ $row->location_code ?? 'Tanpa bin' }}</td>
                            <td class="px-4 py-2.5 font-mono font-medium text-slate-900">
                                <a href="{{ route('inventory.stock-card.index', ['variant' => $row->product_variant_id, 'warehouse' => $row->warehouse_id, 'location' => $row->warehouse_location_id ?? 'none']) }}" class="hover:text-brand-600" title="Kartu stok">{{ $row->sku }}</a>
                            </td>
                            <td class="px-4 py-2.5">
                                <p class="text-slate-900">{{ $row->product_name }}</p>
                                @if ($row->variant_name)
                                    <p class="text-xs text-slate-500">{{ $row->variant_name }}</p>
                                @endif
                            </td>
                            <td @class(['px-4 py-2.5 text-right font-semibold tabular-nums', 'text-slate-900' => $row->qty > 0, 'text-rose-600' => $row->qty < 0, 'text-slate-400' => $row->qty == 0])>
                                {{ $fmt($row->qty) }} <span class="text-xs font-normal text-slate-500">{{ $row->unit_code }}</span>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="7" icon="heroicon-o-map-pin" title="Tidak ada stok" description="Ubah filter, atau catat barang masuk dulu." />
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $rows->links() }}</div>
        @endif
    </div>
</x-layouts.app>
