@php($fmt = fn ($value) => number_format((int) $value, 0, ',', '.'))

<x-layouts.app title="Saldo Stok per Tanggal">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Laporan</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Saldo per Tanggal</span>
    </x-slot:breadcrumb>

    <x-page-header title="Saldo Stok per Tanggal" :description="'Stok tiap barang di akhir '.$date->translatedFormat('d F Y').', dihitung dari semua mutasi di kartu stok sampai tanggal itu.'" icon="heroicon-o-calendar-days">
        <x-reports.export-button />
    </x-page-header>

    <x-reports.tabs />

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <div class="card p-4"><p class="text-xs text-slate-500">Total Saldo</p><p class="mt-1 text-xl font-semibold tabular-nums text-slate-900">{{ $fmt($totals->qty ?? 0) }}</p></div>
        <div class="card p-4"><p class="text-xs text-slate-500">Jumlah SKU</p><p class="mt-1 text-xl font-semibold tabular-nums text-slate-900">{{ $fmt($totals->skus ?? 0) }}</p></div>
        <div class="card p-4"><p class="text-xs text-slate-500">Baris</p><p class="mt-1 text-xl font-semibold tabular-nums text-slate-900">{{ $fmt($totals->lines ?? 0) }}</p></div>
    </div>

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:items-center">
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari SKU atau nama produk..." class="form-input pl-8">
            </div>
            <input type="date" name="date" value="{{ $date->toDateString() }}" max="{{ now()->toDateString() }}" class="form-input lg:w-44" title="Saldo per tanggal">
            <select name="warehouse" class="form-input lg:w-48" onchange="this.form.submit()" aria-label="Gudang">
                <option value="">Semua gudang</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected(request('warehouse') == $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                @endforeach
            </select>
            <select name="group" class="form-input lg:w-48" onchange="this.form.submit()" aria-label="Tampilan">
                <option value="warehouse" @selected($perWarehouse)>Per SKU per gudang</option>
                <option value="sku" @selected(! $perWarehouse)>Per SKU (total gudang)</option>
            </select>
            <label class="flex items-center gap-2 text-sm whitespace-nowrap text-slate-600">
                <input type="checkbox" name="zero" value="1" class="form-checkbox" @checked(request()->boolean('zero')) onchange="this.form.submit()"> Termasuk saldo 0
            </label>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'date', 'warehouse', 'group', 'zero']))
                    <a href="{{ route('reports.balance') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">SKU</th>
                        <th class="h-8 px-4 py-0 font-normal">Produk / Varian</th>
                        @if ($perWarehouse)<th class="h-8 px-4 py-0 font-normal">Gudang</th>@endif
                        <th class="h-8 px-4 py-0 text-right font-normal">Saldo {{ $date->format('d/m/Y') }}</th>
                        <th class="h-8 px-4 py-0 font-normal"><span class="whitespace-nowrap">Mutasi Terakhir <x-hint text="Tanggal mutasi terakhir sampai tanggal saldo." /></span></th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 font-mono font-medium text-slate-900">{{ $row->sku }}</td>
                            <td class="cell-wrap px-4 py-2.5">
                                <p class="text-slate-900">{{ $row->product_name }}</p>
                                @if ($row->variant_name)<p class="text-xs text-slate-500">{{ $row->variant_name }}</p>@endif
                            </td>
                            @if ($perWarehouse)<td class="px-4 py-2.5 text-slate-600">{{ $row->warehouse_code }}</td>@endif
                            <td class="px-4 py-2.5 text-right font-semibold tabular-nums {{ (int) $row->balance < 0 ? 'text-rose-600' : 'text-slate-900' }}">{{ $fmt($row->balance) }} <span class="text-xs font-normal text-slate-500">{{ $row->unit_code }}</span></td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-slate-600">{{ $row->last_movement ? \Illuminate\Support\Carbon::parse($row->last_movement)->format('d/m/Y') : '—' }}</td>
                            <td class="px-4 py-2.5">
                                <div class="flex justify-end">
                                    @can('inventory.view')
                                        <a href="{{ route('inventory.stock-card.index', array_filter(['variant' => $row->product_variant_id, 'warehouse' => $perWarehouse ? $row->warehouse_id : request('warehouse'), 'to' => $date->toDateString()])) }}" class="btn-icon" title="Kartu stok">
                                            <x-heroicon-o-document-chart-bar class="size-5" />
                                        </a>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state :colspan="$perWarehouse ? 6 : 5" icon="heroicon-o-calendar-days" title="Tidak ada saldo" description="Belum ada stok pada tanggal ini untuk filter yang dipilih." />
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $rows->links() }}</div>
        @endif
    </div>

    <p class="mt-4 flex items-center gap-1.5 text-xs text-slate-500">
        <x-heroicon-o-information-circle class="size-4" /> Stok rusak dicatat terpisah dan tidak termasuk di saldo ini.
    </p>
</x-layouts.app>
