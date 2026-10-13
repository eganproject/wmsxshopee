<x-layouts.app title="Stok">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Inventori</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Stok</span>
    </x-slot:breadcrumb>

    <x-page-header title="Stok" description="Saldo stok saat ini per varian di setiap lokasi bin." icon="heroicon-o-archive-box">
        <x-reports.export-button />
        <x-inventory.create-menu />
    </x-page-header>

    <x-inventory.tabs />

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 md:flex-row md:items-center">
            <input type="hidden" name="view" value="{{ $mode }}">
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" class="form-input pl-11"
                       placeholder="{{ $mode === 'location' ? 'Cari SKU, barcode, nama produk atau kode lokasi...' : 'Cari SKU, barcode atau nama produk...' }}">
            </div>
            <select name="warehouse" class="form-input md:w-52" onchange="this.form.submit()">
                <option value="">Semua gudang</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected(request('warehouse') == $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                @endforeach
            </select>
            <label class="flex items-center gap-2 text-sm whitespace-nowrap text-slate-600">
                <input type="checkbox" name="zero" value="1" class="form-checkbox" @checked(request()->boolean('zero')) onchange="this.form.submit()">
                Tampilkan stok 0
            </label>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'warehouse', 'zero']))
                    <a href="{{ route('inventory.stocks.index', ['view' => $mode]) }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
            <div class="inline-flex rounded-lg border border-slate-200 p-0.5 text-sm">
                @foreach (['location' => 'Per Lokasi', 'variant' => 'Per Varian'] as $value => $label)
                    <a href="{{ route('inventory.stocks.index', [...request()->except(['view', 'page']), 'view' => $value]) }}"
                       @class([
                           'rounded-md px-3 py-1.5 font-medium transition',
                           'bg-brand-50 text-brand-700' => $mode === $value,
                           'text-slate-600 hover:text-slate-900' => $mode !== $value,
                       ])>{{ $label }}</a>
                @endforeach
            </div>
            <p class="text-sm text-slate-500">Total: <span class="font-semibold text-slate-900">{{ number_format($totalQty, 0, ',', '.') }}</span></p>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold tracking-wider text-slate-500 uppercase">
                    <tr>
                        <th class="px-5 py-3">SKU</th>
                        <th class="px-5 py-3">Produk / Varian</th>
                        @if ($mode === 'location')
                            <th class="px-5 py-3">Gudang</th>
                            <th class="px-5 py-3">Lokasi</th>
                        @else
                            <th class="px-5 py-3 text-center">Jumlah Lokasi</th>
                        @endif
                        <th class="px-5 py-3 text-right">Stok</th>
                        <th class="px-5 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($stocks as $stock)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-5 py-3.5 font-mono font-medium text-slate-900">{{ $stock->variant->sku }}</td>
                            <td class="px-5 py-3.5">
                                <p class="font-medium text-slate-900">{{ $stock->variant->product->name }}</p>
                                @if ($stock->variant->name)
                                    <p class="text-xs text-slate-500">{{ $stock->variant->name }}</p>
                                @endif
                            </td>
                            @if ($mode === 'location')
                                <td class="px-5 py-3.5 text-slate-600">{{ $stock->warehouse->code }}</td>
                                <td @class(['px-5 py-3.5', 'font-mono text-slate-700' => $stock->location, 'text-slate-400' => ! $stock->location])>{{ $stock->location?->code ?? 'Tanpa bin' }}</td>
                            @else
                                <td class="px-5 py-3.5 text-center text-slate-600">{{ $stock->location_count }}</td>
                            @endif
                            <td @class(['px-5 py-3.5 text-right font-semibold tabular-nums', 'text-slate-900' => $stock->qty > 0, 'text-slate-400' => $stock->qty <= 0])>
                                {{ number_format($stock->qty, 0, ',', '.') }}
                                <span class="text-xs font-normal text-slate-500">{{ $stock->variant->product->unit->code }}</span>
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="flex items-center justify-end gap-1">
                                    @if ($mode === 'variant')
                                        <a href="{{ route('inventory.stocks.index', ['view' => 'location', 'search' => $stock->variant->sku, 'warehouse' => request('warehouse')]) }}" class="btn-icon" title="Lihat per lokasi">
                                            <x-heroicon-o-map-pin class="size-5" />
                                        </a>
                                    @endif
                                    <a href="{{ route('inventory.stock-card.index', array_filter([
                                        'variant' => $stock->product_variant_id,
                                        'warehouse' => $mode === 'location' ? $stock->warehouse_id : request('warehouse'),
                                        'location' => $mode === 'location' ? ($stock->warehouse_location_id ?? 'none') : null,
                                    ])) }}" class="btn-icon" title="Kartu stok">
                                        <x-heroicon-o-document-chart-bar class="size-5" />
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-16 text-center">
                                <x-heroicon-o-archive-box class="mx-auto size-12 text-slate-300" />
                                <p class="mt-3 font-medium text-slate-700">Belum ada stok</p>
                                <p class="text-sm text-slate-500">Stok bertambah saat barang masuk dicatat di menu Mutasi Stok.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($stocks->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $stocks->links() }}</div>
        @endif
    </div>
</x-layouts.app>
