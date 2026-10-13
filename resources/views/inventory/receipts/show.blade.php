@php
    $totals = [
        'ordered' => $receipt->items->sum(fn ($item) => $item->qty_ordered ?? 0),
        'received' => $receipt->items->sum('qty_received'),
        'damaged' => $receipt->items->sum('qty_damaged'),
    ];
    $hasOrdered = $receipt->items->contains(fn ($item) => $item->qty_ordered !== null);
@endphp

<x-layouts.app :title="$receipt->number">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('inventory.receipts.index') }}" class="hover:text-slate-700">Penerimaan Barang</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">{{ $receipt->number }}</span>
    </x-slot:breadcrumb>

    <x-page-header :title="$receipt->number" :description="'Penerimaan dari '.$receipt->supplier->name" icon="heroicon-o-inbox-arrow-down">
        <a href="{{ route('inventory.receipts.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
        @if ($receipt->isDraft())
            @can('receipts.delete')
                <form id="delete-receipt" method="POST" action="{{ route('inventory.receipts.destroy', $receipt) }}">@csrf @method('DELETE')</form>
                <button type="button" class="btn-secondary hover:text-rose-600"
                        @click="$dispatch('confirm', { form: 'delete-receipt', title: 'Hapus draft?', message: @js("Draft {$receipt->number} akan dihapus permanen."), confirmText: 'Hapus' })">
                    <x-heroicon-o-trash class="size-5" /> Hapus
                </button>
            @endcan
            @can('receipts.create')
                <a href="{{ route('inventory.receipts.edit', $receipt) }}" class="btn-secondary"><x-heroicon-o-pencil-square class="size-5" /> Edit</a>
            @endcan
            @can('receipts.receive')
                <form id="receive-receipt" method="POST" action="{{ route('inventory.receipts.receive', $receipt) }}">@csrf</form>
                <button type="button" class="btn-primary"
                        @click="$dispatch('confirm', { form: 'receive-receipt', title: 'Konfirmasi diterima?', message: @js("Stok {$totals['received']} barang kondisi baik akan masuk ke lokasi bin. Setelah ini dokumen tidak bisa diubah."), confirmText: 'Terima', variant: 'primary' })">
                    <x-heroicon-o-check class="size-5" /> Terima
                </button>
            @endcan
        @endif
    </x-page-header>


    <div class="card mb-6 grid gap-5 p-5 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <p class="text-xs text-slate-500">Status</p>
            <p class="mt-1">@include('inventory.receipts._status')</p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Tanggal Terima</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $receipt->receipt_date->translatedFormat('d F Y') }}</p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Supplier</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $receipt->supplier->name }}</p>
            <p class="font-mono text-xs text-slate-500">{{ $receipt->supplier->code }}</p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Gudang Tujuan</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $receipt->warehouse->code }} · {{ $receipt->warehouse->name }}</p>
        </div>
        <div>
            <p class="text-xs text-slate-500">No. PO / Surat Jalan</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $receipt->supplier_reference ?? '—' }}</p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Lampiran</p>
            @if ($receipt->attachment_path)
                <a href="{{ Storage::url($receipt->attachment_path) }}" target="_blank" class="mt-1 inline-flex items-center gap-1 text-sm text-brand-600 hover:underline">
                    <x-heroicon-o-paper-clip class="size-4" /> {{ $receipt->attachmentIsImage() ? 'Foto surat jalan' : 'Dokumen surat jalan' }}
                </a>
            @else
                <p class="mt-1 text-sm text-slate-400">—</p>
            @endif
        </div>
        <div>
            <p class="text-xs text-slate-500">Dibuat oleh</p>
            <p class="mt-1 text-sm text-slate-700">{{ $receipt->creator?->name ?? '—' }} · {{ $receipt->created_at->format('d/m/Y H:i') }}</p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Diterima oleh</p>
            @if ($receipt->received_at)
                <p class="mt-1 text-sm text-slate-700">{{ $receipt->receiver?->name ?? '—' }} · {{ $receipt->received_at->format('d/m/Y H:i') }}</p>
                @if ($receipt->movement)
                    <a href="{{ route('inventory.movements.show', $receipt->movement) }}" class="font-mono text-xs text-brand-600 hover:underline">{{ $receipt->movement->number }}</a>
                @endif
            @else
                <p class="mt-1 text-sm text-slate-400">Belum diterima</p>
            @endif
        </div>
        @if ($receipt->notes)
            <div class="sm:col-span-2 lg:col-span-4">
                <p class="text-xs text-slate-500">Catatan</p>
                <p class="mt-1 text-sm whitespace-pre-line text-slate-700">{{ $receipt->notes }}</p>
            </div>
        @endif
    </div>

    <div class="card">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">#</th>
                        <th class="h-8 px-4 py-0 font-normal">SKU</th>
                        <th class="h-8 px-4 py-0 font-normal">Produk / Varian</th>
                        @if ($hasOrdered)
                            <th class="h-8 px-4 py-0 text-right font-normal">Dipesan</th>
                        @endif
                        <th class="h-8 px-4 py-0 text-right font-normal">Diterima Baik</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Rusak</th>
                        @if ($hasOrdered)
                            <th class="h-8 px-4 py-0 text-right font-normal">Selisih</th>
                        @endif
                        <th class="h-8 px-4 py-0 font-normal">Lokasi Bin</th>
                        <th class="h-8 px-4 py-0 font-normal">Keterangan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($receipt->items as $item)
                        @php($variance = $item->variance())
                        <tr>
                            <td class="px-4 py-2.5 text-slate-400">{{ $loop->iteration }}</td>
                            <td class="px-4 py-2.5 font-mono font-medium text-slate-900">{{ $item->variant->sku }}</td>
                            <td class="px-4 py-2.5">
                                <p class="font-medium text-slate-900">{{ $item->variant->product->name }}</p>
                                @if ($item->variant->name)
                                    <p class="text-xs text-slate-500">{{ $item->variant->name }}</p>
                                @endif
                            </td>
                            @if ($hasOrdered)
                                <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ $item->qty_ordered === null ? '—' : number_format($item->qty_ordered, 0, ',', '.') }}</td>
                            @endif
                            <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-900">
                                {{ number_format($item->qty_received, 0, ',', '.') }}
                                <span class="text-xs font-normal text-slate-500">{{ $item->variant->product->unit->code }}</span>
                            </td>
                            <td @class(['px-4 py-2.5 text-right tabular-nums', 'text-rose-600' => $item->qty_damaged > 0, 'text-slate-400' => ! $item->qty_damaged])>
                                {{ number_format($item->qty_damaged, 0, ',', '.') }}
                            </td>
                            @if ($hasOrdered)
                                <td @class([
                                    'px-4 py-2.5 text-right font-medium tabular-nums',
                                    'text-slate-400' => $variance === null,
                                    'text-rose-600' => $variance !== null && $variance < 0,
                                    'text-amber-600' => $variance !== null && $variance > 0,
                                    'text-emerald-600' => $variance === 0,
                                ])>
                                    {{ $variance === null ? '—' : ($variance > 0 ? '+' : '').$variance }}
                                </td>
                            @endif
                            <td @class(['px-4 py-2.5', 'font-mono text-slate-700' => $item->location, 'text-slate-400' => ! $item->location])>{{ $item->location?->code ?? 'Tanpa bin' }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $item->notes ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="border-t border-slate-200 bg-slate-50 text-[13px]">
                    <tr>
                        <td colspan="3" class="px-4 py-2 text-right text-slate-500">Total</td>
                        @if ($hasOrdered)
                            <td class="px-4 py-2 text-right font-medium tabular-nums text-slate-700">{{ number_format($totals['ordered'], 0, ',', '.') }}</td>
                        @endif
                        <td class="px-4 py-2 text-right font-semibold tabular-nums text-slate-900">{{ number_format($totals['received'], 0, ',', '.') }}</td>
                        <td class="px-4 py-2 text-right font-medium tabular-nums text-slate-700">{{ number_format($totals['damaged'], 0, ',', '.') }}</td>
                        <td colspan="{{ $hasOrdered ? 3 : 2 }}"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <p class="mt-4 flex items-center gap-1.5 text-xs text-slate-500">
        @if ($receipt->isDraft())
            <x-heroicon-o-information-circle class="size-4" /> Stok belum bertambah. Klik Terima setelah barang dicek dan ditaruh di lokasi bin.
        @else
            <x-heroicon-o-lock-closed class="size-4" /> Penerimaan yang sudah diterima tidak bisa diubah. Untuk koreksi, catat penyesuaian stok.
        @endif
    </p>

    <x-activity.history :subject="$receipt" />
</x-layouts.app>
