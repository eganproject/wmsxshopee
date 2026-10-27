@php
    $fmt = fn ($value) => number_format((int) $value, 0, ',', '.');
@endphp

<x-layouts.app title="Kartu Stok Rusak">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('inventory.damaged.index') }}" class="hover:text-slate-700">Stok Rusak</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Kartu Stok Rusak</span>
    </x-slot:breadcrumb>

    <x-page-header title="Kartu Stok Rusak" description="Riwayat barang rusak masuk dan keluar per gudang, lengkap dengan dokumen sumbernya." icon="heroicon-o-document-chart-bar">
        <x-reports.export-button />
        <x-damaged.create-menu :params="request()->only('warehouse')" />
    </x-page-header>

    <x-damaged.tabs />

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:items-center">
            @if (request('variant'))
                <input type="hidden" name="variant" value="{{ request('variant') }}">
            @endif
            <div class="relative flex-1 lg:min-w-64">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari SKU, nama produk atau no. dokumen..." class="form-input pl-8">
            </div>
            <select name="type" class="form-input lg:w-48" onchange="this.form.submit()">
                <option value="">Semua jenis</option>
                @foreach ($types as $value => $meta)
                    <option value="{{ $value }}" @selected(request('type') === $value)>{{ $meta['label'] }}</option>
                @endforeach
            </select>
            <select name="warehouse" class="form-input lg:w-36" onchange="this.form.submit()">
                <option value="">Semua gudang</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected(request('warehouse') == $warehouse->id)>{{ $warehouse->code }}</option>
                @endforeach
            </select>
            <input type="date" name="from" value="{{ request('from') }}" class="form-input lg:w-36" title="Dari tanggal">
            <input type="date" name="to" value="{{ request('to') }}" class="form-input lg:w-36" title="Sampai tanggal">
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'type', 'warehouse', 'from', 'to', 'variant']))
                    <a href="{{ route('inventory.damaged.ledger') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">Tanggal</th>
                        <th class="h-8 px-4 py-0 font-normal"><span class="whitespace-nowrap">Jenis <x-hint text="Sumber pergerakan stok rusak. Arahkan kursor ke label jenis untuk penjelasannya." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal"><span class="whitespace-nowrap">Dokumen <x-hint text="Dokumen asal pergerakan, misalnya penerimaan, retur atau dokumen barang rusak." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal">SKU</th>
                        <th class="h-8 px-4 py-0 font-normal">Produk</th>
                        <th class="h-8 px-4 py-0 font-normal">Gudang</th>
                        <th class="h-8 px-4 py-0 text-right font-normal"><span class="whitespace-nowrap">Masuk <x-hint text="Barang rusak yang bertambah." /></span></th>
                        <th class="h-8 px-4 py-0 text-right font-normal"><span class="whitespace-nowrap">Keluar <x-hint text="Barang rusak yang berkurang karena diretur, dimusnahkan, diperbaiki atau dirakit." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal">Keterangan</th>
                        <th class="h-8 px-4 py-0 font-normal">Oleh</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($ledgers as $ledger)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 whitespace-nowrap text-slate-600">{{ $ledger->movement_date->format('d/m/Y') }}</td>
                            <td class="px-4 py-2.5"><x-damaged.type-badge :type="$ledger->type" /></td>
                            <td class="px-4 py-2.5 font-mono text-slate-700">
                                @if ($url = $ledger->sourceUrl())
                                    <a href="{{ $url }}" class="hover:text-brand-600">{{ $ledger->reference }}</a>
                                @else
                                    {{ $ledger->reference ?? '—' }}
                                @endif
                            </td>
                            <td class="px-4 py-2.5 font-mono font-medium text-slate-900">{{ $ledger->variant->sku }}</td>
                            <td class="px-4 py-2.5 text-slate-700">{{ $ledger->variant->product->name }}@if ($ledger->variant->name) <span class="text-slate-500">· {{ $ledger->variant->name }}</span>@endif</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $ledger->warehouse->code }}</td>
                            <td @class(['px-4 py-2.5 text-right tabular-nums', 'font-medium text-rose-600' => $ledger->qty_in, 'text-slate-400' => ! $ledger->qty_in])>{{ $ledger->qty_in ? $fmt($ledger->qty_in) : '—' }}</td>
                            <td @class(['px-4 py-2.5 text-right tabular-nums', 'font-medium text-emerald-600' => $ledger->qty_out, 'text-slate-400' => ! $ledger->qty_out])>{{ $ledger->qty_out ? $fmt($ledger->qty_out) : '—' }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $ledger->notes ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $ledger->creator?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <x-empty-state colspan="10" icon="heroicon-o-document-chart-bar" title="Belum ada riwayat stok rusak" description="Riwayat muncul setelah ada barang rusak dari penerimaan, retur, atau dokumen barang rusak." :ignore="['warehouse']" />
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($ledgers->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $ledgers->links() }}</div>
        @endif
    </div>
</x-layouts.app>
