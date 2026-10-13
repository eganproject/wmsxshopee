{{-- Navigasi antar halaman inventori: Stok → Kartu Stok → Mutasi Stok --}}
@php
    $tabs = [
        ['label' => 'Stok', 'route' => 'inventory.stocks.index', 'active' => 'inventory.stocks.*', 'icon' => 'heroicon-o-archive-box'],
        ['label' => 'Kartu Stok', 'route' => 'inventory.stock-card.index', 'active' => 'inventory.stock-card.*', 'icon' => 'heroicon-o-document-chart-bar'],
        ['label' => 'Mutasi Stok', 'route' => 'inventory.movements.index', 'active' => 'inventory.movements.*', 'icon' => 'heroicon-o-arrows-right-left'],
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
