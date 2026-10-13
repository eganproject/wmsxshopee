@php
    $fmt = fn ($value) => number_format((int) $value, 0, ',', '.');
    $signed = fn ($value) => ((int) $value > 0 ? '+' : '').number_format((int) $value, 0, ',', '.');
@endphp

<x-layouts.app title="Pergerakan Barang">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Laporan</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Pergerakan Barang</span>
    </x-slot:breadcrumb>

    <x-page-header title="Pergerakan Barang" :description="'Saldo awal, masuk, keluar dan penyesuaian per barang, '.$from->translatedFormat('d M Y').' sampai '.$to->translatedFormat('d M Y').'.'" icon="heroicon-o-presentation-chart-line">
        <x-reports.export-button />
    </x-page-header>

    <x-reports.tabs />

    <div class="mb-6 grid gap-4 sm:grid-cols-5">
        @foreach (['opening' => 'Saldo Awal', 'qty_in' => 'Masuk', 'qty_out' => 'Keluar', 'adjusted' => 'Penyesuaian', 'closing' => 'Saldo Akhir'] as $key => $label)
            <div class="card p-4">
                <p class="text-xs text-slate-500">{{ $label }}</p>
                <p @class([
                    'mt-1 text-xl font-semibold tabular-nums',
                    'text-emerald-600' => $key === 'qty_in',
                    'text-rose-600' => $key === 'qty_out',
                    'text-amber-600' => $key === 'adjusted' && $totals->adjusted != 0,
                    'text-slate-900' => in_array($key, ['opening', 'closing']) || ($key === 'adjusted' && $totals->adjusted == 0),
                ])>{{ $key === 'adjusted' ? $signed($totals->adjusted) : $fmt($totals->{$key}) }}</p>
            </div>
        @endforeach
    </div>

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:items-center">
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari SKU atau nama produk..." class="form-input pl-8">
            </div>
            <select name="warehouse" class="form-input lg:w-48" onchange="this.form.submit()">
                <option value="">Semua gudang</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected(request('warehouse') == $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                @endforeach
            </select>
            <input type="date" name="from" value="{{ $from->toDateString() }}" class="form-input lg:w-40" title="Dari tanggal">
            <input type="date" name="to" value="{{ $to->toDateString() }}" class="form-input lg:w-40" title="Sampai tanggal">
            <label class="flex items-center gap-2 text-sm whitespace-nowrap text-slate-600">
                <input type="checkbox" name="all" value="1" class="form-checkbox" @checked(request()->boolean('all')) onchange="this.form.submit()"> Termasuk yang tidak bergerak
            </label>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'warehouse', 'from', 'to', 'all']))
                    <a href="{{ route('reports.movements') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">SKU</th>
                        <th class="h-8 px-4 py-0 font-normal">Produk / Varian</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Saldo Awal</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Masuk</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Keluar</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Penyesuaian</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Saldo Akhir</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 font-mono font-medium text-slate-900">{{ $row->sku }}</td>
                            <td class="px-4 py-2.5">
                                <p class="text-slate-900">{{ $row->product_name }}</p>
                                @if ($row->variant_name)
                                    <p class="text-xs text-slate-500">{{ $row->variant_name }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ $fmt($row->opening) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums {{ $row->qty_in ? 'text-emerald-600' : 'text-slate-400' }}">{{ $fmt($row->qty_in) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums {{ $row->qty_out ? 'text-rose-600' : 'text-slate-400' }}">{{ $fmt($row->qty_out) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums {{ $row->adjusted ? 'text-amber-600' : 'text-slate-400' }}">{{ $signed($row->adjusted) }}</td>
                            <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-900">{{ $fmt($row->closing) }} <span class="text-xs font-normal text-slate-500">{{ $row->unit_code }}</span></td>
                            <td class="px-4 py-2.5">
                                <div class="flex justify-end">
                                    <a href="{{ route('inventory.stock-card.index', array_filter(['variant' => $row->product_variant_id, 'warehouse' => request('warehouse'), 'from' => $from->toDateString(), 'to' => $to->toDateString()])) }}" class="btn-icon" title="Kartu stok">
                                        <x-heroicon-o-document-chart-bar class="size-5" />
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="8" icon="heroicon-o-presentation-chart-line" title="Tidak ada pergerakan" description="Tidak ada mutasi stok pada rentang tanggal ini." />
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $rows->links() }}</div>
        @endif
    </div>

    <p class="mt-4 flex items-center gap-1.5 text-xs text-slate-500">
        <x-heroicon-o-information-circle class="size-4" /> Transfer antar lokasi di gudang yang sama tidak mengubah saldo, jadi tidak dihitung sebagai masuk atau keluar.
    </p>
</x-layouts.app>
