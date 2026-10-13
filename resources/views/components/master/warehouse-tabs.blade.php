{{-- Navigasi antar master struktur gudang: Gudang → Zona → Rak → Lokasi Bin --}}
@php
    $tabs = [
        ['label' => 'Gudang', 'route' => 'master.warehouses.index', 'active' => 'master.warehouses.*', 'icon' => 'heroicon-o-building-office-2'],
        ['label' => 'Zona', 'route' => 'master.zones.index', 'active' => 'master.zones.*', 'icon' => 'heroicon-o-squares-2x2'],
        ['label' => 'Rak', 'route' => 'master.racks.index', 'active' => 'master.racks.*', 'icon' => 'heroicon-o-view-columns'],
        ['label' => 'Lokasi Bin', 'route' => 'master.locations.index', 'active' => 'master.locations.*', 'icon' => 'heroicon-o-map-pin'],
    ];
@endphp

<nav class="mb-6 flex gap-1 overflow-x-auto rounded-xl border border-slate-200 bg-white p-1 shadow-sm">
    @foreach ($tabs as $tab)
        @php($active = request()->routeIs($tab['active']))
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
