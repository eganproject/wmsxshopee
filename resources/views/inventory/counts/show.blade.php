@php
    $counted = $count->items->whereNotNull('counted_qty');
    $variances = $counted->map(fn ($item) => $item->variance());
@endphp

<x-layouts.app :title="$count->number">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('inventory.counts.index') }}" class="hover:text-slate-700">Stock Opname</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">{{ $count->number }}</span>
    </x-slot:breadcrumb>

    <x-page-header :title="$count->number" :description="'Opname '.$count->warehouse->code.' · '.$count->scopeLabel().' · '.$count->count_date->translatedFormat('d F Y')" icon="heroicon-o-clipboard-document-check">
        <a href="{{ route('inventory.counts.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
        <a href="{{ route('inventory.counts.sheet', ['count' => $count, 'system' => 1]) }}" target="_blank" class="btn-secondary"><x-heroicon-o-printer class="size-5" /> Cetak</a>
    </x-page-header>

    <div class="card mb-6 grid gap-5 p-5 sm:grid-cols-3 lg:grid-cols-6">
        <div>
            <p class="text-xs text-slate-500">Status</p>
            <p class="mt-1"><x-inventory.count-status :status="$count->status" /></p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Dihitung</p>
            <p class="mt-1 text-sm font-semibold tabular-nums text-slate-900">{{ $counted->count() }} / {{ $count->items->count() }} baris</p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Selisih Lebih / Kurang</p>
            <p class="mt-1 text-sm font-semibold tabular-nums">
                <span class="text-emerald-600">+{{ $variances->filter(fn ($v) => $v > 0)->sum() }}</span> /
                <span class="text-rose-600">{{ $variances->filter(fn ($v) => $v < 0)->sum() }}</span>
            </p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Mutasi Penyesuaian</p>
            @if ($count->movement)
                <a href="{{ route('inventory.movements.show', $count->movement) }}" class="mt-1 inline-block font-mono text-sm text-brand-600 hover:underline">{{ $count->movement->number }}</a>
            @else
                <p class="mt-1 text-sm text-slate-400">Tidak ada selisih</p>
            @endif
        </div>
        <div>
            <p class="text-xs text-slate-500">Dibuat oleh</p>
            <p class="mt-1 text-sm text-slate-700">{{ $count->creator?->name ?? '—' }}</p>
            <p class="text-xs text-slate-500">{{ $count->created_at->format('d/m/Y H:i') }}</p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Diposting oleh</p>
            <p class="mt-1 text-sm text-slate-700">{{ $count->poster?->name ?? '—' }}</p>
            <p class="text-xs text-slate-500">{{ $count->posted_at?->format('d/m/Y H:i') }}</p>
        </div>
        @if ($count->notes)
            <div class="sm:col-span-3 lg:col-span-6">
                <p class="text-xs text-slate-500">Catatan</p>
                <p class="mt-1 text-sm whitespace-pre-line text-slate-700">{{ $count->notes }}</p>
            </div>
        @endif
    </div>

    <div class="card">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">#</th>
                        <th class="h-8 px-4 py-0 font-normal">Lokasi</th>
                        <th class="h-8 px-4 py-0 font-normal">SKU</th>
                        <th class="h-8 px-4 py-0 font-normal">Produk / Varian</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Sistem</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Hasil Hitung</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Selisih</th>
                        <th class="h-8 px-4 py-0 font-normal">Ditaruh ke Bin</th>
                        <th class="h-8 px-4 py-0 font-normal">Catatan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($count->items as $item)
                        @php($variance = $item->variance())
                        <tr>
                            <td class="px-4 py-2.5 text-slate-400">{{ $loop->iteration }}</td>
                            <td @class(['px-4 py-2.5', 'font-mono text-slate-700' => $item->location, 'text-slate-400' => ! $item->location])>{{ $item->location?->code ?? 'Tanpa bin' }}</td>
                            <td class="px-4 py-2.5 font-mono font-medium text-slate-900">{{ $item->variant->sku }}</td>
                            <td class="px-4 py-2.5">
                                <p class="text-slate-900">{{ $item->variant->product->name }}</p>
                                @if ($item->variant->name)
                                    <p class="text-xs text-slate-500">{{ $item->variant->name }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ number_format($item->system_qty, 0, ',', '.') }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-900">{{ $item->counted_qty === null ? '—' : number_format($item->counted_qty, 0, ',', '.') }}</td>
                            <td @class(['px-4 py-2.5 text-right font-medium tabular-nums', 'text-slate-400' => ! $variance, 'text-emerald-600' => $variance > 0, 'text-rose-600' => $variance < 0])>
                                {{ $variance === null ? 'Tidak dihitung' : ($variance > 0 ? '+'.$variance : $variance) }}
                            </td>
                            <td class="px-4 py-2.5 font-mono text-slate-700">{{ $item->target?->code ?? '' }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $item->notes ?? '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <p class="mt-4 flex items-center gap-1.5 text-xs text-slate-500">
        <x-heroicon-o-information-circle class="size-4" /> Kolom Sistem adalah saldo saat posting; stok tiap baris yang dihitung sudah disetel ke hasil hitung.
    </p>
</x-layouts.app>
