{{-- Tombol "Alokasikan" dengan pilihan jenis dokumen barang rusak sesuai hak akses. --}}
@props(['params' => [], 'label' => 'Catat Dokumen'])

@php
    $options = collect(\App\Models\DamagedDocument::TYPES)->filter(fn ($meta) => auth()->user()->can($meta['permission']));
@endphp

@if ($options->isNotEmpty())
    <div class="relative" x-data="{ open: false }" @click.outside="open = false">
        <button type="button" class="btn-primary" @click="open = !open">
            <x-heroicon-o-plus class="size-5" /> {{ $label }} <x-heroicon-o-chevron-down class="size-4" />
        </button>
        <div x-cloak x-show="open" x-transition.origin.top.right
             class="absolute right-0 z-20 mt-2 w-60 overflow-hidden rounded-xl border border-slate-200 bg-white p-1.5 text-left shadow-lg">
            @foreach ($options as $type => $meta)
                <a href="{{ route('inventory.damaged.documents.create', ['type' => $type, ...$params]) }}"
                   class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-100">
                    <x-dynamic-component :component="$meta['icon']" class="size-5 text-slate-400" /> {{ $meta['label'] }}
                </a>
            @endforeach
        </div>
    </div>
@endif
