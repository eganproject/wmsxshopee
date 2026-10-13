<x-layouts.app title="Edit Produk Paket">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('master.bundles.index') }}" class="hover:text-slate-700">Produk Paket</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Edit Paket</span>
    </x-slot:breadcrumb>

    <x-page-header title="Edit Produk Paket" :description="$bundle->sku.' · '.$bundle->name" icon="heroicon-o-pencil-square">
        <a href="{{ route('master.bundles.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    <form method="POST" action="{{ route('master.bundles.update', $bundle) }}" data-dirty-guard class="mx-auto max-w-4xl">
        @csrf
        @method('PUT')
        @include('master.bundles._form')

        <div class="sticky bottom-0 mt-6 flex justify-end gap-3 border-t border-slate-200 bg-slate-50/90 py-4 backdrop-blur">
            <a href="{{ route('master.bundles.index') }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary"><x-heroicon-o-check class="size-5" /> Simpan Perubahan</button>
        </div>
    </form>
</x-layouts.app>
