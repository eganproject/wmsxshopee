{{-- Tombol "Catat Mutasi" dengan pilihan jenis sesuai hak akses --}}
@php
    $options = collect(\App\Models\StockMovement::TYPES)
        ->filter(fn ($meta, $type) => auth()->user()->can($type === 'adjustment' ? 'inventory.adjust' : 'inventory.create'));
@endphp

@if ($options->isNotEmpty())
    <div class="relative" x-data="{ open: false }" @click.outside="open = false">
        <button type="button" class="btn-primary" @click="open = !open">
            <x-heroicon-o-plus class="size-5" /> Catat Mutasi <x-heroicon-o-chevron-down class="size-4" />
        </button>
        <div x-cloak x-show="open" x-transition.origin.top.right
             class="absolute right-0 z-20 mt-2 w-56 overflow-hidden rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg">
            @foreach ($options as $type => $meta)
                <a href="{{ route('inventory.movements.create', ['type' => $type]) }}"
                   class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-100">
                    <x-dynamic-component :component="$meta['icon']" class="size-5 text-slate-400" /> {{ $meta['label'] }}
                </a>
            @endforeach
            @can('inventory.create')
                <div class="my-1 border-t border-slate-100"></div>
                <a href="{{ route('inventory.opening-stock.create') }}"
                   class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-100">
                    <x-heroicon-o-arrow-up-tray class="size-5 text-slate-400" /> Impor Stok Awal (Excel)
                </a>
            @endcan
        </div>
    </div>
@endif
