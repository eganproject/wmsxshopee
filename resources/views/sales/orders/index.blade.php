<x-layouts.app title="Pesanan">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Penjualan</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Pesanan</span>
    </x-slot:breadcrumb>

    <x-page-header title="Pesanan" description="Pesanan manual, Shopee dan TikTok. Konfirmasi untuk mencadangkan stok, lalu atur pengiriman, picking, kemas dan kirim." icon="heroicon-o-shopping-cart">
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

    <div class="card" x-data="{
            selected: [],
            rows: @js($bulkRows),
            count(key) { return this.selected.filter((id) => this.rows[id]?.[key]).length },
            ids(key) { return this.selected.filter((id) => this.rows[id]?.[key]) },
            get skipped() {
                const arranged = this.count('arranged'), manual = this.count('manual');
                const other = this.selected.length - this.count('arrange') - arranged - manual;
                return [
                    arranged ? arranged + ' sudah diatur pengirimannya' : null,
                    manual ? manual + ' pesanan manual (atur dari halaman pesanan)' : null,
                    other > 0 ? other + ' belum / tidak bisa diatur' : null,
                ].filter(Boolean).join(' · ');
            },
        }">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:flex-wrap lg:items-center">
            @if ($statusFilter)
                <input type="hidden" name="status" value="{{ $statusFilter }}">
            @endif
            <div class="relative flex-1 lg:min-w-72">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari no. SO, no. marketplace, pembeli, resi atau SKU..." class="form-input pl-8">
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
            <div class="flex items-center gap-2">
                <input type="date" name="from" value="{{ request('from') }}" class="form-input lg:w-40" title="Dari tanggal" aria-label="Dari tanggal">
                <span class="text-sm text-slate-500">s/d</span>
                <input type="date" name="to" value="{{ request('to') }}" class="form-input lg:w-40" title="Sampai tanggal" aria-label="Sampai tanggal">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'channel', 'warehouse', 'shipment', 'shopee', 'from', 'to']))
                    <a href="{{ route('sales.orders.index', request()->only('status')) }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        @can('sales.fulfill')
            {{-- Bar aksi massal mengambang di bawah layar selama ada pesanan yang dicentang. --}}
            <div x-show="selected.length" x-cloak
                 x-transition:enter="transition duration-150 ease-out" x-transition:enter-start="translate-y-4 opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
                 x-transition:leave="transition duration-100 ease-in" x-transition:leave-start="opacity-100" x-transition:leave-end="translate-y-4 opacity-0"
                 @keydown.escape.window="selected = []"
                 class="pointer-events-none fixed inset-x-0 bottom-4 z-40 flex justify-center px-4 lg:left-60 lg:collapsed:left-14"
                 role="region" aria-label="Aksi untuk pesanan terpilih">
                <div class="pointer-events-auto w-full max-w-5xl rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm shadow-xl shadow-slate-900/10">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <span class="flex items-center gap-2 text-slate-600">
                            <span class="flex h-6 min-w-6 items-center justify-center rounded-full bg-slate-900 px-1.5 text-xs font-semibold text-white" x-text="selected.length"></span>
                            pesanan dipilih
                        </span>
                        <span class="hidden h-5 w-px bg-slate-200 sm:block"></span>
                        <div class="flex flex-wrap items-center gap-2">
                            @foreach (\App\Models\SalesOrder::SHIPPING_METHODS as $value => $meta)
                                <form method="POST" action="{{ route('sales.orders.shipment.bulk') }}" x-show="count('arrange')"
                                      data-confirm-title="Atur {{ $meta['label'] }} di marketplace?" :data-confirm="count('arrange') + ' pesanan Shopee / TikTok diatur {{ $meta['label'] }} dengan alamat pickup toko dan jadwal paling awal.'" data-confirm-text="Atur {{ $meta['label'] }}">
                                    @csrf
                                    <input type="hidden" name="method" value="{{ $value }}">
                                    <template x-for="id in ids('arrange')" :key="id"><input type="hidden" name="orders[]" :value="id"></template>
                                    <button type="submit" class="btn-secondary"><x-dynamic-component :component="$meta['icon']" class="size-5" /> Atur {{ $meta['label'] }} <span class="text-slate-500" x-text="'(' + count('arrange') + ')'"></span></button>
                                </form>
                            @endforeach
                            <form method="GET" action="{{ route('sales.orders.shipping-labels') }}" target="_blank" data-no-loading x-show="count('label')">
                                <template x-for="id in ids('label')" :key="id"><input type="hidden" name="orders[]" :value="id"></template>
                                <button type="submit" class="btn-primary"><x-heroicon-o-printer class="size-5" /> Cetak Resi <span class="opacity-75" x-text="'(' + count('label') + ')'"></span></button>
                            </form>
                            <p class="text-xs text-slate-500" x-show="! count('arrange') && ! count('label')">Tidak ada aksi untuk pesanan terpilih.</p>
                        </div>
                        <button type="button" class="btn-secondary ml-auto" title="Batal pilih (Esc)" @click="selected = []"><x-heroicon-o-x-mark class="size-5" /> Batal</button>
                    </div>
                    <p class="mt-2 flex items-center gap-1 border-t border-slate-100 pt-2 text-xs text-slate-500" x-show="skipped">
                        <x-heroicon-o-information-circle class="size-4 shrink-0" /> <span>Dilewati: <span x-text="skipped"></span></span>
                    </p>
                </div>
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
                        <th class="h-8 px-3 py-0 font-normal">Pesanan</th>
                        <th class="h-8 px-3 py-0 font-normal">Saluran / Marketplace</th>
                        <th class="h-8 px-3 py-0 font-normal">Pembeli / Gudang</th>
                        <th class="h-8 px-3 py-0 font-normal">Barang Dipesan</th>
                        <th class="h-8 px-3 py-0 font-normal">Pengiriman</th>
                        <th class="h-8 px-3 py-0 font-normal">Status</th>
                        <th class="h-8 px-3 py-0 text-right font-normal">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($orders as $order)
                        @php($syncError = $order->shopee_sync_error ?? $order->tiktok_sync_error)
                        @php($unmapped = $order->hasStatus('draft') && $order->unmapped_skus !== null)
                        @php($deadline = $order->shipByDeadline())
                        <tr class="align-top hover:bg-slate-50/60" :class="selected.includes({{ $order->id }}) && 'bg-sky-50/60'">
                            @can('sales.fulfill')
                                <td class="py-2.5 pl-4"><input type="checkbox" value="{{ $order->id }}" x-model.number="selected" aria-label="Pilih {{ $order->number }}"></td>
                            @endcan
                            <td class="px-3 py-2.5 whitespace-nowrap">
                                <a href="{{ route('sales.orders.show', $order) }}" class="font-mono font-medium text-slate-900 hover:text-brand-600">{{ $order->number }}</a>
                                <p class="text-xs text-slate-500">{{ $order->order_date->format('d/m/Y') }}</p>
                            </td>
                            <td class="max-w-44 px-3 py-2.5">
                                <p class="text-slate-700">{{ $order->channel_name ?? 'Manual' }}</p>
                                @if ($order->external_number)
                                    <p class="font-mono text-xs text-slate-500">{{ $order->external_number }}</p>
                                @endif
                                <x-sales.shopee-status :order="$order" />
                                <x-sales.tiktok-status :order="$order" />
                                @if ($syncError)
                                    <a href="{{ route('sales.orders.show', $order) }}{{ $unmapped ? '#sku-mapping' : '' }}"
                                       class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-amber-700 hover:underline"
                                       title="{{ $syncError }} · Klik untuk menyelesaikan">
                                        <x-heroicon-o-exclamation-triangle class="size-3.5" />
                                        {{ $unmapped ? 'SKU belum terhubung · Selesaikan' : 'Perlu dicek' }}
                                    </a>
                                @endif
                            </td>
                            <td class="max-w-36 px-3 py-2.5">
                                <p class="truncate text-slate-700" title="{{ $order->customer_name }}">{{ $order->customer_name }}</p>
                                <p class="text-xs text-slate-500" title="{{ $order->warehouse->name }}">{{ $order->warehouse->code }}</p>
                            </td>
                            <td class="px-3 py-2.5">
                                <x-sales.order-lines :order="$order" />
                            </td>
                            <td class="px-3 py-2.5">
                                <x-sales.shipment-status :order="$order" />
                                @if (! $order->isSelfPickup() && ($order->courier || $order->tracking_number))
                                    <p class="mt-1 text-xs text-slate-700">{{ $order->courier?->name }}{{ $order->courier && $order->courier_service ? ' · '.$order->courier_service : '' }}</p>
                                    @if ($order->tracking_number)
                                        <p class="font-mono text-xs text-slate-500">{{ $order->tracking_number }}</p>
                                    @endif
                                @endif
                                @if ($deadline)
                                    <p @class([
                                        'mt-1 flex items-center gap-1 text-[11px] whitespace-nowrap',
                                        'font-medium text-rose-600' => $deadline->isPast(),
                                        'text-amber-700' => ! $deadline->isPast() && $deadline->lte(now()->addDay()),
                                        'text-slate-500' => $deadline->gt(now()->addDay()),
                                    ]) title="Batas kirim dari marketplace">
                                        <x-heroicon-o-clock class="size-3.5" />
                                        {{ $deadline->isPast() ? 'Lewat batas kirim' : 'Kirim sebelum' }} {{ $deadline->format('d/m H:i') }}
                                    </p>
                                @endif
                            </td>
                            <td class="px-3 py-2.5">
                                <x-sales.status-badge :status="$order->status" />
                            </td>
                            <td class="px-3 py-2.5">
                                <div class="flex justify-end gap-0.5">
                                    @if ($order->isEditable())
                                        @can('sales.create')
                                            <a href="{{ route('sales.orders.edit', $order) }}" class="btn-icon" title="Edit">
                                                <x-heroicon-o-pencil-square class="size-5" />
                                            </a>
                                        @endcan
                                    @endif
                                    @can('sales.fulfill')
                                        @if ($order->canArrangeShipment() && ! ($order->isMarketplace() && $order->shipmentAlreadyArranged()))
                                            <a href="{{ route('sales.orders.shipment', $order) }}" class="btn-icon" title="{{ $order->isShipmentArranged() ? 'Ubah pengiriman' : 'Atur pengiriman' }}">
                                                <x-heroicon-o-truck class="size-5" />
                                            </a>
                                        @endif
                                    @endcan
                                    @if ($order->hasStatus(...\App\Models\SalesOrder::RESERVING))
                                        <a href="{{ route('sales.orders.pick-list', $order) }}" target="_blank" class="btn-icon" title="Cetak picking list">
                                            <x-heroicon-o-clipboard-document-list class="size-5" />
                                        </a>
                                    @endif
                                    <a href="{{ route('sales.orders.show', $order) }}" class="btn-icon" title="Lihat detail">
                                        <x-heroicon-o-eye class="size-5" />
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state :colspan="auth()->user()->can('sales.fulfill') ? 8 : 7" icon="heroicon-o-shopping-cart" title="Belum ada pesanan" description="Input pesanan manual atau impor dari file Excel / CSV.">
                            @can('sales.create')
                                <a href="{{ route('sales.orders.create') }}" class="btn-primary"><x-heroicon-o-plus class="size-5" /> Buat Pesanan</a>
                            @endcan
                        </x-empty-state>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Ruang agar baris terakhir tidak tertutup bar aksi mengambang. --}}
        <div x-show="selected.length" x-cloak class="h-24" aria-hidden="true"></div>

        @if ($orders->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $orders->links() }}</div>
        @endif
    </div>
</x-layouts.app>
