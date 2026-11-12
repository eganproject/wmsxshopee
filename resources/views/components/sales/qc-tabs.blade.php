{{-- Navigasi halaman QC Kemas: Antrean → Substitusi SKU → Scan Resi Ganda → SKU Tanpa Scan QC --}}
@php
    $tabs = [
        ['label' => 'Antrean QC', 'route' => 'sales.qc.index', 'active' => ['sales.qc.index', 'sales.qc.show'], 'icon' => 'heroicon-o-viewfinder-circle'],
        ['label' => 'Substitusi SKU', 'route' => 'sales.qc.substitutions', 'active' => 'sales.qc.substitutions', 'icon' => 'heroicon-o-arrows-right-left'],
        ['label' => 'Scan Resi Ganda', 'route' => 'sales.qc.duplicates', 'active' => 'sales.qc.duplicates', 'icon' => 'heroicon-o-document-duplicate'],
        ['label' => 'SKU Tanpa Scan', 'route' => 'sales.qc.exempt', 'active' => 'sales.qc.exempt', 'icon' => 'heroicon-o-no-symbol'],
    ];
@endphp

<nav class="mb-6 flex gap-1 overflow-x-auto rounded-xl border border-slate-200 bg-white p-1 shadow-sm">
    @foreach ($tabs as $tab)
        @php($active = request()->routeIs(...(array) $tab['active']))
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
