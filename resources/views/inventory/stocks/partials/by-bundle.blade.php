{{-- Stok virtual produk paket: dihitung dari stok tersedia barang isinya. --}}
@php
    $canViewOrders = auth()->user()->can('sales.view');
    $canEditBundles = auth()->user()->can('products.edit');
    $showSellable = request()->filled('warehouse');
    $columns = $showSellable ? 7 : 6;
@endphp

<table class="min-w-full text-sm">
    <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
        <tr>
            <th class="px-5 py-3">SKU Paket</th>
            <th class="px-5 py-3">Paket / Isi</th>
            <th class="px-5 py-3 text-right"><span class="whitespace-nowrap">Dipesan <x-hint text="Jumlah paket di pesanan Dikonfirmasi, Sedang Diambil dan Sudah Dikemas. Stok barang isinya sudah dicadangkan." /></span></th>
            <th class="px-5 py-3 text-right"><span class="whitespace-nowrap">Tersedia <x-hint text="Jumlah paket yang masih bisa dipenuhi dari stok tersedia barang isinya. Dibatasi oleh barang isi yang paling sedikit (pembatas)." /></span></th>
            @if ($showSellable)
                <th class="px-5 py-3 text-right">Stok <span class="whitespace-nowrap">Jual <x-hint text="Stok paket yang dikirim ke Shopee / TikTok, dihitung dari stok jual barang isinya termasuk jatah gudang cadangan." /></span></th>
            @endif
            <th class="px-5 py-3">Status</th>
            <th class="px-5 py-3 text-right">Aksi</th>
        </tr>
    </thead>
    <tbody class="divide-y divide-slate-100">
        @forelse ($stocks as $bundle)
            @php
                $available = (int) ($bundleLevels['available'][$bundle->id] ?? 0);
                $reserved = (int) ($bundleLevels['reserved'][$bundle->id] ?? 0);
                // Barang isi yang membatasi jumlah paket.
                $limits = $bundle->items->map(fn ($item) => intdiv(max(0, $bundleLevels['components'][$item->product_variant_id] ?? 0), max(1, $item->qty)));
                $lowest = $limits->min();
            @endphp
            <tr class="hover:bg-slate-50/60">
                <td class="px-5 py-3.5 align-top">
                    <p class="font-mono font-medium text-slate-900">{{ $bundle->sku }}</p>
                    <span class="mt-1 inline-flex items-center gap-1 rounded-full border border-violet-200 bg-violet-50 px-2 py-0.5 text-[11px] font-medium text-violet-700 cursor-help"
                          data-hint-title="Paket virtual" data-hint="Paket tidak punya stok fisik sendiri. Stoknya dihitung dari barang isinya, dan saat paket terjual yang berkurang adalah stok barang isi.">
                        <x-heroicon-o-sparkles class="size-3.5" /> Virtual
                    </span>
                </td>
                <td class="px-5 py-3.5 align-top">
                    <p class="font-medium text-slate-900">{{ $bundle->name }}</p>
                    <ul class="mt-1 space-y-0.5 text-xs text-slate-500">
                        @foreach ($bundle->items as $index => $item)
                            @php($componentAvailable = (int) ($bundleLevels['components'][$item->product_variant_id] ?? 0))
                            <li @class(['text-rose-600' => $limits[$index] === $lowest && $bundle->items->count() > 1])>
                                <a href="{{ route('inventory.stocks.index', array_filter(['search' => $item->variant?->sku, 'warehouse' => request('warehouse')])) }}" class="font-mono hover:underline" title="Lihat stok barang ini">{{ $item->variant?->sku }}</a> × {{ $item->qty }}
                                · tersedia {{ $fmt($componentAvailable) }} {{ $item->variant?->product?->unit?->code }}
                                @if ($limits[$index] === $lowest && $bundle->items->count() > 1)
                                    <span class="cursor-help underline decoration-dotted underline-offset-2" data-hint="Barang isi ini yang paling sedikit, sehingga membatasi jumlah paket yang bisa dijual. Tambah stok barang ini untuk menaikkan stok paket.">(pembatas)</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </td>
                <td class="px-5 py-3.5 text-right align-top tabular-nums">
                    @if ($reserved > 0)
                        <{{ $canViewOrders ? 'a' : 'span' }} @if ($canViewOrders) href="{{ route('sales.orders.index', array_filter(['search' => $bundle->sku, 'warehouse' => request('warehouse')])) }}" @endif
                           @class(['font-medium text-sky-700', 'hover:underline' => $canViewOrders])>{{ $fmt($reserved) }}</{{ $canViewOrders ? 'a' : 'span' }}>
                    @else
                        <span class="text-slate-400">0</span>
                    @endif
                </td>
                <td @class(['px-5 py-3.5 text-right align-top font-semibold tabular-nums', 'text-slate-900' => $available > 0, 'text-rose-600' => $available <= 0])>
                    {{ $fmt($available) }} <span class="text-xs font-normal text-slate-500">paket</span>
                </td>
                @if ($showSellable)
                    <td class="px-5 py-3.5 text-right align-top tabular-nums text-slate-700">{{ $fmt($bundleLevels['sellable'][$bundle->id] ?? 0) }}</td>
                @endif
                <td class="px-5 py-3.5 align-top">
                    @if (! $bundle->is_active)
                        <span class="inline-flex rounded-full border border-slate-200 px-2 py-0.5 text-[11px] text-slate-600">Nonaktif</span>
                    @elseif ($available > 0)
                        <span class="inline-flex rounded-full border border-emerald-200 px-2 py-0.5 text-[11px] text-emerald-700">Aman</span>
                    @else
                        <span class="inline-flex rounded-full border border-slate-200 px-2 py-0.5 text-[11px] text-slate-600">Habis</span>
                    @endif
                </td>
                <td class="px-5 py-3.5 align-top">
                    <div class="flex items-center justify-end gap-1">
                        @if ($canEditBundles)
                            <a href="{{ route('master.bundles.edit', $bundle) }}" class="btn-icon" title="Ubah isi paket">
                                <x-heroicon-o-pencil-square class="size-5" />
                            </a>
                        @endif
                    </div>
                </td>
            </tr>
        @empty
            <x-empty-state :colspan="$columns" icon="heroicon-o-gift" title="Belum ada produk paket" description="Buat paket di Master Data → Produk Paket, atau impor dari Excel." />
        @endforelse
    </tbody>
</table>
