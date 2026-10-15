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
            @if ($order->hasShippingLabel())
                <a href="{{ route('sales.orders.shipping-label', $order) }}" target="_blank" class="btn-secondary"><x-heroicon-o-printer class="size-5" /> Cetak Resi</a>
            @endif
            @if ($order->canArrangeShipment())
                <a href="{{ route('sales.orders.shipment', $order) }}" @class(['btn-primary' => ! $order->isShipmentArranged() && $order->hasStatus('confirmed'), 'btn-secondary' => $order->isShipmentArranged() || ! $order->hasStatus('confirmed')])>
                    <x-heroicon-o-truck class="size-5" /> {{ $order->isShipmentArranged() ? 'Ubah Pengiriman' : 'Atur Pengiriman' }}
                </a>
            @endif
            @if ($order->hasStatus('confirmed'))
                <a href="{{ route('sales.orders.picking', $order) }}" class="btn-primary"><x-heroicon-o-hand-raised class="size-5" /> Picking</a>
            @elseif ($order->hasStatus('picked'))
                @can('sales.skip_scan')
                    <form id="pack-order" method="POST" action="{{ route('sales.orders.pack', $order) }}">@csrf</form>
                    <button type="button" class="btn-secondary"
                            @click="$dispatch('confirm', { form: 'pack-order', title: 'Kemas tanpa QC scan?', message: 'Pesanan ditandai dikemas tanpa scan barang. Pastikan semua barang sudah dicek manual.', confirmText: 'Sudah Dikemas', variant: 'primary' })">
                        <x-heroicon-o-archive-box class="size-5" /> Kemas tanpa Scan
                    </button>
                @endcan
                <a href="{{ route('sales.qc.show', $order) }}" class="btn-primary"><x-heroicon-o-viewfinder-circle class="size-5" /> QC Scan</a>
            @elseif ($order->hasStatus('packed') && $order->isSelfPickup())
                <form id="hand-to-customer" method="POST" action="{{ route('sales.orders.hand-to-customer', $order) }}">@csrf</form>
                <button type="button" class="btn-primary"
                        @click="$dispatch('confirm', { form: 'hand-to-customer', title: 'Serahkan ke pembeli?', message: @js("Barang diserahkan ke {$order->customer_name}. Stok keluar dari bin hasil picking dan pesanan selesai."), confirmText: 'Serahkan', variant: 'primary' })">
                    <x-heroicon-o-hand-raised class="size-5" /> Serahkan ke Pembeli
                </button>
            @elseif ($order->hasStatus('packed'))
                <a href="{{ route('sales.handovers.index') }}" class="btn-primary"><x-heroicon-o-paper-airplane class="size-5" /> Serah Terima</a>
            @endif
        @endcan

        @if ($order->hasStatus('shipped'))
            @can('returns.create')
                <a href="{{ route('sales.returns.create', ['order' => $order->id]) }}" class="btn-secondary"><x-heroicon-o-arrow-uturn-left class="size-5" /> Buat Retur</a>
            @endcan
        @endif
    </x-page-header>

    @if ($order->shopee_shop_id)
        <div @class([
            'mb-5 flex items-start gap-3 rounded-lg border px-4 py-3 text-sm',
            'border-amber-200 bg-amber-50 text-amber-800' => $order->shopee_sync_error,
            'border-slate-200 bg-slate-50 text-slate-600' => ! $order->shopee_sync_error,
        ])>
            <x-heroicon-o-building-storefront class="mt-0.5 size-5 shrink-0" />
            <div>
                <p>
                    Pesanan Shopee <span class="font-mono">{{ $order->external_number }}</span>
                    · status di Shopee <span class="font-medium">{{ $order->shopeeStatusLabel() ?? '—' }}</span>
                    @if ($order->shopee_shipped_at)
                        · diterima kurir {{ $order->shopee_shipped_at->translatedFormat('d M Y H:i') }}
                    @elseif ($order->awaitingCourierScan())
                        · <span class="font-medium">belum discan kurir</span> (serah terima {{ $order->shipped_at?->diffForHumans() }})
                    @endif
                    @if ($order->shipped_automatically)
                        · dikirim otomatis mengikuti status Shopee
                    @endif
                    @if ($order->shopee_synced_at)
                        · disinkron {{ $order->shopee_synced_at->diffForHumans() }}
                    @endif
                </p>
                @if ($order->shopee_sync_error)
                    <p class="mt-0.5 font-medium">{{ $order->shopee_sync_error }}</p>
                @endif
            </div>
        </div>
    @endif


    @if ($order->tiktok_shop_id)
        <div @class([
            'mb-5 flex items-start gap-3 rounded-lg border px-4 py-3 text-sm',
            'border-amber-200 bg-amber-50 text-amber-800' => $order->tiktok_sync_error,
            'border-slate-200 bg-slate-50 text-slate-600' => ! $order->tiktok_sync_error,
        ])>
            <x-heroicon-o-musical-note class="mt-0.5 size-5 shrink-0" />
            <div>
                <p>
                    Pesanan TikTok <span class="font-mono">{{ $order->external_number }}</span>
                    · status di TikTok <span class="font-medium">{{ $order->tiktokStatusLabel() ?? '—' }}</span>
                    @if ($order->tiktok_ship_by && ! $order->hasStatus('shipped', 'cancelled'))
                        · siap kirim sebelum {{ $order->tiktok_ship_by->format('d/m/Y H:i') }}
                    @endif
                    @if ($order->shipped_automatically)
                        · dikirim otomatis mengikuti status TikTok
                    @endif
                    @if ($order->tiktok_synced_at)
                        · disinkron {{ $order->tiktok_synced_at->diffForHumans() }}
                    @endif
                </p>
                @if ($order->tiktok_sync_error)
                    <p class="mt-0.5 font-medium">{{ $order->tiktok_sync_error }}</p>
                @elseif ($order->hasStatus('confirmed', 'picked', 'packed') && ! $order->tiktokShipmentArranged())
                    <p class="mt-0.5">Atur pickup / drop off dengan tombol Atur Pengiriman (atau dari TikTok Shop Seller Center); status & resi masuk otomatis.</p>
                @endif
            </div>
        </div>
    @endif

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

    @if ($order->isShipmentArranged() || $order->shopeeShipmentArranged() || $order->shipment_handover_id)
        <div class="card mb-6 grid gap-5 p-5 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <p class="text-xs text-slate-500">Cara Kirim</p>
                <p class="mt-1 flex items-center gap-1.5 text-sm font-medium text-slate-900">
                    @if ($order->shipping_method)
                        <x-dynamic-component :component="\App\Models\SalesOrder::SHIPPING_METHODS[$order->shipping_method]['icon']" class="size-4 text-slate-400" />
                        {{ $order->shippingMethodLabel() }}
                    @else
                        Diatur di Shopee Seller Centre
                    @endif
                </p>
                @if ($order->shipment_arranged_at)
                    <p class="text-xs text-slate-500">{{ $order->arranger?->name ?? 'Sistem' }} · {{ $order->shipment_arranged_at->format('d/m/Y H:i') }}</p>
                @endif
            </div>
            <div class="lg:col-span-2">
                @if ($order->shipping_method === 'pickup')
                    <p class="text-xs text-slate-500">Jadwal & Alamat Pickup</p>
                    <p class="mt-1 text-sm font-medium text-slate-900">{{ $order->pickup_slot ?? $order->pickup_at?->translatedFormat('l, d M Y H:i') ?? '—' }}</p>
                    @if ($order->pickup_address)
                        <p class="text-xs text-slate-500">{{ $order->pickup_address }}</p>
                    @endif
                @elseif ($order->shipping_method === 'dropoff')
                    <p class="text-xs text-slate-500">Lokasi Drop Off</p>
                    <p class="mt-1 text-sm font-medium text-slate-900">{{ $order->dropoff_point ?? '—' }}</p>
                @else
                    <p class="text-xs text-slate-500">Resi</p>
                    <p class="mt-1 font-mono text-sm text-slate-900">{{ $order->tracking_number ?? 'Menunggu resi dari Shopee' }}</p>
                @endif
                @if ($order->shopee_ship_by && ! $order->hasStatus('shipped'))
                    <p class="mt-1 text-xs text-slate-500">Batas kirim Shopee {{ $order->shopee_ship_by->format('d/m/Y H:i') }}</p>
                @endif
            </div>
            <div>
                <p class="text-xs text-slate-500">QC & Serah Terima</p>
                <div class="mt-1 space-y-0.5 text-xs text-slate-600">
                    <p>Label resi: {{ $order->shipping_label_printed_at ? 'dicetak '.$order->shipping_label_printed_at->format('d/m H:i') : 'belum dicetak' }}</p>
                    @if ($order->qc_checked_by)
                        <p>QC scan: {{ $order->qcChecker?->name }}</p>
                    @endif
                    @if ($order->handover)
                        <p>Serah terima: <a href="{{ route('sales.handovers.show', $order->handover) }}" class="font-mono text-brand-600 hover:underline">{{ $order->handover->number }}</a></p>
                    @endif
                </div>
            </div>
        </div>
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
            <p class="text-xs text-slate-500">Saluran Penjualan</p>
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
            <p class="text-xs text-slate-500">Pembayaran</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $order->paymentMethodLabel() ?? '—' }}</p>
            @if ($order->payment_status)
                <span @class([
                    'mt-0.5 inline-flex items-center rounded-full px-2 py-0.5 text-xs',
                    'bg-emerald-50 text-emerald-700' => $order->payment_status === 'paid',
                    'bg-amber-50 text-amber-700' => $order->payment_status !== 'paid',
                ])>{{ $order->paymentStatusLabel() }}</span>
            @endif
        </div>
        <div>
            <p class="text-xs text-slate-500">{{ $order->isSelfPickup() ? 'Pengiriman' : 'Kurir / Resi' }}</p>
            @if ($order->isSelfPickup())
                <p class="mt-1 flex items-center gap-1.5 text-sm font-medium text-slate-900"><x-heroicon-o-hand-raised class="size-4 text-slate-400" /> Diambil Pembeli</p>
            @else
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $order->courier?->name ?? '—' }}{{ $order->courier_service ? ' · '.$order->courier_service : '' }}</p>
            @endif
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

    @if ($order->hasStatus('packed') && ! $order->isSelfPickup())
        @can('sales.fulfill')
            <div class="mb-6 flex items-start gap-3 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
                <x-heroicon-o-paper-airplane class="mt-0.5 size-5 shrink-0" />
                <p>Paket sudah lolos QC. Scan resinya di <a href="{{ route('sales.handovers.index') }}" class="font-medium underline">Serah Terima</a> saat diserahkan ke kurir; pesanan otomatis jadi Dikirim dan stok keluar.</p>
            </div>
        @endcan
        @can('sales.skip_scan')
            <form method="POST" action="{{ route('sales.orders.ship', $order) }}" class="card mb-6"
                  data-confirm-title="Kirim pesanan {{ $order->number }}?" data-confirm="Stok keluar dari bin hasil picking dan status pesanan menjadi Dikirim. Pastikan kurir dan nomor resi sudah benar." data-confirm-text="Kirim">
                @csrf
                <div class="border-b border-slate-100 px-5 py-3">
                    <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900"><x-heroicon-o-truck class="size-5 text-slate-400" /> Kirim tanpa Serah Terima</h2>
                    <p class="mt-0.5 text-sm text-slate-500">Cadangan bila scan tidak bisa dipakai. Isi kurir dan nomor resi; stok keluar dari bin hasil picking.</p>
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
                    @if ((float) $order->discount > 0)
                        <tr>
                            <td colspan="{{ $extraCols + 5 }}" class="px-4 py-1 text-right text-slate-500">Diskon</td>
                            <td class="px-4 py-1 text-right tabular-nums text-rose-600">-{{ $money($order->discount) }}</td>
                            <td></td>
                        </tr>
                    @endif
                    <tr>
                        <td colspan="{{ $extraCols + 5 }}" class="px-4 py-1 text-right text-slate-500">Ongkir</td>
                        <td class="px-4 py-1 text-right tabular-nums text-slate-700">{{ $money($order->shipping_fee) }}</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td colspan="{{ $extraCols + 5 }}" class="px-4 py-2 text-right text-slate-500">Total Pesanan</td>
                        <td class="px-4 py-2 text-right font-semibold tabular-nums text-slate-900">{{ $money($order->grandTotal()) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    @if ($order->shopee_income)
        @can('sales.income')
            @include('sales.orders._shopee-income')
        @endcan
    @endif

    @if ($order->tiktok_payment)
        @can('sales.income')
            @include('sales.orders._tiktok-payment')
        @endcan
    @endif

    <p class="mt-4 flex items-center gap-1.5 text-xs text-slate-500">
        <x-heroicon-o-information-circle class="size-4" />
        @if ($order->isSelfPickup() && $order->hasStatus('confirmed', 'picked', 'packed', 'shipped'))
            @switch($order->status)
                @case('confirmed') Stok sudah dicadangkan. Diambil pembeli: tidak perlu atur pengiriman atau resi. Cetak picking list lalu klik Picking. @break
                @case('picked') Barang sudah diambil dari bin. Cek barang lewat QC Scan (scan nomor SO lalu tiap barang) sebelum diserahkan. @break
                @case('packed') Barang siap. Klik Serahkan ke Pembeli saat pembeli mengambil; stok keluar dari gudang. @break
                @default Barang sudah diserahkan ke pembeli dan stok sudah keluar lewat mutasi Barang Keluar.
            @endswitch
        @else
        @switch($order->status)
            @case('draft') Stok belum dicadangkan. Klik Konfirmasi bila barang siap diproses. @break
            @case('confirmed') Stok sudah dicadangkan. Atur pengiriman (pickup / drop off) dan cetak resi, lalu cetak picking list dan klik Picking. @break
            @case('picked') Barang sudah diambil dari bin. Lakukan QC Scan: scan resi lalu scan tiap barang sebelum paket ditutup. @break
            @case('packed') Paket lolos QC. Scan resinya di Serah Terima saat diserahkan ke kurir; stok keluar dari gudang. @break
            @case('shipped') Pesanan sudah dikirim dan stok sudah keluar lewat mutasi Barang Keluar. @break
            @default Pesanan dibatalkan; cadangan stok sudah dilepas.
        @endswitch
        @endif
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
                            <span class="text-slate-600">layak jual {{ $return->items->sum('qty_good') }} · rusak {{ $return->items->sum('qty_damaged') }}@if ($missing = $return->items->sum('qty_missing')) · hilang {{ $missing }}@endif</span>
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
