@php
    $canViewOrders = auth()->user()->can('sales.view');
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
            <th class="px-5 py-3 text-right"><span class="whitespace-nowrap">Fisik <x-hint text="Jumlah barang yang secara fisik ada di rak gudang, termasuk yang sudah diambil picker tapi belum dikirim." /></span></th>
            <th class="px-5 py-3 text-right"><span class="whitespace-nowrap">Dipesan <x-hint text="Stok yang sudah dicadangkan untuk pesanan Dikonfirmasi, Sedang Diambil dan Sudah Dikemas. Berkurang dari fisik saat pesanan dikirim." /></span></th>
            <th class="px-5 py-3 text-right"><span class="whitespace-nowrap">Tersedia <x-hint text="Fisik dikurangi Dipesan. Inilah stok yang masih bisa menerima pesanan baru." /></span></th>
            <th class="px-5 py-3 text-right"><span class="whitespace-nowrap">Tertahan <x-hint text="Jumlah barang di pesanan Draft yang belum mencadangkan stok, misalnya gagal dikonfirmasi karena stok kurang atau SKU belum terhubung." next="Lengkapi stok atau SKU, lalu konfirmasi pesanan draftnya." /></span></th>
            @if ($showSellable)
                <th class="px-5 py-3 text-right">Stok <span class="whitespace-nowrap">Jual <x-hint text="Stok yang dikirim ke Shopee / TikTok: tersedia di gudang ini ditambah jatah gudang cadangan dan barang yang sedang dalam perjalanan." /></span></th>
            @endif
            <th class="px-5 py-3"><span class="whitespace-nowrap">Status <x-hint text="Aman: stok cukup. Menipis: tersedia sama atau di bawah stok minimum. Habis: tidak ada stok tersedia. Kurang: pesanan melebihi stok tersedia." /></span></th>
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
                        {{-- Angka jadi link ke daftar pesanan hanya untuk user dengan hak sales.view. --}}
                        <{{ $canViewOrders ? 'a' : 'span' }} @if ($canViewOrders) href="{{ route('sales.orders.index', array_filter(['search' => $row->sku, 'warehouse' => request('warehouse')])) }}" @endif
                           @class(['font-medium text-sky-700', 'hover:underline' => $canViewOrders])
                           title="Belum diambil: {{ $fmt($row->reserved_waiting) }} · Sudah diambil / dikemas: {{ $fmt($row->reserved_picked) }}">{{ $fmt($row->reserved) }}</{{ $canViewOrders ? 'a' : 'span' }}>
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
                        <{{ $canViewOrders ? 'a' : 'span' }} @if ($canViewOrders) href="{{ route('sales.orders.index', array_filter(['search' => $row->sku, 'status' => 'draft', 'warehouse' => request('warehouse')])) }}" @endif
                           @class(['font-medium text-amber-700', 'hover:underline' => $canViewOrders]) title="Pesanan draft">{{ $fmt($row->held) }}</{{ $canViewOrders ? 'a' : 'span' }}>
                    @else
                        <span class="text-slate-400">0</span>
                    @endif
                </td>
                @if ($showSellable)
                    <td class="px-5 py-3.5 text-right tabular-nums text-slate-700">{{ $fmt($sellable[$row->id] ?? 0) }}</td>
                @endif
                <td class="px-5 py-3.5">
                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] cursor-help whitespace-nowrap {{ $badge[$info['color']] }}" data-hint-title="{{ $info['label'] }}" data-hint="{{ $info['hint'] }}">{{ $info['label'] }}</span>
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
