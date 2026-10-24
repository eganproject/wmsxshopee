{{-- Navigasi antar laporan: Stok per Lokasi → Stok Minimum → Saran Pembelian → Pergerakan Barang → Barang Hilang --}}
@php
    $tabs = [
        ['label' => 'Stok per Lokasi', 'route' => 'reports.stock-locations', 'icon' => 'heroicon-o-map-pin'],
        ['label' => 'Stok Minimum', 'route' => 'reports.min-stock', 'icon' => 'heroicon-o-bell-alert'],
        ['label' => 'Saran Pembelian', 'route' => 'reports.reorder', 'icon' => 'heroicon-o-shopping-bag'],
        ['label' => 'Pergerakan Barang', 'route' => 'reports.movements', 'icon' => 'heroicon-o-presentation-chart-line'],
        ['label' => 'Barang Hilang', 'route' => 'reports.lost-items', 'icon' => 'heroicon-o-question-mark-circle'],
    ];
@endphp

<nav class="mb-6 flex gap-1 overflow-x-auto rounded-xl border border-slate-200 bg-white p-1 shadow-sm">
    @foreach ($tabs as $tab)
        @php($active = request()->routeIs($tab['route']))
        <a href="{{ route($tab['route']) }}"
           @class([
               'flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium whitespace-nowrap transition',
               'bg-brand-50 text-brand-700' => $active,
               'text-slate-600 hover:bg-slate-50 hover:text-slate-900' => ! $active,
           ])>
            <x-dynamic-component :component="$tab['icon']" class="size-5" /> {{ $tab['label'] }}
        </a>
    @endforeach
</nav>
