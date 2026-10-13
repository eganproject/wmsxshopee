<x-layouts.app title="Edit Zona">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('master.zones.index') }}" class="hover:text-slate-700">Zona</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Edit Zona</span>
    </x-slot:breadcrumb>

    <x-page-header title="Edit Zona" :description="$zone->warehouse->code.' · '.$zone->code.' · '.$zone->name" icon="heroicon-o-pencil-square">
        <a href="{{ route('master.zones.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    <form method="POST" action="{{ route('master.zones.update', $zone) }}" class="max-w-3xl">
        @csrf
        @method('PUT')
        @include('master.zones._form')

        <div class="sticky bottom-0 mt-6 flex justify-end gap-2 border-t border-slate-200 bg-white/90 py-3 backdrop-blur">
            <a href="{{ route('master.zones.index') }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary"><x-heroicon-o-check class="size-5" /> Simpan Perubahan</button>
        </div>
    </form>
</x-layouts.app>
