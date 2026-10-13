<x-layouts.app title="Mutasi Stok">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Inventori</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Mutasi Stok</span>
    </x-slot:breadcrumb>

    <x-page-header title="Mutasi Stok" description="Dokumen barang masuk, keluar, transfer antar lokasi dan penyesuaian." icon="heroicon-o-arrows-right-left">
        <x-reports.export-button />
        <x-inventory.create-menu />
    </x-page-header>

    <x-inventory.tabs />

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:items-center">
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari no. dokumen atau referensi..." class="form-input pl-11">
            </div>
            <select name="type" class="form-input lg:w-44" onchange="this.form.submit()">
                <option value="">Semua jenis</option>
                @foreach ($types as $value => $meta)
                    <option value="{{ $value }}" @selected(request('type') === $value)>{{ $meta['label'] }}</option>
                @endforeach
            </select>
            <select name="warehouse" class="form-input lg:w-48" onchange="this.form.submit()">
                <option value="">Semua gudang</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected(request('warehouse') == $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                @endforeach
            </select>
            <input type="date" name="from" value="{{ request('from') }}" class="form-input lg:w-40" title="Dari tanggal">
            <input type="date" name="to" value="{{ request('to') }}" class="form-input lg:w-40" title="Sampai tanggal">
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'type', 'warehouse', 'from', 'to']))
                    <a href="{{ route('inventory.movements.index') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold tracking-wider text-slate-500 uppercase">
                    <tr>
                        <th class="px-5 py-3">No. Dokumen</th>
                        <th class="px-5 py-3">Tanggal</th>
                        <th class="px-5 py-3">Jenis</th>
                        <th class="px-5 py-3">Gudang</th>
                        <th class="px-5 py-3">Referensi</th>
                        <th class="px-5 py-3 text-right">Baris</th>
                        <th class="px-5 py-3">Dibuat oleh</th>
                        <th class="px-5 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($movements as $movement)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-5 py-3.5 font-mono font-medium text-slate-900">
                                <a href="{{ route('inventory.movements.show', $movement) }}" class="hover:text-brand-600">{{ $movement->number }}</a>
                            </td>
                            <td class="px-5 py-3.5 whitespace-nowrap text-slate-600">{{ $movement->movement_date->format('d/m/Y') }}</td>
                            <td class="px-5 py-3.5"><x-inventory.type-badge :type="$movement->type" /></td>
                            <td class="px-5 py-3.5 text-slate-600">{{ $movement->warehouse->code }}</td>
                            <td class="px-5 py-3.5 text-slate-600">{{ $movement->reference ?? '—' }}</td>
                            <td class="px-5 py-3.5 text-right tabular-nums text-slate-700">{{ $movement->items_count }}</td>
                            <td class="px-5 py-3.5 text-slate-600">{{ $movement->creator?->name ?? '—' }}</td>
                            <td class="px-5 py-3.5">
                                <div class="flex justify-end">
                                    <a href="{{ route('inventory.movements.show', $movement) }}" class="btn-icon" title="Lihat detail">
                                        <x-heroicon-o-eye class="size-5" />
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-5 py-16 text-center">
                                <x-heroicon-o-arrows-right-left class="mx-auto size-12 text-slate-300" />
                                <p class="mt-3 font-medium text-slate-700">Belum ada mutasi stok</p>
                                <p class="text-sm text-slate-500">Mulai dengan mencatat barang masuk ke lokasi bin.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($movements->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $movements->links() }}</div>
        @endif
    </div>
</x-layouts.app>
