@php
    $statuses = \App\Models\StockAdjustment::STATUSES;
    $fmt = fn ($value) => number_format((int) $value, 0, ',', '.');
@endphp

<x-layouts.app title="Penyesuaian Stok">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Penyesuaian Stok</span>
    </x-slot:breadcrumb>

    <x-page-header title="Penyesuaian Stok" description="Koreksi jumlah stok (plus / minus) dengan alasan dan approval. Stok baru berubah setelah disetujui." icon="heroicon-o-adjustments-horizontal">
        <x-reports.export-button />
        @can('adjustments.create')
            <a href="{{ route('inventory.adjustments.create') }}" class="btn-primary"><x-heroicon-o-plus class="size-5" /> Buat Penyesuaian</a>
        @endcan
    </x-page-header>

    {{-- Ringkasan per status: klik untuk memfilter. --}}
    <div class="mb-4 flex flex-wrap gap-2">
        <a href="{{ request()->fullUrlWithQuery(['status' => null, 'page' => null]) }}"
           @class(['inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm', 'border-brand-500 bg-brand-50 text-brand-700' => ! request('status'), 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50' => request('status')])>
            Semua <span class="tabular-nums text-xs opacity-70">{{ $fmt($counts->sum()) }}</span>
        </a>
        @foreach ($statuses as $value => $meta)
            <a href="{{ request()->fullUrlWithQuery(['status' => $value, 'page' => null]) }}"
               @class(['inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm', 'border-brand-500 bg-brand-50 text-brand-700' => request('status') === $value, 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50' => request('status') !== $value])>
                {{ $meta['label'] }}
                <span @class(['tabular-nums text-xs', 'rounded-full bg-amber-100 px-1.5 font-semibold text-amber-700' => $value === 'pending' && ($counts[$value] ?? 0) > 0, 'opacity-70' => ! ($value === 'pending' && ($counts[$value] ?? 0) > 0)])>{{ $fmt($counts[$value] ?? 0) }}</span>
            </a>
        @endforeach
    </div>

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:items-center">
            <input type="hidden" name="status" value="{{ request('status') }}">
            <div class="relative flex-1 lg:min-w-64">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari no. dokumen, referensi atau SKU..." class="form-input pl-8">
            </div>
            <select name="reason" class="form-input lg:w-52" onchange="this.form.submit()">
                <option value="">Semua alasan</option>
                @foreach (\App\Models\StockAdjustment::REASONS as $value => $label)
                    <option value="{{ $value }}" @selected(request('reason') === $value)>{{ $label }}</option>
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
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'status', 'reason', 'warehouse', 'from', 'to']))
                    <a href="{{ route('inventory.adjustments.index') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">No.</th>
                        <th class="h-8 px-4 py-0 font-normal">Tanggal</th>
                        <th class="h-8 px-4 py-0 font-normal">Gudang</th>
                        <th class="h-8 px-4 py-0 font-normal">Alasan</th>
                        <th class="h-8 px-4 py-0 font-normal">Referensi</th>
                        <th class="h-8 px-4 py-0 text-right font-normal"><span class="whitespace-nowrap">Tambah / Kurang <x-hint text="Total pcs yang ditambah dan dikurangi di semua baris dokumen. Jumlah baris ada di ekspor Excel." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal"><span class="whitespace-nowrap">Status <x-hint text="Draft dan Menunggu Approval belum mengubah stok. Stok berubah saat Disetujui." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal whitespace-nowrap">Dibuat oleh</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($adjustments as $adjustment)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 font-mono font-medium whitespace-nowrap text-slate-900">
                                <a href="{{ route('inventory.adjustments.show', $adjustment) }}" class="hover:text-brand-600">{{ $adjustment->number }}</a>
                            </td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-slate-600">{{ $adjustment->adjustment_date->format('d/m/Y') }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $adjustment->warehouse->code }}</td>
                            <td class="min-w-36 px-4 py-2.5 text-slate-700">{{ $adjustment->reasonLabel() }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $adjustment->reference ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-right whitespace-nowrap tabular-nums">
                                <span class="text-emerald-600">+{{ $fmt($adjustment->qty_plus) }}</span>
                                <span class="text-slate-300">/</span>
                                <span class="text-rose-600">−{{ $fmt(abs((int) $adjustment->qty_minus)) }}</span>
                            </td>
                            <td class="px-4 py-2.5"><x-adjustments.status-badge :adjustment="$adjustment" /></td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-slate-600">{{ $adjustment->creator?->name ?? '—' }}</td>
                            <td class="px-4 py-2.5">
                                <div class="flex justify-end gap-1">
                                    @if ($adjustment->isDraft())
                                        @can('adjustments.create')
                                            <a href="{{ route('inventory.adjustments.edit', $adjustment) }}" class="btn-icon" title="Edit">
                                                <x-heroicon-o-pencil-square class="size-5" />
                                            </a>
                                        @endcan
                                    @endif
                                    <a href="{{ route('inventory.adjustments.show', $adjustment) }}" class="btn-icon" title="Lihat detail">
                                        <x-heroicon-o-eye class="size-5" />
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="9" icon="heroicon-o-adjustments-horizontal" title="Belum ada penyesuaian stok" description="Buat penyesuaian untuk mengoreksi selisih stok. Stok berubah setelah disetujui." />
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($adjustments->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $adjustments->links() }}</div>
        @endif
    </div>
</x-layouts.app>
