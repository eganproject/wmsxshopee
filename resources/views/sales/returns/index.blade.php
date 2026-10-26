<x-layouts.app title="Retur Penjualan">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Penjualan</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Retur Penjualan</span>
    </x-slot:breadcrumb>

    <x-page-header title="Retur Penjualan" description="Paket kembali dari pembeli: scan resi, cek kondisi barang, lalu barang layak jual masuk lagi ke stok." icon="heroicon-o-arrow-uturn-left">
        <x-reports.export-button />
        @can('returns.create')
            <a href="{{ route('sales.returns.create', ['source' => 'manual']) }}" class="btn-secondary">
                <x-heroicon-o-user class="size-5" /> Retur Tanpa Pesanan
            </a>
            <a href="{{ route('sales.returns.create') }}" class="btn-primary">
                <x-heroicon-o-qr-code class="size-5" /> Scan Retur
            </a>
        @endcan
    </x-page-header>

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:flex-wrap lg:items-center">
            <div class="relative flex-1 lg:min-w-64">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari no. retur, resi, SO, pengirim..." class="form-input pl-8">
            </div>
            <select name="status" class="form-input lg:w-32" onchange="this.form.submit()">
                <option value="">Semua status</option>
                @foreach (\App\Models\SalesReturn::STATUSES as $value => $meta)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $meta['label'] }}</option>
                @endforeach
            </select>
            <select name="source" class="form-input lg:w-40" onchange="this.form.submit()">
                <option value="">Semua sumber</option>
                @foreach (\App\Models\SalesReturn::SOURCES as $value => $label)
                    <option value="{{ $value }}" @selected(request('source') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <select name="warehouse" class="form-input lg:w-36" onchange="this.form.submit()">
                <option value="">Semua gudang</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected(request('warehouse') == $warehouse->id)>{{ $warehouse->code }}</option>
                @endforeach
            </select>
            <input type="date" name="from" value="{{ request('from') }}" class="form-input lg:w-36" title="Dari tanggal">
            <input type="date" name="to" value="{{ request('to') }}" class="form-input lg:w-36" title="Sampai tanggal">
            <label class="flex items-center gap-1.5 text-sm whitespace-nowrap text-slate-600">
                <input type="checkbox" name="damaged" value="1" class="rounded border-slate-300" @checked(request()->boolean('damaged')) onchange="this.form.submit()"> Ada rusak
            </label>
            <label class="flex items-center gap-1.5 text-sm whitespace-nowrap text-slate-600">
                <input type="checkbox" name="missing" value="1" class="rounded border-slate-300" @checked(request()->boolean('missing')) onchange="this.form.submit()"> Ada hilang
            </label>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'status', 'source', 'warehouse', 'from', 'to', 'damaged', 'missing']))
                    <a href="{{ route('sales.returns.index') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">No.</th>
                        <th class="h-8 px-4 py-0 font-normal">Tanggal</th>
                        <th class="h-8 px-4 py-0 font-normal"><span class="whitespace-nowrap">Pesanan <x-hint text="Pesanan asal yang diretur. Kosong untuk retur tanpa pesanan." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal">Pengirim</th>
                        <th class="h-8 px-4 py-0 font-normal">Resi <span class="whitespace-nowrap">Retur <x-hint text="Nomor resi paket retur dari pembeli." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal">Gudang</th>
                        <th class="h-8 px-4 py-0 font-normal text-right">Layak <span class="whitespace-nowrap">Jual <x-hint text="Barang dalam kondisi baik yang masuk lagi ke stok jual di lokasi bin." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal text-right"><span class="whitespace-nowrap">Rusak <x-hint text="Barang rusak / cacat. Masuk ke Stok Rusak gudang dan tidak ikut dijual." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal text-right"><span class="whitespace-nowrap">Hilang <x-hint text="Barang yang seharusnya ikut kembali tapi tidak ada di paket. Dicatat di laporan Barang Hilang." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal"><span class="whitespace-nowrap">Status <x-hint text="Draft: stok belum berubah dan masih bisa diubah. Diterima: stok sudah diperbarui dan dokumen terkunci." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($returns as $return)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 font-mono font-medium text-slate-900">
                                <a href="{{ route('sales.returns.show', $return) }}" class="hover:text-brand-600">{{ $return->number }}</a>
                            </td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-slate-600">{{ $return->return_date->format('d/m/Y') }}</td>
                            <td class="px-4 py-2.5">
                                @if ($return->order)
                                    @can('sales.view')
                                        <a href="{{ route('sales.orders.show', $return->order) }}" class="font-mono text-slate-700 hover:text-brand-600">{{ $return->order->number }}</a>
                                    @else
                                        <span class="font-mono text-slate-700">{{ $return->order->number }}</span>
                                    @endcan
                                    @if ($return->external_number)
                                        <p class="text-xs text-slate-500">{{ $return->channel_name }} #{{ $return->external_number }}</p>
                                    @endif
                                @else
                                    <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">Tanpa pesanan</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-slate-700">{{ $return->sender_name }}</td>
                            <td class="px-4 py-2.5 font-mono text-xs text-slate-600">{{ $return->tracking_number ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $return->warehouse->code }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ number_format((int) $return->items_sum_qty_good, 0, ',', '.') }}</td>
                            <td @class(['px-4 py-2.5 text-right tabular-nums', 'text-rose-600' => $return->items_sum_qty_damaged > 0, 'text-slate-400' => ! $return->items_sum_qty_damaged])>
                                {{ number_format((int) $return->items_sum_qty_damaged, 0, ',', '.') }}
                            </td>
                            <td @class(['px-4 py-2.5 text-right tabular-nums', 'font-medium text-amber-600' => $return->items_sum_qty_missing > 0, 'text-slate-400' => ! $return->items_sum_qty_missing])>
                                {{ number_format((int) $return->items_sum_qty_missing, 0, ',', '.') }}
                            </td>
                            <td class="px-4 py-2.5">@include('sales.returns._status')</td>
                            <td class="px-4 py-2.5">
                                <div class="flex justify-end gap-1">
                                    @if ($return->isDraft())
                                        @can('returns.create')
                                            <a href="{{ route('sales.returns.edit', $return) }}" class="btn-icon" title="Edit">
                                                <x-heroicon-o-pencil-square class="size-5" />
                                            </a>
                                        @endcan
                                    @endif
                                    <a href="{{ route('sales.returns.show', $return) }}" class="btn-icon" title="Lihat detail">
                                        <x-heroicon-o-eye class="size-5" />
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="11" icon="heroicon-o-arrow-uturn-left" title="Belum ada retur penjualan" description="Scan resi paket yang kembali untuk mencatat retur.">
                            @can('returns.create')
                                <a href="{{ route('sales.returns.create') }}" class="btn-primary"><x-heroicon-o-plus class="size-5" /> Buat Retur</a>
                            @endcan
                        </x-empty-state>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($returns->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $returns->links() }}</div>
        @endif
    </div>
</x-layouts.app>
