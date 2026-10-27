<x-layouts.app title="Penerimaan Barang">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Inventori</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Penerimaan Barang</span>
    </x-slot:breadcrumb>

    <x-page-header title="Penerimaan Barang" description="Barang datang dari supplier: cek jumlah, catat yang rusak, lalu simpan ke lokasi bin." icon="heroicon-o-inbox-arrow-down">
        <x-reports.export-button />
        @can('receipts.create')
            <a href="{{ route('inventory.receipts.create') }}" class="btn-primary">
                <x-heroicon-o-plus class="size-5" /> Penerimaan Baru
            </a>
        @endcan
    </x-page-header>

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:items-center">
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari no. penerimaan atau PO / surat jalan..." class="form-input pl-8">
            </div>
            <select name="status" class="form-input lg:w-36" onchange="this.form.submit()">
                <option value="">Semua status</option>
                @foreach (\App\Models\GoodsReceipt::STATUSES as $value => $meta)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $meta['label'] }}</option>
                @endforeach
            </select>
            <select name="supplier" class="form-input lg:w-48" onchange="this.form.submit()">
                <option value="">Semua supplier</option>
                @foreach ($suppliers as $supplier)
                    <option value="{{ $supplier->id }}" @selected(request('supplier') == $supplier->id)>{{ $supplier->name }}</option>
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
                @if (request()->hasAny(['search', 'status', 'supplier', 'warehouse', 'from', 'to']))
                    <a href="{{ route('inventory.receipts.index') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">No.</th>
                        <th class="h-8 px-4 py-0 font-normal">Tanggal</th>
                        <th class="h-8 px-4 py-0 font-normal">Supplier</th>
                        <th class="h-8 px-4 py-0 font-normal">PO / Surat <span class="whitespace-nowrap">Jalan <x-hint text="Nomor dokumen dari supplier." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal">Gudang</th>
                        <th class="h-8 px-4 py-0 font-normal text-right"><span class="whitespace-nowrap">Diterima <x-hint text="Total barang kondisi baik yang masuk stok." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal text-right"><span class="whitespace-nowrap">Rusak <x-hint text="Total barang rusak yang masuk Stok Rusak." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal"><span class="whitespace-nowrap">Status <x-hint text="Draft: belum menambah stok dan masih bisa diubah. Diterima: stok sudah masuk dan dokumen terkunci." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($receipts as $receipt)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 font-mono font-medium text-slate-900">
                                <a href="{{ route('inventory.receipts.show', $receipt) }}" class="hover:text-brand-600">{{ $receipt->number }}</a>
                            </td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-slate-600">{{ $receipt->receipt_date->format('d/m/Y') }}</td>
                            <td class="px-4 py-2.5 text-slate-700">{{ $receipt->supplier->name }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $receipt->supplier_reference ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $receipt->warehouse->code }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ number_format((int) $receipt->items_sum_qty_received, 0, ',', '.') }}</td>
                            <td @class(['px-4 py-2.5 text-right tabular-nums', 'text-rose-600' => $receipt->items_sum_qty_damaged > 0, 'text-slate-400' => ! $receipt->items_sum_qty_damaged])>
                                {{ number_format((int) $receipt->items_sum_qty_damaged, 0, ',', '.') }}
                            </td>
                            <td class="px-4 py-2.5">@include('inventory.receipts._status')</td>
                            <td class="px-4 py-2.5">
                                <div class="flex justify-end gap-1">
                                    @if ($receipt->isDraft())
                                        @can('receipts.create')
                                            <a href="{{ route('inventory.receipts.edit', $receipt) }}" class="btn-icon" title="Edit">
                                                <x-heroicon-o-pencil-square class="size-5" />
                                            </a>
                                        @endcan
                                    @endif
                                    <a href="{{ route('inventory.receipts.show', $receipt) }}" class="btn-icon" title="Lihat detail">
                                        <x-heroicon-o-eye class="size-5" />
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="9" icon="heroicon-o-inbox-arrow-down" title="Belum ada penerimaan barang" description="Catat barang yang datang dari supplier di sini.">
                            @can('receipts.create')
                                <a href="{{ route('inventory.receipts.create') }}" class="btn-primary"><x-heroicon-o-plus class="size-5" /> Catat Penerimaan</a>
                            @endcan
                        </x-empty-state>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($receipts->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $receipts->links() }}</div>
        @endif
    </div>
</x-layouts.app>
