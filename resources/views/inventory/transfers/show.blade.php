@php($fmt = fn ($n) => number_format((int) $n, 0, ',', '.'))
<x-layouts.app :title="$transfer->number">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('inventory.transfers.index') }}" class="hover:text-slate-700">Transfer Antar Gudang</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">{{ $transfer->number }}</span>
    </x-slot:breadcrumb>

    <x-page-header :title="$transfer->number" :description="$transfer->fromWarehouse->code.' → '.$transfer->toWarehouse->code" icon="heroicon-o-truck">
        <a href="{{ route('inventory.transfers.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
        @if ($transfer->isSent() && $canCancel)
            @can('inventory.create')
                <button type="button" class="btn-secondary text-rose-600" @click="$dispatch('cancel-transfer')"><x-heroicon-o-x-circle class="size-5" /> Batalkan</button>
            @endcan
        @endif
    </x-page-header>

    <div class="card mb-6 grid gap-5 p-6 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <p class="text-xs font-medium tracking-wider text-slate-500 uppercase">Status</p>
            <p class="mt-1"><x-inventory.transfer-status :transfer="$transfer" /></p>
        </div>
        <div>
            <p class="text-xs font-medium tracking-wider text-slate-500 uppercase">Dari</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $transfer->fromWarehouse->code }} · {{ $transfer->fromWarehouse->name }}</p>
        </div>
        <div>
            <p class="text-xs font-medium tracking-wider text-slate-500 uppercase">Ke</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $transfer->toWarehouse->code }} · {{ $transfer->toWarehouse->name }}</p>
        </div>
        <div>
            <p class="text-xs font-medium tracking-wider text-slate-500 uppercase">Tanggal Kirim</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $transfer->transfer_date->translatedFormat('d F Y') }}</p>
        </div>
        <div>
            <p class="text-xs font-medium tracking-wider text-slate-500 uppercase">Referensi</p>
            <p class="mt-1 text-sm text-slate-700">{{ $transfer->reference ?? '—' }}</p>
        </div>
        <div>
            <p class="text-xs font-medium tracking-wider text-slate-500 uppercase">Dikirim oleh</p>
            <p class="mt-1 text-sm text-slate-700">{{ $transfer->creator?->name ?? '—' }} · {{ $transfer->created_at->format('d/m/Y H:i') }}</p>
        </div>
        @if ($transfer->received_at)
            <div>
                <p class="text-xs font-medium tracking-wider text-slate-500 uppercase">Diterima oleh</p>
                <p class="mt-1 text-sm text-slate-700">{{ $transfer->receiver?->name ?? '—' }} · {{ $transfer->received_at->format('d/m/Y H:i') }}</p>
            </div>
        @endif
        @if ($transfer->cancelled_at)
            <div>
                <p class="text-xs font-medium tracking-wider text-slate-500 uppercase">Dibatalkan</p>
                <p class="mt-1 text-sm text-slate-700">{{ $transfer->cancelled_at->format('d/m/Y H:i') }}{{ $transfer->cancel_reason ? ' · '.$transfer->cancel_reason : '' }}</p>
            </div>
        @endif
        <div>
            <p class="text-xs font-medium tracking-wider text-slate-500 uppercase">Mutasi <span class="whitespace-nowrap">Stok <x-hint text="Dokumen pergerakan stok yang tercatat otomatis: kirim dari gudang asal, terima di gudang tujuan, atau pengembalian bila dibatalkan." /></span></p>
            <p class="mt-1 flex flex-wrap gap-2 text-sm">
                @foreach ([$transfer->outMovement, $transfer->inMovement, $transfer->returnMovement] as $movement)
                    @if ($movement)
                        <a href="{{ route('inventory.movements.show', $movement) }}" class="font-mono text-brand-600 hover:underline">{{ $movement->number }}</a>
                    @endif
                @endforeach
            </p>
        </div>
        @if ($transfer->notes)
            <div class="sm:col-span-2 lg:col-span-4">
                <p class="text-xs font-medium tracking-wider text-slate-500 uppercase">Catatan</p>
                <p class="mt-1 text-sm whitespace-pre-line text-slate-700">{{ $transfer->notes }}</p>
            </div>
        @endif
    </div>

    @php($receiving = $transfer->isSent() && $canReceive && auth()->user()->can('inventory.create'))

    <form method="POST" action="{{ route('inventory.transfers.receive', $transfer) }}" class="card">
        @csrf
        <div class="border-b border-slate-100 px-5 py-3">
            <h2 class="text-base font-semibold text-slate-900">Barang</h2>
            @if ($receiving)
                <p class="mt-0.5 text-sm text-slate-500">Cocokkan jumlah yang benar-benar sampai dan pilih bin tujuan, lalu klik Terima. Kekurangan tercatat sebagai barang hilang di laporan Barang Hilang.</p>
            @endif
        </div>
        <x-form-errors class="mx-5 mt-4" />
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="px-5 py-3">SKU</th>
                        <th class="px-5 py-3">Produk / Varian</th>
                        <th class="px-5 py-3">Lokasi Asal</th>
                        <th class="px-5 py-3">Lokasi <span class="whitespace-nowrap">Tujuan <x-hint text="Bin di gudang tujuan tempat barang ditaruh saat diterima." /></span></th>
                        <th class="px-5 py-3 text-right"><span class="whitespace-nowrap">Dikirim <x-hint text="Jumlah yang keluar dari gudang asal." /></span></th>
                        <th class="px-5 py-3 text-right"><span class="whitespace-nowrap">Diterima <x-hint text="Jumlah yang benar-benar diterima. Bila kurang dari yang dikirim, kekurangannya dicatat di laporan Barang Hilang." /></span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($transfer->items as $item)
                        <tr class="align-top">
                            <td class="px-5 py-3 font-mono font-medium text-slate-900">{{ $item->variant->sku }}</td>
                            <td class="px-5 py-3">
                                <p class="font-medium text-slate-900">{{ $item->variant->product->name }}</p>
                                <p class="text-xs text-slate-500">{{ $item->variant->name }}{{ $item->notes ? ' · '.$item->notes : '' }}</p>
                            </td>
                            <td class="px-5 py-3 font-mono text-slate-700">{{ $item->fromLocation?->code ?? 'Tanpa bin' }}</td>
                            <td class="px-5 py-3 font-mono text-slate-700">
                                @if ($receiving)
                                    <select name="items[{{ $item->id }}][to_location_id]" class="form-input min-w-40">
                                        <option value="">Tanpa bin</option>
                                        @foreach ($locations as $location)
                                            <option value="{{ $location->id }}" @selected(old("items.{$item->id}.to_location_id", $item->to_location_id) == $location->id)>{{ $location->code }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    {{ $item->toLocation?->code ?? 'Tanpa bin' }}
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right tabular-nums text-slate-900">
                                {{ $fmt($item->qty) }} <span class="text-xs text-slate-500">{{ $item->variant->product->unit->code }}</span>
                                @if ($label = $item->variant->cartonLabel($item->qty))
                                    <p class="text-xs text-slate-500">{{ $label }}</p>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right tabular-nums">
                                @if ($receiving)
                                    <input type="number" min="0" max="{{ $item->qty }}" name="items[{{ $item->id }}][received_qty]" value="{{ old("items.{$item->id}.received_qty", $item->qty) }}" class="form-input ml-auto w-28 text-right">
                                @elseif ($item->received_qty !== null)
                                    <span @class(['font-medium', 'text-rose-600' => $item->received_qty < $item->qty, 'text-slate-900' => $item->received_qty >= $item->qty])>{{ $fmt($item->received_qty) }}</span>
                                    @if ($item->received_qty < $item->qty)
                                        <p class="text-xs text-rose-600">kurang {{ $fmt($item->qty - $item->received_qty) }}</p>
                                        @can('reports.view')
                                            <a href="{{ route('reports.lost-items', ['search' => $transfer->number, 'from' => $transfer->transfer_date->toDateString()]) }}" class="text-xs text-brand-600 hover:text-brand-700">tercatat hilang</a>
                                        @else
                                            <p class="text-xs text-slate-500">tercatat hilang</p>
                                        @endcan
                                    @endif
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($receiving)
            <div class="flex justify-end border-t border-slate-100 px-5 py-4">
                <button type="submit" class="btn-primary"><x-heroicon-o-inbox-arrow-down class="size-5" /> Terima di {{ $transfer->toWarehouse->code }}</button>
            </div>
        @endif
    </form>

    @if ($transfer->isSent() && $canCancel)
        @can('inventory.create')
            <div x-data="{ open: false }" x-cloak @cancel-transfer.window="open = true; $nextTick(() => $refs.reason.focus())" @keydown.escape.window="open = false">
                <div x-show="open" x-transition.opacity class="fixed inset-0 z-[70] bg-black/40"></div>
                <div x-show="open" class="fixed inset-0 z-[80] flex items-center justify-center p-4">
                    <form method="POST" action="{{ route('inventory.transfers.cancel', $transfer) }}" x-show="open" x-transition @click.outside="open = false" class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
                        @csrf
                        <div class="flex gap-4">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-rose-100 text-rose-600"><x-heroicon-o-exclamation-triangle class="size-5" /></span>
                            <div class="flex-1">
                                <h3 class="text-base font-semibold text-slate-900">Batalkan {{ $transfer->number }}?</h3>
                                <p class="mt-1.5 text-sm text-slate-600">Stok dikembalikan ke lokasi asal di {{ $transfer->fromWarehouse->code }}.</p>
                                <label for="cancel_reason" class="form-label mt-3">Alasan (opsional)</label>
                                <input id="cancel_reason" name="cancel_reason" x-ref="reason" maxlength="255" class="form-input" placeholder="cth. Truk batal berangkat">
                            </div>
                        </div>
                        <div class="mt-5 flex justify-end gap-2">
                            <button type="button" class="btn-secondary" @click="open = false">Tidak</button>
                            <button type="submit" class="btn-danger">Batalkan Transfer</button>
                        </div>
                    </form>
                </div>
            </div>
        @endcan
    @endif

    <x-activity.history :subject="$transfer" />
</x-layouts.app>
