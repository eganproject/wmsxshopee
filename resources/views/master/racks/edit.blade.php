<x-layouts.app title="Edit Rak">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('master.racks.index') }}" class="hover:text-slate-700">Rak</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Edit Rak</span>
    </x-slot:breadcrumb>

    <x-page-header title="Edit Rak" :description="$rack->zone->warehouse->code.' · '.$rack->zone->code.' · Rak '.$rack->code" icon="heroicon-o-pencil-square">
        <a href="{{ route('master.racks.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    <form method="POST" action="{{ route('master.racks.update', $rack) }}" class="max-w-3xl">
        @csrf
        @method('PUT')
        @include('master.racks._form')

        <div class="sticky bottom-0 mt-6 flex justify-end gap-2 border-t border-slate-200 bg-white/90 py-3 backdrop-blur">
            <a href="{{ route('master.racks.index') }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary"><x-heroicon-o-check class="size-5" /> Simpan Perubahan</button>
        </div>
    </form>
</x-layouts.app>
