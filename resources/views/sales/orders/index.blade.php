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

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:items-center">
            @if ($statusFilter)
                <input type="hidden" name="status" value="{{ $statusFilter }}">
            @endif
            <div class="relative flex-1">
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
            <input type="date" name="from" value="{{ request('from') }}" class="form-input lg:w-40" title="Dari tanggal">
            <input type="date" name="to" value="{{ request('to') }}" class="form-input lg:w-40" title="Sampai tanggal">
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'channel', 'warehouse', 'from', 'to']))
                    <a href="{{ route('sales.orders.index', request()->only('status')) }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
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
                            <td class="px-4 py-2.5 font-mono font-medium text-slate-900">
                                <a href="{{ route('sales.orders.show', $order) }}" class="hover:text-brand-600">{{ $order->number }}</a>
                            </td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-slate-600">{{ $order->order_date->format('d/m/Y') }}</td>
                            <td class="px-4 py-2.5">
                                <p class="text-slate-700">{{ $order->channel_name ?? '—' }}</p>
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
                            </td>
                            <td class="px-4 py-2.5"><x-sales.status-badge :status="$order->status" /></td>
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
                        <x-empty-state colspan="9" icon="heroicon-o-shopping-cart" title="Belum ada pesanan" description="Input pesanan manual atau impor dari file Excel / CSV.">
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
