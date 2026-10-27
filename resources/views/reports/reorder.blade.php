@php($fmt = fn ($value) => number_format((int) $value, 0, ',', '.'))

<x-layouts.app title="Saran Pembelian">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Laporan</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Saran Pembelian</span>
    </x-slot:breadcrumb>

    <x-page-header title="Saran Pembelian" description="Jumlah yang disarankan untuk dibeli ulang berdasarkan stok minimum dan rata-rata penjualan." icon="heroicon-o-shopping-bag">
        <x-reports.export-button />
    </x-page-header>

    <x-reports.tabs />

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <div class="card p-4">
            <p class="text-xs text-slate-500">SKU Perlu <span class="whitespace-nowrap">Dibeli <x-hint text="Jumlah SKU yang saran belinya lebih dari nol." /></span></p>
            <p @class(['mt-1 text-xl font-semibold tabular-nums', 'text-rose-600' => $summary['skus'] > 0, 'text-slate-900' => ! $summary['skus']])>{{ $fmt($summary['skus']) }} SKU</p>
            <p class="text-xs text-slate-500">{{ $fmt($summary['below']) }} sudah di bawah stok minimum</p>
        </div>
        <div class="card p-4">
            <p class="text-xs text-slate-500">Total Saran <span class="whitespace-nowrap">Beli <x-hint text="Total pcs yang disarankan untuk dibeli dari semua SKU." /></span></p>
            <p class="mt-1 text-xl font-semibold tabular-nums text-slate-900">{{ $fmt($summary['qty']) }}</p>
            <p class="text-xs text-slate-500">unit, semua SKU</p>
        </div>
        <div class="card flex items-start gap-2 p-4 text-xs text-slate-500">
            <x-heroicon-o-information-circle class="size-4 shrink-0" />
            <span>
                Stok target = stok minimum + rata-rata terjual per hari × {{ $suggestions->coverDays }} hari.
                Saran beli = stok target − tersedia − qty di draft penerimaan. Penjualan paket ikut dihitung ke komponennya.
            </span>
        </div>
    </div>

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:flex-wrap lg:items-center">
            <div class="relative flex-1 lg:min-w-56">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari SKU atau nama produk..." class="form-input pl-8">
            </div>
            <select name="warehouse" class="form-input lg:w-48" onchange="this.form.submit()">
                <option value="">Semua gudang</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected(request('warehouse') == $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                @endforeach
            </select>
            <label class="flex items-center gap-2 text-sm text-slate-600">
                Penjualan
                <input type="number" name="lookback" min="1" max="365" value="{{ $suggestions->lookbackDays }}" class="form-input w-20" data-hint-title="Periode penjualan" data-hint="Rata-rata penjualan harian dihitung dari sekian hari terakhir. Pakai periode lebih panjang untuk barang yang lakunya tidak menentu.">
                hari
            </label>
            <label class="flex items-center gap-2 text-sm text-slate-600">
                Stok untuk
                <input type="number" name="cover" min="0" max="365" value="{{ $suggestions->coverDays }}" class="form-input w-20" data-hint-title="Stok untuk berapa hari" data-hint="Target stok = stok minimum + rata-rata terjual per hari × jumlah hari ini. Sesuaikan dengan lama pengiriman dari supplier.">
                hari
            </label>
            <select name="show" class="form-input lg:w-56" onchange="this.form.submit()">
                <option value="">Hanya yang perlu dibeli</option>
                <option value="all" @selected(request('show') === 'all')>Semua SKU dipantau</option>
            </select>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'warehouse', 'show', 'lookback', 'cover']))
                    <a href="{{ route('reports.reorder') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">SKU</th>
                        <th class="h-8 px-4 py-0 font-normal">Produk / Varian</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Stok <span class="whitespace-nowrap">Min. <x-hint text="Batas stok minimum dari Master Produk." /></span></th>
                        <th class="h-8 px-4 py-0 text-right font-normal"><span class="whitespace-nowrap">Tersedia <x-hint text="Stok fisik dikurangi yang sudah dicadangkan pesanan." /></span></th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Draft <span class="whitespace-nowrap">Terima <x-hint text="Barang yang sudah dicatat di Penerimaan Barang tapi belum dikonfirmasi diterima. Dianggap akan datang, jadi mengurangi saran beli." /></span></th>
                        <th class="h-8 px-4 py-0 text-right font-normal"><span class="whitespace-nowrap">Terjual/Hari <x-hint text="Rata-rata terjual per hari dalam {{ $suggestions->lookbackDays }} hari terakhir." /></span></th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Habis <span class="whitespace-nowrap">Dalam <x-hint text="Perkiraan berapa hari lagi stok tersedia habis dengan kecepatan jual saat ini." /></span></th>
                        <th class="h-8 px-4 py-0 text-right font-normal"><span class="whitespace-nowrap">Target <x-hint text="Stok minimum + rata-rata terjual per hari × jumlah hari stok." /></span></th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Saran <span class="whitespace-nowrap">Beli <x-hint text="Target dikurangi Tersedia dan Draft Terima. Jumlah yang disarankan untuk dipesan ke supplier." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal">Supplier <span class="whitespace-nowrap">Terakhir <x-hint text="Supplier dari penerimaan barang terakhir untuk SKU ini, sebagai acuan pemesanan." /></span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 font-mono font-medium text-slate-900">
                                @can('inventory.view')<a href="{{ route('inventory.stocks.index', ['view' => 'location', 'search' => $row->sku, 'warehouse' => request('warehouse')]) }}" class="hover:text-brand-600" title="Lihat stok per lokasi">{{ $row->sku }}</a>@else{{ $row->sku }}@endcan
                            </td>
                            <td class="px-4 py-2.5">
                                <p class="text-slate-900">{{ $row->product_name }}</p>
                                @if ($row->variant_name)
                                    <p class="text-xs text-slate-500">{{ $row->variant_name }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ $row->min_stock === null ? '—' : $fmt($row->min_stock) }}</td>
                            <td @class(['px-4 py-2.5 text-right font-semibold tabular-nums', 'text-rose-600' => $row->available <= 0, 'text-amber-700' => $row->available > 0 && $row->below_min, 'text-slate-900' => $row->available > 0 && ! $row->below_min])>
                                {{ $fmt($row->available) }} <span class="text-xs font-normal text-slate-500">{{ $row->unit_code }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ $row->incoming ? $fmt($row->incoming) : '—' }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ $row->daily > 0 ? number_format($row->daily, 1, ',', '.') : '—' }}</td>
                            <td @class(['px-4 py-2.5 text-right tabular-nums', 'font-medium text-rose-600' => $row->days_left !== null && $row->days_left <= 7, 'text-slate-600' => $row->days_left === null || $row->days_left > 7])>
                                {{ $row->days_left === null ? '—' : $fmt($row->days_left).' hari' }}
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ $fmt($row->target) }}</td>
                            <td class="px-4 py-2.5 text-right">
                                @if ($row->suggested > 0)
                                    <span class="inline-flex rounded-full bg-brand-50 px-2.5 py-0.5 text-sm font-semibold tabular-nums text-brand-700">{{ $fmt($row->suggested) }}</span>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="max-w-40 truncate px-4 py-2.5 text-slate-600">{{ $suppliers[$row->id] ?? '—' }}</td>
                        </tr>
                    @empty
                        <x-empty-state colspan="10" icon="heroicon-o-shopping-bag" title="Belum ada yang perlu dibeli" :description="'Saran muncul untuk varian yang punya stok minimum atau terjual dalam '.$suggestions->lookbackDays.' hari terakhir.'" />
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $rows->links() }}</div>
        @endif
    </div>
</x-layouts.app>
