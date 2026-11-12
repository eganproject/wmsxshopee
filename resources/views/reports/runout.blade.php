@php
    $fmt = fn ($value) => number_format((int) $value, 0, ',', '.');
    $statuses = \App\Support\StockAnalytics::RUNOUT_STATUSES;
    $tone = [
        'rose' => 'bg-rose-50 text-rose-700',
        'amber' => 'bg-amber-50 text-amber-800',
        'emerald' => 'bg-emerald-50 text-emerald-700',
        'slate' => 'bg-slate-100 text-slate-600',
    ];
@endphp

<x-layouts.app title="Prakiraan Stok Habis">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Laporan</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Prakiraan Stok Habis</span>
    </x-slot:breadcrumb>

    <x-page-header title="Prakiraan Stok Habis" :description="'Kapan stok tiap SKU per gudang habis bila penjualan seperti '.$lookback.' hari terakhir. Sisa hari = stok tersedia ÷ rata-rata terjual per hari.'" icon="heroicon-o-clock">
        <x-reports.export-button />
    </x-page-header>

    <x-reports.tabs />

    <div class="mb-6 grid gap-4 sm:grid-cols-5">
        @foreach ($statuses as $key => $meta)
            <a href="{{ request()->fullUrlWithQuery(['status' => request('status') === $key ? null : $key, 'page' => null]) }}"
               @class(['card p-4 transition hover:border-slate-300', 'ring-2 ring-brand-500' => request('status') === $key])>
                <p class="flex items-center gap-1 text-xs text-slate-500">{{ $meta['label'] }} <x-hint :text="$meta['hint']" /></p>
                <p @class(['mt-1 text-xl font-semibold tabular-nums', 'text-rose-600' => $meta['color'] === 'rose', 'text-amber-600' => $meta['color'] === 'amber', 'text-emerald-600' => $meta['color'] === 'emerald', 'text-slate-900' => $meta['color'] === 'slate'])>{{ $fmt($counts[$key] ?? 0) }}</p>
            </a>
        @endforeach
    </div>

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:flex-wrap lg:items-center">
            <div class="relative min-w-56 flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari SKU atau nama produk..." class="form-input pl-8">
            </div>
            <select name="warehouse" class="form-input lg:w-48" onchange="this.form.submit()" aria-label="Gudang">
                <option value="">Semua gudang</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected(request('warehouse') == $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                @endforeach
            </select>
            <select name="status" class="form-input lg:w-44" onchange="this.form.submit()" aria-label="Status">
                <option value="">Semua kecuali tidak terjual</option>
                @foreach ($statuses as $key => $meta)
                    <option value="{{ $key }}" @selected(request('status') === $key)>{{ $meta['label'] }}</option>
                @endforeach
                <option value="all" @selected(request('status') === 'all')>Semua</option>
            </select>
            <label class="flex items-center gap-2 text-sm whitespace-nowrap text-slate-600">Hitung dari
                <input type="number" name="lookback" value="{{ $lookback }}" min="7" max="180" class="form-input w-20"> hari
            </label>
            <label class="flex items-center gap-2 text-sm whitespace-nowrap text-slate-600">Kritis ≤
                <input type="number" name="critical" value="{{ $critical }}" min="1" max="90" class="form-input w-16">
            </label>
            <label class="flex items-center gap-2 text-sm whitespace-nowrap text-slate-600">Waspada ≤
                <input type="number" name="warning" value="{{ $warning }}" min="2" max="180" class="form-input w-16"> hari
            </label>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Terapkan</button>
                @if (request()->hasAny(['search', 'warehouse', 'status', 'lookback', 'critical', 'warning']))
                    <a href="{{ route('reports.runout') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
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
                        <th class="h-8 px-4 py-0 text-right font-normal"><span class="whitespace-nowrap">Tersedia <x-hint text="Stok fisik dikurangi yang sudah dicadangkan pesanan." /></span></th>
                        <th class="h-8 px-4 py-0 text-right font-normal"><span class="whitespace-nowrap">Terjual {{ $lookback }} Hari <x-hint text="Qty pesanan yang sudah dikirim dalam periode hitung." /></span></th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Rata-rata/Hari</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Sisa Hari</th>
                        <th class="h-8 px-4 py-0 font-normal">Perkiraan Habis</th>
                        <th class="h-8 px-4 py-0 font-normal">Status</th>
                        <th class="h-8 px-4 py-0 text-right font-normal"><span class="whitespace-nowrap">Akan Masuk <x-hint text="Qty di draft Penerimaan Barang yang belum diterima." /></span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        @php($meta = $statuses[$row->status])
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 font-mono font-medium text-slate-900">{{ $row->sku }}</td>
                            <td class="cell-wrap px-4 py-2.5">
                                <p class="text-slate-900">{{ $row->product_name }}</p>
                                @if ($row->variant_name)<p class="text-xs text-slate-500">{{ $row->variant_name }}</p>@endif
                            </td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $row->warehouse_code }}</td>
                            <td class="px-4 py-2.5 text-right font-semibold tabular-nums {{ $row->available <= 0 ? 'text-rose-600' : 'text-slate-900' }}">{{ $fmt($row->available) }} <span class="text-xs font-normal text-slate-500">{{ $row->unit_code }}</span></td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ $fmt($row->sold) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ number_format($row->daily, 1, ',', '.') }}</td>
                            <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-900">{{ $row->days_left ?? '—' }}</td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-slate-700">{{ $row->runout_date?->translatedFormat('d M Y') ?? '—' }}</td>
                            <td class="px-4 py-2.5"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $tone[$meta['color']] }}">{{ $meta['label'] }}</span></td>
                            <td class="px-4 py-2.5 text-right tabular-nums {{ $row->incoming ? 'text-emerald-600' : 'text-slate-400' }}">{{ $fmt($row->incoming) }}</td>
                        </tr>
                    @empty
                        <x-empty-state colspan="10" icon="heroicon-o-clock" title="Tidak ada data" description="Belum ada SKU yang terjual atau memiliki stok untuk filter ini." />
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $rows->links() }}</div>
        @endif
    </div>

    <p class="mt-4 flex items-center gap-1.5 text-xs text-slate-500">
        <x-heroicon-o-information-circle class="size-4" /> Perkiraan memakai rata-rata penjualan, jadi bisa meleset saat promo atau musim ramai. Stok yang akan masuk belum dihitung ke sisa hari.
    </p>
</x-layouts.app>
