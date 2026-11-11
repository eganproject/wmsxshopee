@php
    $fmt = fn ($value) => number_format((int) $value, 0, ',', '.');
    $classes = \App\Support\StockAnalytics::MOVING_CLASSES;
    $tone = [
        'emerald' => 'bg-emerald-50 text-emerald-700',
        'sky' => 'bg-sky-50 text-sky-700',
        'amber' => 'bg-amber-50 text-amber-800',
        'slate' => 'bg-slate-100 text-slate-600',
    ];
    $text = ['emerald' => 'text-emerald-600', 'sky' => 'text-sky-600', 'amber' => 'text-amber-600', 'slate' => 'text-slate-900'];
    $hints = [
        'fast' => "SKU terlaris yang bersama-sama menyumbang {$fast}% pertama dari total terjual.",
        'medium' => "SKU yang menyumbang {$fast}% sampai {$medium}% dari total terjual.",
        'slow' => "SKU yang terjual tapi hanya menyumbang ".(100 - $medium)."% terakhir dari total terjual.",
        'dead' => 'Masih ada stok tapi tidak terjual sama sekali dalam periode ini.',
    ];
@endphp

<x-layouts.app title="Fast / Slow Moving">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Laporan</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Fast / Slow Moving</span>
    </x-slot:breadcrumb>

    <x-page-header title="Pergerakan Stok (Fast / Medium / Slow Moving)" :description="'Penggolongan SKU berdasarkan qty terjual '.$from->translatedFormat('d M Y').' – '.$to->translatedFormat('d M Y').' (total '.$fmt($totalSold).' pcs).'" icon="heroicon-o-arrow-trending-up">
        <x-reports.export-button />
    </x-page-header>

    <x-reports.tabs />

    <div class="mb-6 grid gap-4 sm:grid-cols-4">
        @foreach ($classes as $key => $meta)
            <a href="{{ request()->fullUrlWithQuery(['class' => request('class') === $key ? null : $key, 'page' => null]) }}"
               @class(['card p-4 transition hover:border-slate-300', 'ring-2 ring-brand-500' => request('class') === $key])>
                <p class="flex items-center gap-1 text-xs text-slate-500">{{ $meta['label'] }} <x-hint :text="$hints[$key]" /></p>
                <p class="mt-1 text-xl font-semibold tabular-nums {{ $text[$meta['color']] }}">{{ $fmt($summary[$key]['skus']) }} <span class="text-xs font-normal text-slate-500">SKU</span></p>
                <p class="mt-1 text-xs text-slate-500">Terjual {{ $fmt($summary[$key]['sold']) }} · Stok {{ $fmt($summary[$key]['stock']) }}</p>
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
            <select name="class" class="form-input lg:w-44" onchange="this.form.submit()" aria-label="Kelas">
                <option value="">Semua kelas</option>
                @foreach ($classes as $key => $meta)
                    <option value="{{ $key }}" @selected(request('class') === $key)>{{ $meta['label'] }}</option>
                @endforeach
            </select>
            <div class="flex items-center gap-2">
                <input type="date" name="from" value="{{ $from->toDateString() }}" class="form-input w-40" aria-label="Dari tanggal">
                <span class="text-slate-400">–</span>
                <input type="date" name="to" value="{{ $to->toDateString() }}" class="form-input w-40" aria-label="Sampai tanggal">
            </div>
            <label class="flex items-center gap-2 text-sm whitespace-nowrap text-slate-600">Fast ≤
                <input type="number" name="fast" value="{{ $fast }}" min="10" max="95" class="form-input w-16"> %
            </label>
            <label class="flex items-center gap-2 text-sm whitespace-nowrap text-slate-600">Medium ≤
                <input type="number" name="medium" value="{{ $medium }}" min="11" max="99" class="form-input w-16"> %
            </label>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Terapkan</button>
                @if (request()->hasAny(['search', 'warehouse', 'class', 'from', 'to', 'fast', 'medium']))
                    <a href="{{ route('reports.moving') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">SKU</th>
                        <th class="h-8 px-4 py-0 font-normal">Produk / Varian</th>
                        <th class="h-8 px-4 py-0 font-normal">Kelas</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Terjual</th>
                        <th class="h-8 px-4 py-0 text-right font-normal"><span class="whitespace-nowrap">Kali Keluar <x-hint text="Berapa kali SKU ini keluar karena pengiriman pesanan." /></span></th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Rata-rata/Hari</th>
                        <th class="h-8 px-4 py-0 text-right font-normal"><span class="whitespace-nowrap">Porsi <x-hint text="Persentase SKU ini dari total qty terjual. Kumulatif = jumlah porsi dari SKU terlaris sampai baris ini." /></span></th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Stok Sekarang</th>
                        <th class="h-8 px-4 py-0 text-right font-normal"><span class="whitespace-nowrap">Cukup <x-hint text="Perkiraan stok cukup untuk berapa hari lagi pada rata-rata penjualan periode ini." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal">Terakhir Terjual</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        @php($meta = $classes[$row->class])
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 font-mono font-medium text-slate-900">{{ $row->sku }}</td>
                            <td class="cell-wrap px-4 py-2.5">
                                <p class="text-slate-900">{{ $row->product_name }}</p>
                                @if ($row->variant_name)<p class="text-xs text-slate-500">{{ $row->variant_name }}</p>@endif
                            </td>
                            <td class="px-4 py-2.5"><span class="rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap {{ $tone[$meta['color']] }}">{{ $meta['label'] }}</span></td>
                            <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-900">{{ $fmt($row->sold) }} <span class="text-xs font-normal text-slate-500">{{ $row->unit_code }}</span></td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ $fmt($row->out_count) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ number_format($row->daily, 1, ',', '.') }}</td>
                            <td class="px-4 py-2.5 text-right whitespace-nowrap tabular-nums text-slate-700">
                                {{ number_format($row->share, 1, ',', '.') }}%
                                <span class="block text-xs text-slate-400">kum. {{ number_format($row->cumulative, 1, ',', '.') }}%</span>
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums {{ $row->stock <= 0 ? 'text-rose-600' : 'text-slate-900' }}">{{ $fmt($row->stock) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ $row->cover_days !== null ? $fmt($row->cover_days).' hari' : '—' }}</td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-slate-700">{{ $row->last_sold ? \Illuminate\Support\Carbon::parse($row->last_sold)->translatedFormat('d M Y') : '—' }}</td>
                        </tr>
                    @empty
                        <x-empty-state colspan="10" icon="heroicon-o-arrow-trending-up" title="Tidak ada data" description="Belum ada SKU yang terjual atau memiliki stok untuk filter ini." />
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $rows->links() }}</div>
        @endif
    </div>

    <p class="mt-4 flex items-center gap-1.5 text-xs text-slate-500">
        <x-heroicon-o-information-circle class="size-4" /> Penggolongan memakai metode ABC (Pareto) dari qty pesanan yang sudah dikirim. Batas persen Fast dan Medium bisa diubah di filter.
    </p>
</x-layouts.app>
