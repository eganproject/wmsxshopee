@php
    $totals = ['good' => $return->items->sum('qty_good'), 'damaged' => $return->items->sum('qty_damaged'), 'missing' => $return->items->sum('qty_missing')];
    $confirmMessage = "{$totals['good']} barang layak jual akan masuk stok, {$totals['damaged']} barang rusak"
        .($totals['missing'] ? " dan {$totals['missing']} barang hilang" : '')
        .' dicatat tanpa menambah stok. Setelah ini dokumen tidak bisa diubah.';
@endphp

<x-layouts.app :title="$return->number">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('sales.returns.index') }}" class="hover:text-slate-700">Retur Penjualan</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">{{ $return->number }}</span>
    </x-slot:breadcrumb>

    <x-page-header :title="$return->number" :description="'Retur dari '.$return->sender_name.($return->order ? ' · '.$return->order->number : ' · tanpa pesanan')" icon="heroicon-o-arrow-uturn-left">
        <a href="{{ route('sales.returns.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
        @if ($return->isDraft())
            @can('returns.delete')
                <form id="delete-return" method="POST" action="{{ route('sales.returns.destroy', $return) }}">@csrf @method('DELETE')</form>
                <button type="button" class="btn-secondary hover:text-rose-600"
                        @click="$dispatch('confirm', { form: 'delete-return', title: 'Hapus draft?', message: @js("Draft {$return->number} akan dihapus permanen."), confirmText: 'Hapus' })">
                    <x-heroicon-o-trash class="size-5" /> Hapus
                </button>
            @endcan
            @can('returns.create')
                <a href="{{ route('sales.returns.edit', $return) }}" class="btn-secondary"><x-heroicon-o-pencil-square class="size-5" /> Edit</a>
            @endcan
            @can('returns.receive')
                <form id="receive-return" method="POST" action="{{ route('sales.returns.receive', $return) }}">@csrf</form>
                <button type="button" class="btn-primary"
                        @click="$dispatch('confirm', { form: 'receive-return', title: 'Terima retur?', message: @js($confirmMessage), confirmText: 'Terima', variant: 'primary' })">
                    <x-heroicon-o-check class="size-5" /> Terima
                </button>
            @endcan
        @endif
    </x-page-header>


    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="card p-4">
            <p class="text-xs text-slate-500">Layak Jual (masuk stok)</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-emerald-600">{{ number_format($totals['good'], 0, ',', '.') }}</p>
        </div>
        <div class="card p-4">
            <p class="text-xs text-slate-500">Rusak (dicatat terpisah)</p>
            <p @class(['mt-1 text-2xl font-semibold tabular-nums', 'text-rose-600' => $totals['damaged'] > 0, 'text-slate-400' => ! $totals['damaged']])>{{ number_format($totals['damaged'], 0, ',', '.') }}</p>
        </div>
        <div class="card p-4">
            <p class="text-xs text-slate-500">Hilang (tidak ada di paket)</p>
            <p @class(['mt-1 text-2xl font-semibold tabular-nums', 'text-amber-600' => $totals['missing'] > 0, 'text-slate-400' => ! $totals['missing']])>{{ number_format($totals['missing'], 0, ',', '.') }}</p>
        </div>
        <div class="card p-4">
            <p class="text-xs text-slate-500">Mutasi Stok</p>
            @if ($return->movement)
                <a href="{{ route('inventory.movements.show', $return->movement) }}" class="mt-1 inline-block font-mono text-lg font-semibold text-brand-600 hover:underline">{{ $return->movement->number }}</a>
            @else
                <p class="mt-1 text-sm text-slate-400">{{ $return->isDraft() ? 'Dibuat saat retur diterima' : 'Tidak ada (tidak ada yang layak jual)' }}</p>
            @endif
        </div>
    </div>

    <div class="card mb-6 grid gap-5 p-5 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <p class="text-xs text-slate-500">Status</p>
            <p class="mt-1">@include('sales.returns._status')</p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Tanggal Retur</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $return->return_date->translatedFormat('d F Y') }}</p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Pesanan Asal</p>
            @if ($return->order)
                <a href="{{ route('sales.orders.show', $return->order) }}" class="mt-1 inline-block font-mono text-sm font-medium text-brand-600 hover:underline">{{ $return->order->number }}</a>
                @if ($return->external_number)
                    <p class="text-xs text-slate-500">{{ $return->channel_name }} #{{ $return->external_number }}</p>
                @endif
            @else
                <p class="mt-1 text-sm font-medium text-amber-700">Tanpa pesanan</p>
                @if ($return->channel_name || $return->external_number)
                    <p class="text-xs text-slate-500">{{ collect([$return->channel_name, $return->external_number])->filter()->join(' · ') }}</p>
                @endif
            @endif
        </div>
        <div>
            <p class="text-xs text-slate-500">Pengirim</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $return->sender_name }}</p>
            @if ($return->sender_phone)
                <p class="text-xs text-slate-500">{{ $return->sender_phone }}</p>
            @endif
        </div>
        <div>
            <p class="text-xs text-slate-500">No. Resi Retur</p>
            <p class="mt-1 font-mono text-sm font-medium text-slate-900">{{ $return->tracking_number ?? '—' }}</p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Gudang Penerima</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $return->warehouse->code }} · {{ $return->warehouse->name }}</p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Dibuat oleh</p>
            <p class="mt-1 text-sm text-slate-700">{{ $return->creator?->name ?? '—' }} · {{ $return->created_at->format('d/m/Y H:i') }}</p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Diterima oleh</p>
            @if ($return->received_at)
                <p class="mt-1 text-sm text-slate-700">{{ $return->receiver?->name ?? '—' }} · {{ $return->received_at->format('d/m/Y H:i') }}</p>
            @else
                <p class="mt-1 text-sm text-slate-400">Belum diterima</p>
            @endif
        </div>
        @if ($return->notes)
            <div class="sm:col-span-2 lg:col-span-4">
                <p class="text-xs text-slate-500">Catatan</p>
                <p class="mt-1 text-sm whitespace-pre-line text-slate-700">{{ $return->notes }}</p>
            </div>
        @endif
    </div>

    <div class="card">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">#</th>
                        <th class="h-8 px-4 py-0 font-normal">Foto</th>
                        <th class="h-8 px-4 py-0 font-normal">SKU</th>
                        <th class="h-8 px-4 py-0 font-normal">Produk / Varian</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Layak Jual</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Rusak</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Hilang</th>
                        <th class="h-8 px-4 py-0 font-normal">Lokasi Bin</th>
                        <th class="h-8 px-4 py-0 font-normal">Alasan</th>
                        <th class="h-8 px-4 py-0 font-normal">Keterangan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($return->items as $item)
                        <tr>
                            <td class="px-4 py-2.5 text-slate-400">{{ $loop->iteration }}</td>
                            <td class="px-4 py-2.5">
                                @if ($item->photo_path)
                                    <a href="{{ Storage::url($item->photo_path) }}" target="_blank" class="block size-10 overflow-hidden rounded-md border border-slate-200">
                                        <img src="{{ Storage::url($item->photo_path) }}" alt="Foto {{ $item->variant->sku }}" class="size-full object-cover">
                                    </a>
                                @else
                                    <span class="flex size-10 items-center justify-center rounded-md border border-dashed border-slate-200 text-slate-300"><x-heroicon-o-photo class="size-5" /></span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 font-mono font-medium text-slate-900">{{ $item->variant->sku }}</td>
                            <td class="px-4 py-2.5">
                                <p class="font-medium text-slate-900">{{ $item->variant->product->name }}</p>
                                @if ($item->variant->name)
                                    <p class="text-xs text-slate-500">{{ $item->variant->name }}</p>
                                @endif
                                @if ($item->orderItem?->bundleLine)
                                    <p class="text-xs text-violet-600">Isi paket {{ $item->orderItem->bundleLine->bundle->sku }}</p>
                                @endif
                            </td>
                            <td @class(['px-4 py-2.5 text-right tabular-nums', 'font-semibold text-slate-900' => $item->qty_good > 0, 'text-slate-400' => ! $item->qty_good])>
                                {{ number_format($item->qty_good, 0, ',', '.') }}
                                <span class="text-xs font-normal text-slate-500">{{ $item->variant->product->unit->code }}</span>
                            </td>
                            <td @class(['px-4 py-2.5 text-right tabular-nums', 'font-semibold text-rose-600' => $item->qty_damaged > 0, 'text-slate-400' => ! $item->qty_damaged])>
                                {{ number_format($item->qty_damaged, 0, ',', '.') }}
                            </td>
                            <td @class(['px-4 py-2.5 text-right tabular-nums', 'font-semibold text-amber-600' => $item->qty_missing > 0, 'text-slate-400' => ! $item->qty_missing])>
                                {{ number_format($item->qty_missing, 0, ',', '.') }}
                            </td>
                            <td @class(['px-4 py-2.5', 'font-mono text-slate-700' => $item->location, 'text-slate-400' => ! $item->location])>
                                {{ $item->qty_good ? ($item->location?->code ?? 'Tanpa bin') : '—' }}
                            </td>
                            <td class="px-4 py-2.5 text-slate-700">{{ $item->reasonLabel() }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $item->notes ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="border-t border-slate-200 bg-slate-50 text-[13px]">
                    <tr>
                        <td colspan="4" class="px-4 py-2 text-right text-slate-500">Total</td>
                        <td class="px-4 py-2 text-right font-semibold tabular-nums text-slate-900">{{ number_format($totals['good'], 0, ',', '.') }}</td>
                        <td class="px-4 py-2 text-right font-semibold tabular-nums text-rose-600">{{ number_format($totals['damaged'], 0, ',', '.') }}</td>
                        <td class="px-4 py-2 text-right font-semibold tabular-nums text-amber-600">{{ number_format($totals['missing'], 0, ',', '.') }}</td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <p class="mt-4 flex items-center gap-1.5 text-xs text-slate-500">
        @if ($return->isDraft())
            <x-heroicon-o-information-circle class="size-4" /> Stok belum bertambah. Klik Terima setelah barang dicek dan barang layak jual ditaruh di lokasi bin.
        @else
            <x-heroicon-o-lock-closed class="size-4" /> Retur yang sudah diterima tidak bisa diubah. Untuk koreksi, catat penyesuaian stok.
        @endif
    </p>

    <x-activity.history :subject="$return" />
</x-layouts.app>
