@php
    $rows = $order->items->mapWithKeys(fn ($item) => [$item->id => collect(old("picks.{$item->id}", $suggestion[$item->id] ?? []))
        ->map(fn ($row) => ['warehouse_location_id' => (string) ($row['warehouse_location_id'] ?? ''), 'qty' => $row['qty'] ?? ''])
        ->values()]);
    $options = $order->items->mapWithKeys(fn ($item) => [$item->id => $slots->get($item->product_variant_id, collect())
        ->filter(fn ($slot) => $slot['location_id'])
        ->map(fn ($slot) => ['value' => $slot['location_id'], 'text' => "{$slot['code']} (bebas {$slot['qty']})"])
        ->values()]);
    $unbinned = $order->items->mapWithKeys(fn ($item) => [$item->id => (int) ($slots->get($item->product_variant_id, collect())->firstWhere('location_id', null)['qty'] ?? 0)]);
@endphp

<x-layouts.app :title="'Picking '.$order->number">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('sales.orders.index') }}" class="hover:text-slate-700">Pesanan</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('sales.orders.show', $order) }}" class="hover:text-slate-700">{{ $order->number }}</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Picking</span>
    </x-slot:breadcrumb>

    <x-page-header :title="'Picking '.$order->number" :description="'Catat dari bin mana barang diambil di gudang '.$order->warehouse->code.'. Bin sudah diisi otomatis, ubah bila perlu.'" icon="heroicon-o-hand-raised">
        <a href="{{ route('sales.orders.pick-list', $order) }}" target="_blank" class="btn-secondary"><x-heroicon-o-printer class="size-5" /> Picking List</a>
        <a href="{{ route('sales.orders.show', $order) }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    @if ($shortage->isNotEmpty())
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <p class="flex items-center gap-1.5 font-medium"><x-heroicon-o-truck class="size-4" /> Menunggu stok dari gudang cadangan</p>
            <p class="mt-1">Pesanan diterima memakai stok gudang cadangan. Picking bisa dilakukan setelah transfer antar gudang diterima di {{ $order->warehouse->code }}:
                {{ $shortage->map(fn ($row) => $row['sku'].' kurang '.$row['short'])->join(', ') }}.</p>
            @can('inventory.view')
                <a href="{{ route('inventory.transfers.index') }}" class="mt-1.5 inline-flex items-center gap-1 font-medium text-amber-900 underline">Lihat transfer & saran isi ulang</a>
            @endcan
        </div>
    @endif


    <form method="POST" action="{{ route('sales.orders.pick', $order) }}"
          x-data="{
              picks: @js($rows),
              options: @js($options),
              total(id) { return this.picks[id].reduce((sum, row) => sum + (parseInt(row.qty) || 0), 0); },
          }">
        @csrf
        <div class="card divide-y divide-slate-100">
            @foreach ($order->items as $item)
                <div class="grid gap-4 p-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
                    <div>
                        <p class="font-mono text-sm font-medium text-slate-900">{{ $item->variant->sku }}</p>
                        <p class="text-sm text-slate-700">{{ $item->variant->product->name }}{{ $item->variant->name ? ' / '.$item->variant->name : '' }}</p>
                        @if ($item->bundleLine)
                            <p class="text-xs text-violet-600">Isi paket {{ $item->bundleLine->bundle->sku }}</p>
                        @endif
                        <p class="mt-1 text-sm">
                            Diambil <span class="font-semibold tabular-nums" :class="total({{ $item->id }}) === {{ $item->qty }} ? 'text-emerald-600' : 'text-rose-600'" x-text="total({{ $item->id }})"></span>
                            dari <span class="font-semibold tabular-nums text-slate-900">{{ $item->qty }}</span> {{ $item->variant->product->unit->code }}
                        </p>
                        @if ($unbinned[$item->id] > 0)
                            <p class="mt-1 text-xs text-slate-500">Stok tanpa bin bebas: {{ $unbinned[$item->id] }} <x-hint text="Stok yang tercatat di gudang tapi belum ditaruh di bin, dan belum diambil untuk pesanan lain. Bisa dipakai dengan memilih Tanpa bin." /></p>
                        @endif
                        @error("picks.{$item->id}")
                            <p class="mt-1 flex items-center gap-1 text-xs text-rose-600"><x-heroicon-o-exclamation-triangle class="size-4 shrink-0" /> {{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <template x-for="(row, index) in picks[{{ $item->id }}]" :key="index">
                            <div class="mb-2 flex items-start gap-2">
                                <select class="form-input flex-1" :name="`picks[{{ $item->id }}][${index}][warehouse_location_id]`" x-model="row.warehouse_location_id"
                                        x-tom-select="{ options: options[{{ $item->id }}], value: row.warehouse_location_id }" data-placeholder="Tanpa bin">
                                    <option value="">Tanpa bin</option>
                                </select>
                                <input type="number" min="0" step="1" class="form-input w-24 text-right" :name="`picks[{{ $item->id }}][${index}][qty]`" x-model="row.qty">
                                <button type="button" class="btn-icon hover:bg-rose-50 hover:text-rose-600" title="Hapus baris"
                                        x-show="picks[{{ $item->id }}].length > 1" @click="picks[{{ $item->id }}].splice(index, 1)">
                                    <x-heroicon-o-trash class="size-5" />
                                </button>
                            </div>
                        </template>
                        <button type="button" class="text-sm text-brand-600 hover:underline"
                                @click="picks[{{ $item->id }}].push({ warehouse_location_id: '', qty: Math.max({{ $item->qty }} - total({{ $item->id }}), 0) || '' })">
                            + Ambil dari bin lain
                        </button>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="sticky bottom-0 mt-6 flex items-center justify-between gap-3 border-t border-slate-200 bg-white/90 py-3 backdrop-blur">
            <p class="hidden items-center gap-1.5 text-xs text-slate-500 sm:flex">
                <x-heroicon-o-information-circle class="size-4" /> Stok belum keluar di tahap ini; bin yang dipilih dipakai saat pesanan dikirim.
            </p>
            <div class="flex gap-2">
                <a href="{{ route('sales.orders.show', $order) }}" class="btn-secondary">Batal</a>
                <button type="submit" class="btn-primary"><x-heroicon-o-check class="size-5" /> Selesai Picking</button>
            </div>
        </div>
    </form>
</x-layouts.app>
