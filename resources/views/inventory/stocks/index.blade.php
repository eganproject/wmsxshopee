@php($fmt = fn ($value) => number_format((int) $value, 0, ',', '.'))

<x-layouts.app title="Stok">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span>Inventori</span>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Stok</span>
    </x-slot:breadcrumb>

    <x-page-header title="Stok" description="Stok fisik, yang sudah dipesan dan yang masih tersedia per SKU, serta saldo per lokasi bin." icon="heroicon-o-archive-box">
        <x-reports.export-button />
        <x-inventory.create-menu />
    </x-page-header>

    <x-inventory.tabs />

    @if ($mode === 'variant')
        <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card p-4">
                <p class="text-xs text-slate-500"><span class="whitespace-nowrap">Fisik <x-hint text="Jumlah barang yang secara fisik ada di rak gudang, termasuk yang sudah diambil picker tapi belum dikirim." /></span></p>
                <p class="mt-1 text-xl font-semibold tabular-nums text-slate-900">{{ $fmt($totals['on_hand']) }}</p>
                <p class="text-xs text-slate-500">{{ $fmt($totals['skus']) }} SKU ditampilkan</p>
            </div>
            <div class="card p-4">
                <p class="text-xs text-slate-500"><span class="whitespace-nowrap">Dipesan <x-hint text="Stok yang sudah dicadangkan untuk pesanan Dikonfirmasi, Sedang Diambil dan Sudah Dikemas. Berkurang dari fisik saat pesanan dikirim." /></span></p>
                <p class="mt-1 text-xl font-semibold tabular-nums text-sky-700">{{ $fmt($totals['reserved']) }}</p>
                <p class="text-xs text-slate-500">Dicadangkan pesanan yang belum dikirim</p>
            </div>
            <div class="card p-4">
                <p class="text-xs text-slate-500"><span class="whitespace-nowrap">Tersedia <x-hint text="Fisik dikurangi Dipesan. Inilah stok yang masih bisa menerima pesanan baru." /></span></p>
                <p @class(['mt-1 text-xl font-semibold tabular-nums', 'text-emerald-700' => $totals['available'] > 0, 'text-rose-600' => $totals['available'] <= 0])>{{ $fmt($totals['available']) }}</p>
                <p class="text-xs text-slate-500">Fisik − Dipesan @if ($totals['held']) · {{ $fmt($totals['held']) }} tertahan di draft @endif</p>
            </div>
            <a href="{{ route('inventory.stocks.index', [...request()->except(['status', 'page']), 'status' => 'short']) }}" class="card block p-4 transition hover:border-rose-200">
                <p class="text-xs text-slate-500">SKU Kurang <span class="whitespace-nowrap">Stok <x-hint text="Jumlah SKU yang pesanannya (termasuk draft) melebihi stok tersedia. Klik untuk melihat daftarnya." next="Terima barang dari supplier atau transfer dari gudang lain." /></span></p>
                <p @class(['mt-1 text-xl font-semibold tabular-nums', 'text-rose-600' => $totals['short'] > 0, 'text-slate-900' => ! $totals['short']])>{{ $fmt($totals['short']) }} SKU</p>
                <p class="text-xs text-slate-500">Pesanan melebihi stok tersedia</p>
            </a>
        </div>
    @endif

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 md:flex-row md:items-center">
            <input type="hidden" name="view" value="{{ $mode }}">
            @if ($filter)
                <input type="hidden" name="status" value="{{ $filter }}">
            @endif
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-slate-400" />
                <input type="search" name="search" value="{{ request('search') }}" class="form-input pl-11"
                       placeholder="{{ ['location' => 'Cari SKU, barcode, nama produk atau kode lokasi...', 'bundle' => 'Cari SKU paket, nama paket atau SKU isi...'][$mode] ?? 'Cari SKU, barcode atau nama produk...' }}">
            </div>
            <select name="warehouse" class="form-input md:w-52" onchange="this.form.submit()">
                <option value="">Semua gudang</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected(request('warehouse') == $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                @endforeach
            </select>
            <label class="flex items-center gap-2 text-sm whitespace-nowrap text-slate-600">
                <input type="checkbox" name="zero" value="1" class="form-checkbox" @checked(request()->boolean('zero')) onchange="this.form.submit()">
                {{ ['variant' => 'Tampilkan semua SKU', 'bundle' => 'Tampilkan paket nonaktif'][$mode] ?? 'Tampilkan stok 0' }}
            </label>
            <div class="flex gap-2">
                <button type="submit" class="btn-secondary"><x-heroicon-o-funnel class="size-5" /> Filter</button>
                @if (request()->hasAny(['search', 'warehouse', 'zero', 'status']))
                    <a href="{{ route('inventory.stocks.index', ['view' => $mode]) }}" class="btn-icon" title="Reset filter"><x-heroicon-o-x-mark class="size-5" /></a>
                @endif
            </div>
        </form>

        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
            <div class="flex flex-wrap items-center gap-3">
                <div class="inline-flex rounded-lg border border-slate-200 p-0.5 text-sm">
                    @foreach (['variant' => 'Per SKU', 'location' => 'Per Lokasi', 'bundle' => 'Paket (Virtual)'] as $value => $label)
                        <a href="{{ route('inventory.stocks.index', [...request()->except(['view', 'page', 'status']), 'view' => $value]) }}"
                           @class([
                               'rounded-md px-3 py-1.5 font-medium transition',
                               'bg-brand-50 text-brand-700' => $mode === $value,
                               'text-slate-600 hover:text-slate-900' => $mode !== $value,
                           ])>{{ $label }}</a>
                    @endforeach
                </div>
                @if ($mode === 'variant')
                    <div class="flex flex-wrap gap-1.5 text-sm">
                        @foreach (['' => 'Semua', ...\App\Support\StockOverview::FILTERS] as $value => $label)
                            <a href="{{ route('inventory.stocks.index', array_filter([...request()->except(['status', 'page']), 'status' => $value])) }}"
                               @class([
                                   'rounded-full border px-3 py-1 transition',
                                   'border-brand-200 bg-brand-50 font-medium text-brand-700' => ($filter ?? '') === $value,
                                   'border-slate-200 text-slate-600 hover:text-slate-900' => ($filter ?? '') !== $value,
                               ])>{{ $label }}</a>
                        @endforeach
                    </div>
                @endif
            </div>
            @if ($mode === 'bundle')
                <p class="text-sm text-slate-500">Paket tidak punya stok sendiri. Angkanya dihitung dari stok tersedia barang isinya.</p>
            @endif
            @if ($mode === 'location')
                <p class="text-sm text-slate-500">Total: <span class="font-semibold text-slate-900">{{ $fmt($totalQty) }}</span></p>
            @endif
        </div>

        <div class="overflow-x-auto">
            @if ($mode === 'variant')
                @include('inventory.stocks.partials.by-variant')
            @elseif ($mode === 'bundle')
                @include('inventory.stocks.partials.by-bundle')
            @else
                @include('inventory.stocks.partials.by-location')
            @endif
        </div>

        @if ($stocks->hasPages())
            <div class="border-t border-slate-100 px-5 py-4">{{ $stocks->links() }}</div>
        @endif
    </div>
</x-layouts.app>
