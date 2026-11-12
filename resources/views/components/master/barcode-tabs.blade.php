{{-- Sub-tab halaman Barcode: Barcode Alias → Barcode Tidak Dikenali --}}
@props(['openMisses' => 0])

<nav class="mb-5 flex gap-5 overflow-x-auto border-b border-slate-200">
    @foreach ([['label' => 'Barcode Alias', 'route' => 'master.barcodes.index'], ['label' => 'Barcode Tidak Dikenali', 'route' => 'master.barcodes.misses']] as $tab)
        @php($active = request()->routeIs($tab['route']))
        <a href="{{ route($tab['route']) }}"
           @class([
               '-mb-px flex items-center gap-1.5 border-b-[1.5px] pt-1 pb-2.5 text-sm whitespace-nowrap transition',
               'border-slate-900 text-slate-900' => $active,
               'border-transparent text-slate-500 hover:text-slate-900' => ! $active,
           ])>
            {{ $tab['label'] }}
            @if ($tab['route'] === 'master.barcodes.misses' && $openMisses > 0)
                <span class="rounded-full bg-rose-100 px-1.5 text-xs font-medium text-rose-700">{{ number_format($openMisses, 0, ',', '.') }}</span>
            @endif
        </a>
    @endforeach
</nav>
