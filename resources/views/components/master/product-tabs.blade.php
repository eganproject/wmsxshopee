{{-- Navigasi antar master produk: Produk → Satuan --}}
@php
    $tabs = [
        ['label' => 'Produk', 'route' => 'master.products.index', 'active' => 'master.products.*', 'icon' => 'heroicon-o-cube'],
        ['label' => 'Satuan', 'route' => 'master.units.index', 'active' => 'master.units.*', 'icon' => 'heroicon-o-scale'],
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
