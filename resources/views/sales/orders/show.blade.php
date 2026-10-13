@php
    $money = fn ($value) => 'Rp '.number_format((float) $value, 0, ',', '.');
    $subtotal = $order->subtotal();
    $steps = ['draft' => 'Draft', 'confirmed' => 'Dikonfirmasi', 'picked' => 'Diambil', 'packed' => 'Dikemas', 'shipped' => 'Dikirim'];
    $stepKeys = array_keys($steps);
    $current = array_search($order->status, $stepKeys, true);
    $showPicks = $order->hasStatus('picked', 'packed', 'shipped') || ($order->hasStatus('cancelled') && $order->items->contains(fn ($item) => $item->picks->isNotEmpty()));
@endphp

<x-layouts.app :title="$order->number">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('sales.orders.index') }}" class="hover:text-slate-700">Pesanan</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">{{ $order->number }}</span>
    </x-slot:breadcrumb>

    <x-page-header :title="$order->number" :description="'Pesanan '.$order->customer_name.($order->channel_name ? ' · '.$order->channel_name : '')" icon="heroicon-o-shopping-cart">
        <a href="{{ route('sales.orders.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>

        @if ($order->isCancellable())
            @can('sales.cancel')
                <button type="button" class="btn-secondary hover:text-rose-600" @click="$dispatch('cancel-order')">
                    <x-heroicon-o-x-circle class="size-5" /> Batalkan
                </button>
            @endcan
        @endif

        @if ($order->hasStatus('draft'))
            @can('sales.delete')
                <form id="delete-order" method="POST" action="{{ route('sales.orders.destroy', $order) }}">@csrf @method('DELETE')</form>
                <button type="button" class="btn-secondary hover:text-rose-600"
                        @click="$dispatch('confirm', { form: 'delete-order', title: 'Hapus draft?', message: @js("Draft {$order->number} akan dihapus permanen."), confirmText: 'Hapus' })">
                    <x-heroicon-o-trash class="size-5" /> Hapus
                </button>
            @endcan
            @can('sales.create')
                <a href="{{ route('sales.orders.edit', $order) }}" class="btn-secondary"><x-heroicon-o-pencil-square class="size-5" /> Edit</a>
            @endcan
            @can('sales.confirm')
                <form id="confirm-order" method="POST" action="{{ route('sales.orders.confirm', $order) }}">@csrf</form>
                <button type="button" class="btn-primary"
                        @click="$dispatch('confirm', { form: 'confirm-order', title: 'Konfirmasi pesanan?', message: 'Stok di gudang akan dicadangkan untuk pesanan ini dan dokumen tidak bisa diubah lagi.', confirmText: 'Konfirmasi', variant: 'primary' })">
                    <x-heroicon-o-check class="size-5" /> Konfirmasi
                </button>
            @endcan
        @endif

        @if ($order->hasStatus(...\App\Models\SalesOrder::RESERVING))
            @can('sales.confirm')
                <form id="reopen-order" method="POST" action="{{ route('sales.orders.reopen', $order) }}">@csrf</form>
                <button type="button" class="btn-secondary"
                        @click="$dispatch('confirm', { form: 'reopen-order', title: 'Kembalikan ke draft?', message: 'Cadangan stok dan hasil picking dilepas supaya pesanan bisa diubah. Konfirmasi ulang setelah selesai.', confirmText: 'Kembalikan', variant: 'primary' })">
                    <x-heroicon-o-arrow-uturn-left class="size-5" /> Ke Draft
                </button>
            @endcan
            <a href="{{ route('sales.orders.pick-list', $order) }}" target="_blank" class="btn-secondary"><x-heroicon-o-printer class="size-5" /> Picking List</a>
        @endif

        @can('sales.fulfill')
            @if ($order->hasStatus('confirmed'))
                <a href="{{ route('sales.orders.picking', $order) }}" class="btn-primary"><x-heroicon-o-hand-raised class="size-5" /> Picking</a>
            @elseif ($order->hasStatus('picked'))
                <form id="pack-order" method="POST" action="{{ route('sales.orders.pack', $order) }}">@csrf</form>
                <button type="button" class="btn-primary"
                        @click="$dispatch('confirm', { form: 'pack-order', title: 'Tandai sudah dikemas?', message: 'Pastikan semua barang sudah dicek dan dikemas.', confirmText: 'Sudah Dikemas', variant: 'primary' })">
                    <x-heroicon-o-archive-box class="size-5" /> Kemas
                </button>
            @endif
        @endcan

        @if ($order->hasStatus('shipped'))
            @can('returns.create')
                <a href="{{ route('sales.returns.create', ['order' => $order->id]) }}" class="btn-secondary"><x-heroicon-o-arrow-uturn-left class="size-5" /> Buat Retur</a>
            @endcan
        @endif
    </x-page-header>

    @if ($errors->any())
        <div class="mb-6 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
            @foreach ($errors->all() as $message)
                <p class="flex items-center gap-1.5"><x-heroicon-o-exclamation-triangle class="size-4 shrink-0" /> {{ $message }}</p>
            @endforeach
        </div>
    @endif

    @if ($order->hasStatus('cancelled'))
        <div class="mb-6 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
            <p class="flex items-center gap-1.5 font-medium"><x-heroicon-o-x-circle class="size-4" /> Dibatalkan {{ $order->cancelled_at?->format('d/m/Y H:i') }}</p>
            @if ($order->cancel_reason)
                <p class="mt-1">Alasan: {{ $order->cancel_reason }}</p>
            @endif
        </div>
    @else
        <ol class="card mb-6 flex items-center gap-2 overflow-x-auto p-4 text-sm">
            @foreach ($steps as $key => $label)
                @php($index = $loop->index)
                <li class="flex shrink-0 items-center gap-2">
                    <span @class([
                        'flex size-6 items-center justify-center rounded-full text-xs font-semibold',
                        'bg-emerald-600 text-white' => $index < $current || $order->hasStatus('shipped'),
                        'bg-brand-500 text-white' => $index === $current && ! $order->hasStatus('shipped'),
                        'bg-slate-100 text-slate-500' => $index > $current,
                    ])>
                        @if ($index < $current || $order->hasStatus('shipped'))
                            <x-heroicon-o-check class="size-3.5" />
                        @else
                            {{ $index + 1 }}
                        @endif
                    </span>
                    <span @class(['font-medium text-slate-900' => $index <= $current, 'text-slate-500' => $index > $current])>{{ $label }}</span>
                    @unless ($loop->last)
                        <span class="mx-1 h-px w-8 bg-slate-200 sm:w-14"></span>
                    @endunless
                </li>
            @endforeach
        </ol>
    @endif

    <div class="card mb-6 grid gap-5 p-5 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <p class="text-xs text-slate-500">Status</p>
            <p class="mt-1"><x-sales.status-badge :status="$order->status" /></p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Tanggal Pesanan</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $order->order_date->translatedFormat('d F Y') }}</p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Saluran / Toko</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $order->channel_name ?? '—' }}</p>
            @if ($order->external_number)
                <p class="font-mono text-xs text-slate-500">{{ $order->external_number }}</p>
            @endif
        </div>
        <div>
            <p class="text-xs text-slate-500">Gudang Pengirim</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $order->warehouse->code }} · {{ $order->warehouse->name }}</p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Pembeli</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $order->customer_name }}</p>
            @if ($order->customer_phone)
                <p class="text-xs text-slate-500">{{ $order->customer_phone }}</p>
            @endif
        </div>
        <div class="sm:col-span-1 lg:col-span-2">
            <p class="text-xs text-slate-500">Alamat Kirim</p>
            <p class="mt-1 text-sm whitespace-pre-line text-slate-700">{{ $order->shipping_address ?? '—' }}</p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Kurir / Resi</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $order->courier?->name ?? '—' }}{{ $order->courier_service ? ' · '.$order->courier_service : '' }}</p>
            @if ($order->tracking_number)
                @if ($url = $order->trackingUrl())
                    <a href="{{ $url }}" target="_blank" class="font-mono text-xs text-brand-600 hover:underline">{{ $order->tracking_number }}</a>
                @else
                    <p class="font-mono text-xs text-slate-500">{{ $order->tracking_number }}</p>
                @endif
            @endif
        </div>
        <div>
            <p class="text-xs text-slate-500">Dibuat oleh</p>
            <p class="mt-1 text-sm text-slate-700">{{ $order->creator?->name ?? '—' }} · {{ $order->created_at->format('d/m/Y H:i') }}</p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Riwayat</p>
            <div class="mt-1 space-y-0.5 text-xs text-slate-600">
                @foreach (['confirmed_at' => 'Dikonfirmasi', 'picked_at' => 'Diambil', 'packed_at' => 'Dikemas', 'shipped_at' => 'Dikirim'] as $field => $label)
                    @if ($order->{$field})
                        <p>{{ $label }} · {{ $order->{$field}->format('d/m/Y H:i') }}</p>
                    @endif
                @endforeach
                @if (! $order->confirmed_at)
                    <p class="text-slate-400">Belum diproses</p>
                @endif
            </div>
        </div>
        <div>
            <p class="text-xs text-slate-500">Mutasi Stok</p>
            @if ($order->movement)
                <a href="{{ route('inventory.movements.show', $order->movement) }}" class="mt-1 inline-block font-mono text-sm text-brand-600 hover:underline">{{ $order->movement->number }}</a>
            @else
                <p class="mt-1 text-sm text-slate-400">Stok keluar saat dikirim</p>
            @endif
        </div>
        @if ($order->notes)
            <div class="sm:col-span-2 lg:col-span-4">
                <p class="text-xs text-slate-500">Catatan</p>
                <p class="mt-1 text-sm whitespace-pre-line text-slate-700">{{ $order->notes }}</p>
            </div>
        @endif
    </div>

    @if ($order->hasStatus('packed'))
        @can('sales.fulfill')
            <form method="POST" action="{{ route('sales.orders.ship', $order) }}" class="card mb-6">
                @csrf
                <div class="border-b border-slate-100 px-5 py-3">
                    <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900"><x-heroicon-o-truck class="size-5 text-slate-400" /> Kirim Pesanan</h2>
                    <p class="mt-0.5 text-sm text-slate-500">Isi kurir dan nomor resi. Saat dikirim, stok keluar dari bin hasil picking.</p>
                </div>
                <div class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-4">
                    <x-select name="courier_id" label="Kurir" required>
                        <option value="">Pilih kurir</option>
                        @foreach ($couriers as $courier)
                            <option value="{{ $courier->id }}" @selected((string) old('courier_id', $order->courier_id) === (string) $courier->id)>{{ $courier->name }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="courier_service" label="Layanan" :value="$order->courier_service" placeholder="cth. REG" maxlength="50" />
                    <x-input name="tracking_number" label="Nomor Resi" :value="$order->tracking_number" required maxlength="100" icon="heroicon-o-qr-code" placeholder="Scan atau ketik resi" />
                    <div class="flex items-end">
                        <button type="submit" class="btn-primary w-full justify-center"><x-heroicon-o-paper-airplane class="size-5" /> Kirim</button>
                    </div>
                </div>
            </form>
        @endcan
    @endif

    <div class="card">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">#</th>
                        <th class="h-8 px-4 py-0 font-normal">SKU</th>
                        <th class="h-8 px-4 py-0 font-normal">Produk / Varian</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Jumlah</th>
                        @if ($order->hasStatus('draft'))
                            <th class="h-8 px-4 py-0 text-right font-normal">Stok Tersedia</th>
                        @endif
                        @if ($showPicks)
                            <th class="h-8 px-4 py-0 font-normal">Diambil dari</th>
                        @endif
                        <th class="h-8 px-4 py-0 text-right font-normal">Harga</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Subtotal</th>
                        <th class="h-8 px-4 py-0 font-normal">Keterangan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @php($line = 0)
                    @foreach ($order->items->whereNull('sales_order_bundle_id') as $item)
                        @include('sales.orders._item-row', ['item' => $item, 'number' => ++$line])
                    @endforeach
                    @foreach ($order->bundles as $bundleLine)
                        <tr class="bg-slate-50/60">
                            <td class="px-4 py-2.5 text-slate-400">{{ ++$line }}</td>
                            <td class="px-4 py-2.5 font-mono font-medium text-slate-900">{{ $bundleLine->bundle->sku }}</td>
                            <td class="px-4 py-2.5">
                                <p class="flex items-center gap-1.5 font-medium text-slate-900">
                                    {{ $bundleLine->bundle->name }}
                                    <span class="inline-flex items-center gap-1 rounded-full bg-violet-50 px-2 py-0.5 text-[11px] font-medium text-violet-700"><x-heroicon-o-gift class="size-3" /> Paket</span>
                                </p>
                            </td>
                            <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-900">
                                {{ number_format($bundleLine->qty, 0, ',', '.') }}
                                <span class="text-xs font-normal text-slate-500">paket</span>
                            </td>
                            @if ($order->hasStatus('draft'))
                                @php($possible = $bundleAvailability[$bundleLine->bundle_id] ?? 0)
                                <td @class(['px-4 py-2.5 text-right tabular-nums', 'text-rose-600 font-medium' => $possible < $bundleLine->qty, 'text-slate-600' => $possible >= $bundleLine->qty])
                                    title="Jumlah paket yang bisa dipenuhi dari stok isinya">
                                    {{ number_format($possible, 0, ',', '.') }} <span class="text-xs font-normal">paket</span>
                                </td>
                            @endif
                            @if ($showPicks)
                                <td class="px-4 py-2.5"></td>
                            @endif
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ $money($bundleLine->price) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-900">{{ $money($bundleLine->qty * $bundleLine->price) }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $bundleLine->notes ?? '—' }}</td>
                        </tr>
                        @foreach ($order->items->where('sales_order_bundle_id', $bundleLine->id) as $item)
                            @include('sales.orders._item-row', ['item' => $item, 'number' => null])
                        @endforeach
                    @endforeach
                </tbody>
                @php($extraCols = ($order->hasStatus('draft') ? 1 : 0) + ($showPicks ? 1 : 0))
                <tfoot class="border-t border-slate-200 bg-slate-50 text-[13px]">
                    <tr>
                        <td colspan="3" class="px-4 py-2 text-right text-slate-500">Total barang</td>
                        <td class="px-4 py-2 text-right font-semibold tabular-nums text-slate-900">{{ number_format($order->items->sum('qty'), 0, ',', '.') }}</td>
                        <td colspan="{{ $extraCols + 1 }}" class="px-4 py-2 text-right text-slate-500">Subtotal</td>
                        <td class="px-4 py-2 text-right font-medium tabular-nums text-slate-700">{{ $money($subtotal) }}</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td colspan="{{ $extraCols + 5 }}" class="px-4 py-1 text-right text-slate-500">Ongkir</td>
                        <td class="px-4 py-1 text-right tabular-nums text-slate-700">{{ $money($order->shipping_fee) }}</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td colspan="{{ $extraCols + 5 }}" class="px-4 py-2 text-right text-slate-500">Total Pesanan</td>
                        <td class="px-4 py-2 text-right font-semibold tabular-nums text-slate-900">{{ $money($subtotal + $order->shipping_fee) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <p class="mt-4 flex items-center gap-1.5 text-xs text-slate-500">
        <x-heroicon-o-information-circle class="size-4" />
        @switch($order->status)
            @case('draft') Stok belum dicadangkan. Klik Konfirmasi bila barang siap diproses. @break
            @case('confirmed') Stok sudah dicadangkan. Cetak picking list lalu klik Picking untuk mencatat bin pengambilan. @break
            @case('picked') Barang sudah diambil dari bin. Kemas lalu klik Kemas. @break
            @case('packed') Paket siap. Isi kurir & resi lalu klik Kirim, stok akan keluar dari gudang. @break
            @case('shipped') Pesanan sudah dikirim dan stok sudah keluar lewat mutasi Barang Keluar. @break
            @default Pesanan dibatalkan; cadangan stok sudah dilepas.
        @endswitch
    </p>

    @if ($order->returns->isNotEmpty())
        <div class="card mt-6">
            <div class="border-b border-slate-100 px-5 py-3">
                <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                    <x-heroicon-o-arrow-uturn-left class="size-5 text-slate-400" /> Retur
                </h2>
            </div>
            <div class="divide-y divide-slate-100">
                @foreach ($order->returns as $return)
                    <a href="{{ route('sales.returns.show', $return) }}" class="flex items-center justify-between gap-3 px-5 py-2.5 text-sm hover:bg-slate-50/60">
                        <span class="flex items-center gap-3">
                            <span class="font-mono font-medium text-slate-900">{{ $return->number }}</span>
                            <span class="text-slate-500">{{ $return->return_date->format('d/m/Y') }}</span>
                            <span class="text-slate-600">layak jual {{ $return->items->sum('qty_good') }} · rusak {{ $return->items->sum('qty_damaged') }}</span>
                        </span>
                        @include('sales.returns._status')
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    @if ($order->isCancellable())
        @can('sales.cancel')
            <div x-data="{ open: false }" x-cloak @cancel-order.window="open = true; $nextTick(() => $refs.reason.focus())" @keydown.escape.window="open = false">
                <div x-show="open" x-transition.opacity class="fixed inset-0 z-[70] bg-black/40"></div>
                <div x-show="open" class="fixed inset-0 z-[80] flex items-center justify-center p-4">
                    <form method="POST" action="{{ route('sales.orders.cancel', $order) }}" x-show="open" x-transition @click.outside="open = false" class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
                        @csrf
                        <div class="flex gap-4">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-rose-100 text-rose-600"><x-heroicon-o-exclamation-triangle class="size-5" /></span>
                            <div class="flex-1">
                                <h3 class="text-base font-semibold text-slate-900">Batalkan {{ $order->number }}?</h3>
                                <p class="mt-1.5 text-sm text-slate-600">Cadangan stok dilepas. Pesanan batal tidak bisa diproses lagi.</p>
                                <label for="cancel_reason" class="form-label mt-3">Alasan (opsional)</label>
                                <input id="cancel_reason" name="cancel_reason" x-ref="reason" maxlength="255" class="form-input" placeholder="cth. Dibatalkan pembeli">
                            </div>
                        </div>
                        <div class="mt-5 flex justify-end gap-2">
                            <button type="button" class="btn-secondary" @click="open = false">Tidak</button>
                            <button type="submit" class="btn-danger">Batalkan Pesanan</button>
                        </div>
                    </form>
                </div>
            </div>
        @endcan
    @endif

    <x-activity.history :subject="$order" />
</x-layouts.app>
