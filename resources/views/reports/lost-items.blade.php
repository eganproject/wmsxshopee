@php
    $fmt = fn ($value) => number_format((int) $value, 0, ',', '.');
@endphp

<x-layouts.app title="Barang Hilang">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Laporan</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Barang Hilang</span>
    </x-slot:breadcrumb>

    <x-page-header title="Barang Hilang" :description="'Kekurangan saat terima transfer antar gudang dan barang yang tidak ada di paket retur, '.$from->translatedFormat('d M Y').' sampai '.$to->translatedFormat('d M Y').'.'" icon="heroicon-o-question-mark-circle">
        <x-reports.export-button />
    </x-page-header>

    <x-reports.tabs />

    <div class="mb-6 grid gap-4 sm:grid-cols-4">
        <div class="card p-4">
            <p class="text-xs text-slate-500">Total hilang</p>
            <p @class(['mt-1 text-xl font-semibold tabular-nums', 'text-rose-600' => $totals->qty > 0, 'text-slate-900' => ! $totals->qty])>{{ $fmt($totals->qty) }}</p>
            <p class="text-xs text-slate-500">{{ $fmt($totals->events) }} kejadian · {{ $fmt($totals->skus) }} SKU</p>
        </div>
        <div class="card p-4">
            <p class="text-xs text-slate-500">Kurang saat terima <span class="whitespace-nowrap">transfer <x-hint text="Selisih antara jumlah yang dikirim gudang asal dan yang benar-benar diterima gudang tujuan." /></span></p>
            <p class="mt-1 text-xl font-semibold tabular-nums text-slate-900">{{ $fmt($totals->transfer_qty) }}</p>
        </div>
        <div class="card p-4">
            <p class="text-xs text-slate-500">Tidak ada di paket <span class="whitespace-nowrap">retur <x-hint text="Barang yang seharusnya kembali di paket retur tapi tidak ada saat paket dibuka." /></span></p>
            <p class="mt-1 text-xl font-semibold tabular-nums text-slate-900">{{ $fmt($totals->return_qty) }}</p>
        </div>
        <div class="card p-4">
            <p class="text-xs text-slate-500">Rentang</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $from->translatedFormat('d M Y') }} – {{ $to->translatedFormat('d M Y') }}</p>
        </div>
    </div>

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:flex-wrap lg:items-center">
            <div class="relative flex-1 lg:min-w-56">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari SKU, produk, no. transfer / retur..." class="form-input pl-8">
            </div>
            <select name="warehouse" class="form-input lg:w-48" onchange="this.form.submit()">
                <option value="">Semua gudang</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected(request('warehouse') == $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                @endforeach
            </select>
            <select name="source" class="form-input lg:w-56" onchange="this.form.submit()">
                <option value="">Semua sumber</option>
                @foreach (\App\Models\LostItem::SOURCES as $value => $meta)
                    <option value="{{ $value }}" @selected(request('source') === $value)>{{ $meta['label'] }}</option>
                @endforeach
            </select>
            <input type="date" name="from" value="{{ $from->toDateString() }}" class="form-input lg:w-40" title="Dari tanggal">
            <input type="date" name="to" value="{{ $to->toDateString() }}" class="form-input lg:w-40" title="Sampai tanggal">
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'warehouse', 'source', 'from', 'to']))
                    <a href="{{ route('reports.lost-items') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">Tanggal</th>
                        <th class="h-8 px-4 py-0 font-normal">Gudang</th>
                        <th class="h-8 px-4 py-0 font-normal"><span class="whitespace-nowrap">Sumber <x-hint text="Asal kehilangan: kekurangan transfer antar gudang atau barang tidak ada di paket retur." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal">SKU</th>
                        <th class="h-8 px-4 py-0 font-normal">Produk / Varian</th>
                        <th class="h-8 px-4 py-0 text-right font-normal"><span class="whitespace-nowrap">Hilang <x-hint text="Jumlah pcs yang hilang. Stok gudang sudah tidak menghitung barang ini." /></span></th>
                        <th class="h-8 px-4 py-0 font-normal">Keterangan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 whitespace-nowrap text-slate-600">{{ $row->lost_date->format('d/m/Y') }}</td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-slate-900">
                                @if ($row->fromWarehouse)
                                    <span class="text-slate-500">{{ $row->fromWarehouse->code }} →</span>
                                @endif
                                {{ $row->warehouse?->code }}
                            </td>
                            <td class="px-4 py-2.5">
                                <p class="text-slate-900">{{ $row->sourceLabel() }}</p>
                                @php($canOpen = $row->source === 'transfer' ? auth()->user()->can('inventory.view') : auth()->user()->can('returns.view'))
                                @if ($canOpen && $row->sourceUrl())
                                    <a href="{{ $row->sourceUrl() }}" class="font-mono text-xs text-brand-600 hover:text-brand-700">{{ $row->reference }}</a>
                                @else
                                    <span class="font-mono text-xs text-slate-500">{{ $row->reference }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 font-mono font-medium text-slate-900">{{ $row->variant?->sku }}</td>
                            <td class="px-4 py-2.5">
                                <p class="text-slate-900">{{ $row->variant?->product?->name }}</p>
                                @if ($row->variant?->name)
                                    <p class="text-xs text-slate-500">{{ $row->variant->name }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right font-semibold whitespace-nowrap tabular-nums text-rose-600">{{ $fmt($row->qty) }} <span class="text-xs font-normal text-slate-500">{{ $row->variant?->product?->unit?->code }}</span></td>
                            <td class="px-4 py-2.5 text-slate-600">
                                {{ $row->notes }}
                                @if ($row->creator)
                                    <p class="text-xs text-slate-400">dicatat {{ $row->creator->name }}</p>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="7" icon="heroicon-o-question-mark-circle" title="Tidak ada barang hilang" description="Tidak ada kekurangan transfer maupun barang hilang di retur pada rentang tanggal ini." />
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $rows->links() }}</div>
        @endif
    </div>

    <p class="mt-4 flex items-center gap-1.5 text-xs text-slate-500">
        <x-heroicon-o-information-circle class="size-4" /> Barang hilang tidak mengubah stok: stoknya sudah keluar dari gudang asal saat dikirim (transfer) atau memang tidak pernah kembali (retur).
    </p>
</x-layouts.app>
