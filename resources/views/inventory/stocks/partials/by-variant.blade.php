@php
    $badge = [
        'rose' => 'border-rose-200 text-rose-700',
        'slate' => 'border-slate-200 text-slate-600',
        'amber' => 'border-amber-200 text-amber-700',
        'emerald' => 'border-emerald-200 text-emerald-700',
    ];
    $showSellable = request()->filled('warehouse');
@endphp

<table class="min-w-full text-sm">
    <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
        <tr>
            <th class="px-5 py-3">SKU</th>
            <th class="px-5 py-3">Produk / Varian</th>
            <th class="px-5 py-3 text-right" title="Barang di rak, termasuk yang sudah diambil tapi belum dikirim">Fisik</th>
            <th class="px-5 py-3 text-right" title="Dicadangkan pesanan Dikonfirmasi, Sudah Diambil dan Dikemas">Dipesan</th>
            <th class="px-5 py-3 text-right" title="Fisik dikurangi Dipesan">Tersedia</th>
            <th class="px-5 py-3 text-right" title="Qty di pesanan Draft yang belum mencadangkan stok, mis. gagal dikonfirmasi karena stok kurang">Tertahan</th>
            @if ($showSellable)
                <th class="px-5 py-3 text-right" title="Stok yang dikirim ke Shopee / TikTok, termasuk jatah gudang cadangan">Stok Jual</th>
            @endif
            <th class="px-5 py-3">Status</th>
            <th class="px-5 py-3 text-right">Aksi</th>
        </tr>
    </thead>
    <tbody class="divide-y divide-slate-100">
        @forelse ($stocks as $row)
            @php
                $unit = $row->product?->unit?->code;
                $status = \App\Support\StockOverview::status($row);
                $info = \App\Support\StockOverview::STATUSES[$status];
            @endphp
            <tr class="hover:bg-slate-50/60">
                <td class="px-5 py-3.5 font-mono font-medium text-slate-900">{{ $row->sku }}</td>
                <td class="px-5 py-3.5">
                    <p class="font-medium text-slate-900">{{ $row->product?->name }}</p>
                    @if ($row->name)
                        <p class="text-xs text-slate-500">{{ $row->name }}</p>
                    @endif
                </td>
                <td @class(['px-5 py-3.5 text-right tabular-nums', 'text-slate-900' => $row->on_hand > 0, 'text-slate-400' => $row->on_hand <= 0])>
                    {{ $fmt($row->on_hand) }}
                    <p class="text-xs text-slate-500">{{ $row->location_count }} lokasi</p>
                </td>
                <td class="px-5 py-3.5 text-right tabular-nums">
                    @if ($row->reserved > 0)
                        <a href="{{ route('sales.orders.index', array_filter(['search' => $row->sku, 'warehouse' => request('warehouse')])) }}"
                           class="font-medium text-sky-700 hover:underline"
                           title="Belum diambil: {{ $fmt($row->reserved_waiting) }} · Sudah diambil / dikemas: {{ $fmt($row->reserved_picked) }}">{{ $fmt($row->reserved) }}</a>
                        @if ($row->reserved_picked > 0)
                            <p class="text-xs text-slate-500">{{ $fmt($row->reserved_picked) }} sudah diambil</p>
                        @endif
                    @else
                        <span class="text-slate-400">0</span>
                    @endif
                </td>
                <td @class(['px-5 py-3.5 text-right font-semibold tabular-nums', 'text-slate-900' => $row->available > 0, 'text-rose-600' => $row->available <= 0])>
                    {{ $fmt($row->available) }}
                    <span class="text-xs font-normal text-slate-500">{{ $unit }}</span>
                    @if ($row->available > 0 && ($cartons = $row->cartonLabel((int) $row->available)))
                        <p class="text-xs font-normal text-slate-500">{{ $cartons }}</p>
                    @endif
                </td>
                <td class="px-5 py-3.5 text-right tabular-nums">
                    @if ($row->held > 0)
                        <a href="{{ route('sales.orders.index', array_filter(['search' => $row->sku, 'status' => 'draft', 'warehouse' => request('warehouse')])) }}"
                           class="font-medium text-amber-700 hover:underline" title="Lihat pesanan draft">{{ $fmt($row->held) }}</a>
                    @else
                        <span class="text-slate-400">0</span>
                    @endif
                </td>
                @if ($showSellable)
                    <td class="px-5 py-3.5 text-right tabular-nums text-slate-700">{{ $fmt($sellable[$row->id] ?? 0) }}</td>
                @endif
                <td class="px-5 py-3.5">
                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] whitespace-nowrap {{ $badge[$info['color']] }}" title="{{ $info['hint'] }}">{{ $info['label'] }}</span>
                    @if ($row->minimum !== null)
                        <p class="mt-0.5 text-[11px] text-slate-500">Min. {{ $fmt($row->minimum) }}</p>
                    @endif
                </td>
                <td class="px-5 py-3.5">
                    <div class="flex items-center justify-end gap-1">
                        <a href="{{ route('inventory.stocks.index', array_filter(['view' => 'location', 'search' => $row->sku, 'warehouse' => request('warehouse')])) }}" class="btn-icon" title="Lihat per lokasi">
                            <x-heroicon-o-map-pin class="size-5" />
                        </a>
                        <a href="{{ route('inventory.stock-card.index', array_filter(['variant' => $row->id, 'warehouse' => request('warehouse')])) }}" class="btn-icon" title="Kartu stok">
                            <x-heroicon-o-document-chart-bar class="size-5" />
                        </a>
                    </div>
                </td>
            </tr>
        @empty
            @if (request()->hasAny(['search', 'status']))
                <x-empty-state :colspan="$showSellable ? 9 : 8" icon="heroicon-o-magnifying-glass" title="Tidak ada SKU yang cocok" description="Ubah kata kunci atau filter status." />
            @else
                <x-empty-state :colspan="$showSellable ? 9 : 8" icon="heroicon-o-archive-box" title="Belum ada stok" description="Stok bertambah saat Penerimaan Barang dikonfirmasi atau lewat Impor Stok Awal." />
            @endif
        @endforelse
    </tbody>
</table>
