<x-layouts.app title="Edit Lokasi Bin">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('master.locations.index') }}" class="hover:text-slate-700">Lokasi Bin</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Edit Lokasi Bin</span>
    </x-slot:breadcrumb>

    <x-page-header title="Edit Lokasi Bin" :description="$location->warehouse->code.' · '.$location->code" icon="heroicon-o-pencil-square">
        <a href="{{ route('master.locations.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    <form method="POST" action="{{ route('master.locations.update', $location) }}" data-dirty-guard class="max-w-3xl">
        @csrf
        @method('PUT')
        @include('master.locations._form')

        <div class="sticky bottom-0 mt-6 flex justify-end gap-2 border-t border-slate-200 bg-white/90 py-3 backdrop-blur">
            <a href="{{ route('master.locations.index') }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary"><x-heroicon-o-check class="size-5" /> Simpan Perubahan</button>
        </div>
    </form>

    <x-activity.history :subject="$location" class="max-w-3xl" />
</x-layouts.app>
