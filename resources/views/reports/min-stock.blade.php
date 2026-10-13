@php($fmt = fn ($value) => number_format((int) $value, 0, ',', '.'))

<x-layouts.app title="Stok Minimum">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Laporan</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Stok Minimum</span>
    </x-slot:breadcrumb>

    <x-page-header title="Stok Minimum" description="Barang yang stok tersedianya (fisik dikurangi cadangan pesanan) sudah di bawah atau sama dengan stok minimum." icon="heroicon-o-bell-alert">
        <x-reports.export-button />
    </x-page-header>

    <x-reports.tabs />

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <div class="card p-4">
            <p class="text-xs text-slate-500">Perlu Diisi Ulang</p>
            <p @class(['mt-1 text-xl font-semibold tabular-nums', 'text-rose-600' => $belowCount > 0, 'text-slate-900' => ! $belowCount])>{{ $fmt($belowCount) }} SKU</p>
        </div>
        <div class="card p-4">
            <p class="text-xs text-slate-500">SKU dengan Stok Minimum</p>
            <p class="mt-1 text-xl font-semibold tabular-nums text-slate-900">{{ $fmt($withMinCount) }}</p>
        </div>
        <div class="card flex items-start gap-2 p-4 text-xs text-slate-500">
            <x-heroicon-o-information-circle class="size-4 shrink-0" />
            <span>Stok minimum diatur per varian di Master Produk. Tanpa filter gudang, stok dijumlahkan dari semua gudang.</span>
        </div>
    </div>

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:items-center">
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari SKU atau nama produk..." class="form-input pl-8">
            </div>
            <select name="warehouse" class="form-input lg:w-48" onchange="this.form.submit()">
                <option value="">Semua gudang</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected(request('warehouse') == $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                @endforeach
            </select>
            <select name="show" class="form-input lg:w-56" onchange="this.form.submit()">
                <option value="">Hanya di bawah minimum</option>
                <option value="all" @selected(request('show') === 'all')>Semua yang punya stok minimum</option>
            </select>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'warehouse', 'show']))
                    <a href="{{ route('reports.min-stock') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="h-8 px-4 py-0 font-normal">SKU</th>
                        <th class="h-8 px-4 py-0 font-normal">Produk / Varian</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Stok Min.</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Fisik</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Dicadangkan</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Tersedia</th>
                        <th class="h-8 px-4 py-0 text-right font-normal">Kekurangan</th>
                        <th class="h-8 px-4 py-0 font-normal">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        @php($below = $row->available <= $row->min_stock)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 font-mono font-medium text-slate-900">
                                <a href="{{ route('inventory.stocks.index', ['view' => 'location', 'search' => $row->sku, 'warehouse' => request('warehouse')]) }}" class="hover:text-brand-600" title="Lihat stok per lokasi">{{ $row->sku }}</a>
                            </td>
                            <td class="px-4 py-2.5">
                                <p class="text-slate-900">{{ $row->product_name }}</p>
                                @if ($row->variant_name)
                                    <p class="text-xs text-slate-500">{{ $row->variant_name }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ $fmt($row->min_stock) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ $fmt($row->on_hand) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ $fmt($row->reserved) }}</td>
                            <td @class(['px-4 py-2.5 text-right font-semibold tabular-nums', 'text-rose-600' => $below, 'text-slate-900' => ! $below])>
                                {{ $fmt($row->available) }} <span class="text-xs font-normal text-slate-500">{{ $row->unit_code }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ $below ? $fmt($row->min_stock - $row->available) : '—' }}</td>
                            <td class="px-4 py-2.5">
                                @if ($row->available <= 0)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-50 px-2.5 py-0.5 text-xs font-medium text-rose-700"><x-heroicon-o-x-circle class="size-3.5" /> Habis</span>
                                @elseif ($below)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700"><x-heroicon-o-exclamation-triangle class="size-3.5" /> Menipis</span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700"><x-heroicon-o-check-circle class="size-3.5" /> Aman</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-5 py-16 text-center">
                                <x-heroicon-o-bell-alert class="mx-auto size-12 text-slate-300" />
                                <p class="mt-3 font-medium text-slate-700">{{ $withMinCount ? 'Semua stok aman' : 'Belum ada stok minimum' }}</p>
                                <p class="text-sm text-slate-500">{{ $withMinCount ? 'Tidak ada barang di bawah stok minimum.' : 'Isi kolom Stok Minimum pada varian di Master Produk.' }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $rows->links() }}</div>
        @endif
    </div>
</x-layouts.app>
