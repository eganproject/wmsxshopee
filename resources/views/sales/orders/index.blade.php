<x-layouts.app title="Pesanan">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Penjualan</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Pesanan</span>
    </x-slot:breadcrumb>

    <x-page-header title="Pesanan" description="Pesanan manual: konfirmasi untuk mencadangkan stok, lalu picking, kemas dan kirim." icon="heroicon-o-shopping-cart">
        <x-reports.export-button />
        @can('sales.create')
            <a href="{{ route('sales.orders.import') }}" class="btn-secondary">
                <x-heroicon-o-arrow-up-tray class="size-5" /> Impor Excel / CSV
            </a>
            <a href="{{ route('sales.orders.create') }}" class="btn-primary">
                <x-heroicon-o-plus class="size-5" /> Pesanan Baru
            </a>
        @endcan
    </x-page-header>

    <div class="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200 text-sm">
        @php($statusFilter = request('status'))
        <a href="{{ route('sales.orders.index', request()->except(['status', 'page'])) }}"
           @class(['-mb-px border-b-2 px-3 py-2 whitespace-nowrap', 'border-brand-500 font-medium text-slate-900' => ! $statusFilter, 'border-transparent text-slate-500 hover:text-slate-700' => $statusFilter])>
            Semua <span class="ml-1 text-xs text-slate-400">{{ $counts->sum() }}</span>
        </a>
        @foreach (\App\Models\SalesOrder::STATUSES as $value => $meta)
            <a href="{{ route('sales.orders.index', [...request()->except(['status', 'page']), 'status' => $value]) }}"
               @class(['-mb-px border-b-2 px-3 py-2 whitespace-nowrap', 'border-brand-500 font-medium text-slate-900' => $statusFilter === $value, 'border-transparent text-slate-500 hover:text-slate-700' => $statusFilter !== $value])>
                {{ $meta['label'] }} <span class="ml-1 text-xs text-slate-400">{{ $counts[$value] ?? 0 }}</span>
            </a>
        @endforeach
    </div>

    @if ($failures = session('shipment_failures'))
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <p class="flex items-center gap-1.5 font-medium"><x-heroicon-o-exclamation-triangle class="size-4" /> {{ count($failures) }} pesanan belum bisa diatur</p>
            <ul class="mt-1 space-y-0.5 pl-6">
                @foreach ($failures as $number => $reason)
                    <li class="list-disc"><span class="font-mono">{{ $number }}</span>: {{ $reason }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card" x-data="{ selected: [] }">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:flex-wrap lg:items-center">
            @if ($statusFilter)
                <input type="hidden" name="status" value="{{ $statusFilter }}">
            @endif
            <div class="relative flex-1 lg:min-w-72">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari no. SO, no. marketplace, pembeli atau resi..." class="form-input pl-8">
            </div>
            <select name="channel" class="form-input lg:w-48" onchange="this.form.submit()">
                <option value="">Semua saluran</option>
                @foreach ($channels as $channel)
                    <option value="{{ $channel }}" @selected(request('channel') === $channel)>{{ $channel }}</option>
                @endforeach
            </select>
            <select name="warehouse" class="form-input lg:w-40" onchange="this.form.submit()">
                <option value="">Semua gudang</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected(request('warehouse') == $warehouse->id)>{{ $warehouse->code }}</option>
                @endforeach
            </select>
            <select name="shipment" class="form-input lg:w-44" onchange="this.form.submit()">
                <option value="">Semua pengiriman</option>
                <option value="unarranged" @selected(request('shipment') === 'unarranged')>Belum diatur</option>
                @foreach (\App\Models\SalesOrder::SHIPPING_METHODS as $value => $meta)
                    <option value="{{ $value }}" @selected(request('shipment') === $value)>{{ $meta['label'] }}</option>
                @endforeach
            </select>
            <select name="shopee" class="form-input lg:w-52" onchange="this.form.submit()">
                <option value="">Semua status Shopee</option>
                <optgroup label="Perlu dicek">
                    <option value="courier_pending" @selected(request('shopee') === 'courier_pending')>Sudah serah terima, belum discan kurir</option>
                    <option value="courier_overdue" @selected(request('shopee') === 'courier_overdue')>Belum discan kurir &gt; {{ config('alerts.courier_scan_hours') }} jam</option>
                    <option value="shopee_ahead" @selected(request('shopee') === 'shopee_ahead')>Dikirim di Shopee, belum di gudang</option>
                </optgroup>
                <optgroup label="Status di Shopee">
                    @foreach (\App\Models\SalesOrder::SHOPEE_STATUSES as $value => $meta)
                        <option value="{{ $value }}" @selected(request('shopee') === $value)>{{ $meta['label'] }}</option>
                    @endforeach
                </optgroup>
            </select>
            <input type="date" name="from" value="{{ request('from') }}" class="form-input lg:w-40" title="Dari tanggal">
            <input type="date" name="to" value="{{ request('to') }}" class="form-input lg:w-40" title="Sampai tanggal">
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'channel', 'warehouse', 'shipment', 'shopee', 'from', 'to']))
                    <a href="{{ route('sales.orders.index', request()->only('status')) }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        @can('sales.fulfill')
            <div x-show="selected.length" x-cloak class="flex flex-wrap items-center gap-2 border-b border-slate-100 bg-slate-50 px-4 py-2 text-sm">
                <span class="mr-2 text-slate-600"><span class="font-semibold text-slate-900" x-text="selected.length"></span> dipilih</span>
                @foreach (\App\Models\SalesOrder::SHIPPING_METHODS as $value => $meta)
                    <form method="POST" action="{{ route('sales.orders.shipment.bulk') }}"
                          data-confirm-title="Atur {{ $meta['label'] }} di marketplace?" data-confirm="Pesanan Shopee & TikTok terpilih diatur {{ $meta['label'] }} dengan alamat pickup toko dan jadwal paling awal. Pesanan manual dilewati." data-confirm-text="Atur {{ $meta['label'] }}">
                        @csrf
                        <input type="hidden" name="method" value="{{ $value }}">
                        <template x-for="id in selected" :key="id"><input type="hidden" name="orders[]" :value="id"></template>
                        <button type="submit" class="btn-secondary"><x-dynamic-component :component="$meta['icon']" class="size-5" /> Atur {{ $meta['label'] }}</button>
                    </form>
                @endforeach
                <form method="GET" action="{{ route('sales.orders.shipping-labels') }}" target="_blank">
                    <template x-for="id in selected" :key="id"><input type="hidden" name="orders[]" :value="id"></template>
                    <button type="submit" class="btn-secondary"><x-heroicon-o-printer class="size-5" /> Cetak Resi</button>
                </form>
                <button type="button" class="btn-icon ml-auto" title="Batal pilih" @click="selected = []"><x-heroicon-o-x-mark class="size-5" /></button>
            </div>
        @endcan

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        @can('sales.fulfill')
                            <th class="h-8 w-8 py-0 pl-4">
                                <input type="checkbox" aria-label="Pilih semua" :checked="selected.length && selected.length === @js($orders->pluck('id'))->length"
                                       @change="selected = $event.target.checked ? @js($orders->pluck('id')) : []">
                            </th>
                        @endcan
                        <th class="h-8 px-4 py-0 font-normal">No.</th>
                        <th class="h-8 px-4 py-0 font-normal">Tanggal</th>
                        <th class="h-8 px-4 py-0 font-normal">Saluran / No. Marketplace</th>
                        <th class="h-8 px-4 py-0 font-normal">Pembeli</th>
                        <th class="h-8 px-4 py-0 font-normal">Gudang</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Qty</th>
                        <th class="h-8 px-4 py-0 font-normal">Kurir / Resi</th>
                        <th class="h-8 px-4 py-0 font-normal">Status</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($orders as $order)
                        <tr class="hover:bg-slate-50/60">
                            @can('sales.fulfill')
                                <td class="py-2.5 pl-4"><input type="checkbox" value="{{ $order->id }}" x-model.number="selected" aria-label="Pilih {{ $order->number }}"></td>
                            @endcan
                            <td class="px-4 py-2.5 font-mono font-medium text-slate-900">
                                <a href="{{ route('sales.orders.show', $order) }}" class="hover:text-brand-600">{{ $order->number }}</a>
                            </td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-slate-600">{{ $order->order_date->format('d/m/Y') }}</td>
                            <td class="px-4 py-2.5">
                                <p class="text-slate-700">{{ $order->channel_name ?? '—' }}</p>
                                @if ($order->shopee_sync_error || $order->tiktok_sync_error)
                                    <p class="text-xs text-amber-600" title="{{ $order->shopee_sync_error ?? $order->tiktok_sync_error }}">Perlu dicek</p>
                                @endif
                                @if ($order->external_number)
                                    <p class="font-mono text-xs text-slate-500">{{ $order->external_number }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-slate-700">{{ $order->customer_name }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $order->warehouse->code }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ number_format((int) $order->items_sum_qty, 0, ',', '.') }}</td>
                            <td class="px-4 py-2.5">
                                <p class="text-slate-700">{{ $order->courier?->name ?? '—' }}</p>
                                @if ($order->tracking_number)
                                    <p class="font-mono text-xs text-slate-500">{{ $order->tracking_number }}</p>
                                @endif
                                @if ($order->shippingMethodLabel())
                                    <span class="mt-0.5 inline-block rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600">{{ $order->shippingMethodLabel() }}{{ $order->shipping_method === 'pickup' && $order->pickup_at ? ' · '.$order->pickup_at->format('d/m H:i') : '' }}</span>
                                @elseif ($order->canArrangeShipment())
                                    <span class="mt-0.5 inline-block rounded-full bg-amber-50 px-2 py-0.5 text-[11px] text-amber-700">Pengiriman belum diatur</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5">
                                <x-sales.status-badge :status="$order->status" />
                                <x-sales.shopee-status :order="$order" />
                                <x-sales.tiktok-status :order="$order" />
                            </td>
                            <td class="px-4 py-2.5">
                                <div class="flex justify-end gap-1">
                                    @if ($order->isEditable())
                                        @can('sales.create')
                                            <a href="{{ route('sales.orders.edit', $order) }}" class="btn-icon" title="Edit">
                                                <x-heroicon-o-pencil-square class="size-5" />
                                            </a>
                                        @endcan
                                    @endif
                                    @if ($order->hasStatus(...\App\Models\SalesOrder::RESERVING))
                                        <a href="{{ route('sales.orders.pick-list', $order) }}" target="_blank" class="btn-icon" title="Cetak picking list">
                                            <x-heroicon-o-printer class="size-5" />
                                        </a>
                                    @endif
                                    <a href="{{ route('sales.orders.show', $order) }}" class="btn-icon" title="Lihat detail">
                                        <x-heroicon-o-eye class="size-5" />
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state :colspan="auth()->user()->can('sales.fulfill') ? 10 : 9" icon="heroicon-o-shopping-cart" title="Belum ada pesanan" description="Input pesanan manual atau impor dari file Excel / CSV.">
                            @can('sales.create')
                                <a href="{{ route('sales.orders.create') }}" class="btn-primary"><x-heroicon-o-plus class="size-5" /> Buat Pesanan</a>
                            @endcan
                        </x-empty-state>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($orders->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $orders->links() }}</div>
        @endif
    </div>
</x-layouts.app>
