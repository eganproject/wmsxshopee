<table class="min-w-full text-sm">
    <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
        <tr>
            <th class="px-5 py-3">SKU</th>
            <th class="px-5 py-3">Produk / Varian</th>
            <th class="px-5 py-3">Gudang</th>
            <th class="px-5 py-3"><span class="whitespace-nowrap">Lokasi <x-hint text="Kode lokasi bin. Tanpa bin berarti stok sudah tercatat di gudang tapi belum ditaruh di rak." /></span></th>
            <th class="px-5 py-3 text-right"><span class="whitespace-nowrap">Fisik <x-hint text="Jumlah barang yang ada di lokasi bin ini." /></span></th>
            <th class="px-5 py-3 text-right">Sudah <span class="whitespace-nowrap">Diambil <x-hint text="Barang di bin ini yang sudah dialokasikan ke picking pesanan (sedang diambil / sudah dikemas) tapi belum dikirim." /></span></th>
            <th class="px-5 py-3 text-right"><span class="whitespace-nowrap">Bebas <x-hint text="Fisik dikurangi Sudah Diambil. Jumlah yang masih boleh dipindah, ditransfer atau diambil untuk pesanan lain." /></span></th>
            <th class="px-5 py-3 text-right">Aksi</th>
        </tr>
    </thead>
    <tbody class="divide-y divide-slate-100">
        @forelse ($stocks as $stock)
            @php
                $taken = (int) ($picked[$stock->id] ?? 0);
                $free = (int) $stock->qty - $taken;
            @endphp
            <tr class="hover:bg-slate-50/60">
                <td class="px-5 py-3.5 font-mono font-medium text-slate-900">{{ $stock->variant->sku }}</td>
                <td class="px-5 py-3.5">
                    <p class="font-medium text-slate-900">{{ $stock->variant->product->name }}</p>
                    @if ($stock->variant->name)
                        <p class="text-xs text-slate-500">{{ $stock->variant->name }}</p>
                    @endif
                </td>
                <td class="px-5 py-3.5 text-slate-600">{{ $stock->warehouse->code }}</td>
                <td @class(['px-5 py-3.5', 'font-mono text-slate-700' => $stock->location, 'text-slate-400' => ! $stock->location])>{{ $stock->location?->code ?? 'Tanpa bin' }}</td>
                <td @class(['px-5 py-3.5 text-right font-semibold tabular-nums', 'text-slate-900' => $stock->qty > 0, 'text-slate-400' => $stock->qty <= 0])>
                    {{ $fmt($stock->qty) }}
                    <span class="text-xs font-normal text-slate-500">{{ $stock->variant->product->unit->code }}</span>
                    @if ($cartons = $stock->variant->cartonLabel((int) $stock->qty))
                        <p class="text-xs font-normal text-slate-500">{{ $cartons }}</p>
                    @endif
                </td>
                <td @class(['px-5 py-3.5 text-right tabular-nums', 'text-sky-700' => $taken > 0, 'text-slate-400' => $taken === 0])>{{ $fmt($taken) }}</td>
                <td @class(['px-5 py-3.5 text-right font-semibold tabular-nums', 'text-slate-900' => $free > 0, 'text-slate-400' => $free === 0, 'text-rose-600' => $free < 0])>{{ $fmt($free) }}</td>
                <td class="px-5 py-3.5">
                    <div class="flex items-center justify-end gap-1">
                        <a href="{{ route('inventory.stock-card.index', array_filter([
                            'variant' => $stock->product_variant_id,
                            'warehouse' => $stock->warehouse_id,
                            'location' => $stock->warehouse_location_id ?? 'none',
                        ])) }}" class="btn-icon" title="Kartu stok">
                            <x-heroicon-o-document-chart-bar class="size-5" />
                        </a>
                    </div>
                </td>
            </tr>
        @empty
            <x-empty-state colspan="8" icon="heroicon-o-archive-box" title="Belum ada stok" description="Stok bertambah saat Penerimaan Barang dikonfirmasi atau lewat Impor Stok Awal." />
        @endforelse
    </tbody>
</table>
