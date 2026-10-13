<x-layouts.app title="Tambah Produk">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('master.products.index') }}" class="hover:text-slate-700">Master Produk</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Tambah Produk</span>
    </x-slot:breadcrumb>

    <x-page-header title="Tambah Produk" icon="heroicon-o-plus">
        <a href="{{ route('master.products.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    <form method="POST" enctype="multipart/form-data" action="{{ route('master.products.store') }}" data-dirty-guard class="mx-auto max-w-5xl">
        @csrf
        @include('master.products._form')

        <div class="sticky bottom-0 mt-6 flex justify-end gap-3 border-t border-slate-200 bg-slate-50/90 py-4 backdrop-blur">
            <a href="{{ route('master.products.index') }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary"><x-heroicon-o-check class="size-5" /> Simpan Produk</button>
        </div>
    </form>
</x-layouts.app>
