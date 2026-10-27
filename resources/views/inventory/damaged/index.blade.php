@php
    $fmt = fn ($value) => number_format((int) $value, 0, ',', '.');
@endphp

<x-layouts.app title="Stok Rusak">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Inventori</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Stok Rusak</span>
    </x-slot:breadcrumb>

    <x-page-header title="Stok Rusak" description="Barang rusak per gudang, terpisah dari stok jual. Alokasikan untuk diretur, dimusnahkan, diperbaiki atau dirakit jadi barang lain." icon="heroicon-o-exclamation-triangle">
        <x-reports.export-button />
        <x-damaged.create-menu :params="request()->only('warehouse')" />
    </x-page-header>

    <x-damaged.tabs />

    @if ($drafts)
        <a href="{{ route('inventory.damaged.documents.index', ['status' => 'draft', ...request()->only('warehouse')]) }}"
           class="mb-6 flex items-center gap-2 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-700 hover:bg-amber-100">
            <x-heroicon-o-clock class="size-5 shrink-0" /> {{ $drafts }} dokumen draft menunggu persetujuan. Saldo di bawah belum termasuk draft.
            <x-heroicon-o-chevron-right class="ml-auto size-4" />
        </a>
    @endif

    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="card p-4">
            <p class="text-xs text-slate-500">Total Barang <span class="whitespace-nowrap">Rusak <x-hint text="Jumlah pcs barang rusak yang masih tersimpan di gudang, terpisah dari stok jual." /></span></p>
            <p @class(['mt-1 text-2xl font-semibold tabular-nums', 'text-rose-600' => $summary['qty'] > 0, 'text-slate-400' => ! $summary['qty']])>{{ $fmt($summary['qty']) }}</p>
        </div>
        <div class="card p-4">
            <p class="text-xs text-slate-500">SKU dengan Stok Rusak</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ $fmt($summary['skus']) }}</p>
        </div>
        <div class="card p-4">
            <p class="text-xs text-slate-500">Masuk Rusak Bulan <span class="whitespace-nowrap">Ini <x-hint text="Barang rusak yang masuk bulan ini dari penerimaan, retur penjualan atau dokumen Pindah ke Rusak." /></span></p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ $fmt($summary['in']) }}</p>
        </div>
        <div class="card p-4">
            <p class="text-xs text-slate-500">Dialokasikan Bulan <span class="whitespace-nowrap">Ini <x-hint text="Barang rusak yang keluar bulan ini karena diretur ke supplier, dimusnahkan, diperbaiki atau dirakit." /></span></p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-emerald-600">{{ $fmt($summary['out']) }}</p>
        </div>
    </div>

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 md:flex-row md:items-center">
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" class="form-input pl-8" placeholder="Cari SKU, barcode atau nama produk...">
            </div>
            <select name="warehouse" class="form-input md:w-52" onchange="this.form.submit()">
                <option value="">Semua gudang</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected(request('warehouse') == $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                @endforeach
            </select>
            <label class="flex items-center gap-2 text-sm whitespace-nowrap text-slate-600">
                <input type="checkbox" name="zero" value="1" class="rounded border-slate-300" @checked(request()->boolean('zero')) onchange="this.form.submit()">
                Tampilkan saldo 0
            </label>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'warehouse', 'zero']))
                    <a href="{{ route('inventory.damaged.index') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">SKU</th>
                        <th class="h-8 px-4 py-0 font-normal">Produk / Varian</th>
                        <th class="h-8 px-4 py-0 font-normal">Gudang</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Qty <span class="whitespace-nowrap">Rusak <x-hint text="Jumlah barang rusak SKU ini di gudang. Tidak ikut dijual dan tidak dikirim ke marketplace." /></span></th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($stocks as $stock)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 font-mono font-medium text-slate-900">{{ $stock->variant->sku }}</td>
                            <td class="px-4 py-2.5">
                                <p class="font-medium text-slate-900">{{ $stock->variant->product->name }}</p>
                                @if ($stock->variant->name)
                                    <p class="text-xs text-slate-500">{{ $stock->variant->name }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $stock->warehouse->code }}</td>
                            <td @class(['px-4 py-2.5 text-right font-semibold tabular-nums', 'text-rose-600' => $stock->qty > 0, 'text-slate-400' => ! $stock->qty])>
                                {{ $fmt($stock->qty) }} <span class="text-xs font-normal text-slate-500">{{ $stock->variant->product->unit?->code }}</span>
                            </td>
                            <td class="px-4 py-2.5">
                                <div class="flex justify-end gap-1">
                                    <a href="{{ route('inventory.damaged.ledger', ['variant' => $stock->product_variant_id, 'warehouse' => $stock->warehouse_id]) }}" class="btn-icon" title="Kartu stok rusak">
                                        <x-heroicon-o-document-chart-bar class="size-5" />
                                    </a>
                                    @if ($stock->qty > 0)
                                        @foreach ($actions as $type => $meta)
                                            @continue($type === 'intake')
                                            <a href="{{ route('inventory.damaged.documents.create', ['type' => $type, 'warehouse' => $stock->warehouse_id, 'variant' => $stock->product_variant_id]) }}"
                                               class="btn-icon" title="{{ $meta['label'] }}">
                                                <x-dynamic-component :component="$meta['icon']" class="size-5" />
                                            </a>
                                        @endforeach
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="5" icon="heroicon-o-exclamation-triangle" title="Belum ada stok rusak"
                                       description="Barang rusak masuk ke sini dari Penerimaan Barang, Retur Penjualan, atau lewat Pindah ke Rusak." :ignore="['warehouse']">
                            @can('damaged.move')
                                <a href="{{ route('inventory.damaged.documents.create', ['type' => 'intake', ...request()->only('warehouse')]) }}" class="btn-primary"><x-heroicon-o-plus class="size-5" /> Pindah ke Rusak</a>
                            @endcan
                        </x-empty-state>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($stocks->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $stocks->links() }}</div>
        @endif
    </div>
</x-layouts.app>
