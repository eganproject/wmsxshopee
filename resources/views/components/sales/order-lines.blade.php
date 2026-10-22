@props(['order', 'limit' => 3])

{{-- Ringkasan SKU yang dipesan di daftar pesanan: barang lepas, paket, dan SKU marketplace yang belum terhubung. --}}
@php
    $lines = collect();
    foreach ($order->looseItems as $item) {
        $lines->push([
            'sku' => $item->variant?->sku ?? '—',
            'name' => trim(($item->variant?->product?->name ?? '').($item->variant?->name ? ' · '.$item->variant->name : '')),
            'qty' => (int) $item->qty,
            'tag' => null,
        ]);
    }
    foreach ($order->bundles as $line) {
        $lines->push(['sku' => $line->bundle?->sku ?? '—', 'name' => $line->bundle?->name ?? '', 'qty' => (int) $line->qty, 'tag' => 'paket']);
    }
    if ($order->hasStatus('draft')) {
        foreach ($order->unmapped_skus ?? [] as $sku) {
            $lines->push(['sku' => $sku ?: 'Tanpa SKU', 'name' => 'SKU marketplace belum terhubung ke produk', 'qty' => null, 'tag' => 'unmapped']);
        }
    }
    $total = (int) $order->looseItems->sum('qty') + (int) $order->bundles->sum('qty');
    $hidden = max(0, $lines->count() - $limit);
    $mapped = $lines->where('tag', '!=', 'unmapped')->count();
@endphp

@if ($lines->isEmpty())
    <span class="text-slate-400">—</span>
@else
    <div {{ $attributes->class('w-52') }} x-data="{ more: false }">
        <ul class="space-y-1">
            @foreach ($lines as $line)
                <li class="flex items-baseline gap-2" @if ($loop->index >= $limit) x-show="more" x-cloak @endif title="{{ $line['sku'] }}{{ $line['name'] ? ' · '.$line['name'] : '' }}">
                    <span class="min-w-0 flex-1 truncate">
                        <span @class(['font-mono text-xs font-medium', 'text-slate-900' => $line['tag'] !== 'unmapped', 'text-amber-700' => $line['tag'] === 'unmapped'])>{{ $line['sku'] }}</span>
                        @if ($line['tag'] === 'paket')
                            <span class="ml-0.5 rounded bg-violet-50 px-1 text-[10px] font-medium text-violet-700">Paket</span>
                        @elseif ($line['tag'] === 'unmapped')
                            <span class="ml-0.5 rounded bg-amber-50 px-1 text-[10px] font-medium text-amber-700">Belum terhubung</span>
                        @endif
                        @if ($line['name'] && $line['tag'] !== 'unmapped')
                            <span class="text-xs text-slate-500">{{ $line['name'] }}</span>
                        @endif
                    </span>
                    @if ($line['qty'] !== null)
                        <span class="shrink-0 text-xs font-semibold tabular-nums text-slate-900">×{{ number_format($line['qty'], 0, ',', '.') }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
        @if ($hidden || $mapped > 1)
            <p class="mt-1 flex items-center gap-2 text-[11px] text-slate-500">
                @if ($hidden)
                    <button type="button" class="font-medium text-sky-700 hover:underline" @click="more = ! more"
                            x-text="more ? 'Sembunyikan' : '+{{ $hidden }} SKU lainnya'">+{{ $hidden }} SKU lainnya</button>
                    <span>·</span>
                @endif
                <span>{{ $mapped }} SKU · {{ number_format($total, 0, ',', '.') }} barang</span>
            </p>
        @endif
    </div>
@endif
