@php($fmt = fn ($n) => number_format((int) $n, 0, ',', '.'))
<x-layouts.app title="Transfer Antar Gudang">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Inventori</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Transfer Antar Gudang</span>
    </x-slot:breadcrumb>

    <x-page-header title="Transfer Antar Gudang" description="Kirim stok dari gudang besar (cadangan) ke gudang kecil (fulfilment). Selama di jalan stok tetap ikut dijual di marketplace." icon="heroicon-o-truck">
        @can('inventory.create')
            <a href="{{ route('inventory.transfers.create') }}" class="btn-primary"><x-heroicon-o-plus class="size-5" /> Buat Transfer</a>
        @endcan
    </x-page-header>

    @foreach ($suggestions as $group)
        <div class="card mb-6">
            <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                        <x-heroicon-o-light-bulb class="size-5 text-amber-500" /> Saran Isi Ulang {{ $group['warehouse']->code }}
                    </h2>
                    <p class="mt-0.5 text-sm text-slate-500">Dari gudang cadangan {{ $group['rows']->first()->supply->code }}. Dihitung dari pesanan yang kurang dan stok min/maks per SKU, dibulatkan ke koli utuh.</p>
                </div>
                @can('inventory.create')
                    <a href="{{ route('inventory.transfers.create', ['suggest' => $group['warehouse']->id]) }}" class="btn-primary shrink-0"><x-heroicon-o-truck class="size-5" /> Buat Transfer dari Saran</a>
                @endcan
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                        <tr>
                            <th class="px-5 py-2.5">SKU</th>
                            <th class="px-5 py-2.5 text-right"><span class="whitespace-nowrap">Tersedia <x-hint text="Stok tersedia di gudang kecil ini (fisik dikurangi yang dipesan). Minus berarti pesanan melebihi stok." /></span></th>
                            <th class="px-5 py-2.5 text-right">Dalam <span class="whitespace-nowrap">Perjalanan <x-hint text="Barang dari transfer yang sudah dikirim tapi belum diterima." /></span></th>
                            <th class="px-5 py-2.5 text-right">Min / <span class="whitespace-nowrap">Maks <x-hint text="Batas stok minimum dan target maksimum dari Lokasi Tetap SKU. Saran muncul bila tersedia + dalam perjalanan sama atau di bawah Min." /></span></th>
                            <th class="px-5 py-2.5 text-right">Stok <span class="whitespace-nowrap">Cadangan <x-hint text="Stok bebas di gudang cadangan yang bisa dikirim." /></span></th>
                            <th class="px-5 py-2.5 text-right">Saran <span class="whitespace-nowrap">Kirim <x-hint text="Jumlah yang disarankan untuk dikirim: menutup kekurangan pesanan lalu mengisi sampai Maks, dibulatkan per koli dan dibatasi stok cadangan." /></span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($group['rows'] as $row)
                            <tr>
                                <td class="px-5 py-2.5">
                                    <p class="font-mono text-[13px] text-slate-900">{{ $row->variant->sku }}</p>
                                    <p class="text-xs text-slate-500">{{ trim($row->variant->product?->name.' · '.$row->variant->name, ' ·') }}</p>
                                    @if ($row->urgent)
                                        <span class="mt-1 inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 text-xs text-rose-700"><x-heroicon-o-exclamation-triangle class="size-3.5" /> Ada pesanan menunggu</span>
                                    @endif
                                </td>
                                <td @class(['px-5 py-2.5 text-right tabular-nums', 'text-rose-600 font-medium' => $row->available < 0, 'text-slate-700' => $row->available >= 0])>{{ $fmt($row->available) }}</td>
                                <td class="px-5 py-2.5 text-right tabular-nums text-slate-600">{{ $row->transit ? $fmt($row->transit) : '—' }}</td>
                                <td class="px-5 py-2.5 text-right tabular-nums text-slate-600">{{ $row->min === null ? '—' : $fmt($row->min) }} / {{ $row->max === null ? '—' : $fmt($row->max) }}</td>
                                <td class="px-5 py-2.5 text-right tabular-nums text-slate-600">
                                    {{ $fmt($row->source_free) }}
                                    @if ($label = $row->variant->cartonLabel($row->source_free))
                                        <p class="text-xs text-slate-400">{{ $label }}</p>
                                    @endif
                                </td>
                                <td class="px-5 py-2.5 text-right tabular-nums font-semibold text-slate-900">
                                    {{ $fmt($row->qty) }}
                                    @if ($row->qty && ($label = $row->variant->cartonLabel($row->qty)))
                                        <p class="text-xs font-normal text-slate-500">{{ $label }}</p>
                                    @elseif (! $row->qty)
                                        <p class="text-xs font-normal text-rose-600">Stok cadangan habis</p>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:items-center">
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari no. transfer atau referensi..." class="form-input pl-11">
            </div>
            <select name="status" class="form-input lg:w-48" onchange="this.form.submit()">
                <option value="">Semua status</option>
                @foreach ($statuses as $value => $meta)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $meta['label'] }}</option>
                @endforeach
            </select>
            <select name="warehouse" class="form-input lg:w-48" onchange="this.form.submit()">
                <option value="">Semua gudang</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected(request('warehouse') == $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                @endforeach
            </select>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'status', 'warehouse']))
                    <a href="{{ route('inventory.transfers.index') }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-[13px] text-slate-500">
                    <tr>
                        <th class="px-5 py-3">No. Transfer</th>
                        <th class="px-5 py-3">Tanggal Kirim</th>
                        <th class="px-5 py-3">Dari</th>
                        <th class="px-5 py-3">Ke</th>
                        <th class="px-5 py-3 text-right">Total <span class="whitespace-nowrap">Qty <x-hint text="Total pcs semua barang di transfer ini." /></span></th>
                        <th class="px-5 py-3"><span class="whitespace-nowrap">Status <x-hint text="Dalam Perjalanan: sudah dikirim, belum diterima. Diterima: stok sudah masuk gudang tujuan. Dibatalkan: stok kembali ke gudang asal." /></span></th>
                        <th class="px-5 py-3">Dibuat oleh</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($transfers as $transfer)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-5 py-3.5 font-mono font-medium text-slate-900">
                                <a href="{{ route('inventory.transfers.show', $transfer) }}" class="hover:text-brand-600">{{ $transfer->number }}</a>
                            </td>
                            <td class="px-5 py-3.5 whitespace-nowrap text-slate-600">{{ $transfer->transfer_date->format('d/m/Y') }}</td>
                            <td class="px-5 py-3.5 text-slate-600">{{ $transfer->fromWarehouse?->code }}</td>
                            <td class="px-5 py-3.5 text-slate-600">{{ $transfer->toWarehouse?->code }}</td>
                            <td class="px-5 py-3.5 text-right tabular-nums text-slate-700">{{ $fmt($transfer->items_sum_qty) }}</td>
                            <td class="px-5 py-3.5"><x-inventory.transfer-status :transfer="$transfer" /></td>
                            <td class="px-5 py-3.5 text-slate-600">{{ $transfer->creator?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <x-empty-state colspan="7" icon="heroicon-o-truck" title="Belum ada transfer antar gudang" description="Hubungkan gudang kecil ke gudang cadangannya di Master Gudang, lalu buat transfer untuk mengisi ulang stok." />
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($transfers->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $transfers->links() }}</div>
        @endif
    </div>
</x-layouts.app>
