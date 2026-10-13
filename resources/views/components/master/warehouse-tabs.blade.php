{{-- Navigasi antar master struktur gudang: Gudang → Zona → Rak → Lokasi Bin --}}
@php
    $tabs = [
        ['label' => 'Gudang', 'route' => 'master.warehouses.index', 'active' => 'master.warehouses.*'],
        ['label' => 'Zona', 'route' => 'master.zones.index', 'active' => 'master.zones.*'],
        ['label' => 'Rak', 'route' => 'master.racks.index', 'active' => 'master.racks.*'],
        ['label' => 'Lokasi Bin', 'route' => 'master.locations.index', 'active' => 'master.locations.*'],
    ];
@endphp

<nav class="mb-5 flex gap-5 overflow-x-auto border-b border-slate-200">
    @foreach ($tabs as $tab)
        @php($active = request()->routeIs($tab['active']))
        <a href="{{ route($tab['route']) }}"
           @class([
               '-mb-px border-b-[1.5px] pt-1 pb-2.5 text-sm whitespace-nowrap transition',
               'border-slate-900 text-slate-900' => $active,
               'border-transparent text-slate-500 hover:text-slate-900' => ! $active,
           ])>
            {{ $tab['label'] }}
        </a>
    @endforeach
</nav>
