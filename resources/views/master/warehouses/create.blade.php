<x-layouts.app title="Tambah Gudang">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('master.warehouses.index') }}" class="hover:text-slate-700">Master Gudang</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Tambah Gudang</span>
    </x-slot:breadcrumb>

    <x-page-header title="Tambah Gudang Baru" description="Isi data gudang baru." icon="heroicon-o-plus">
        <a href="{{ route('master.warehouses.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    <form method="POST" action="{{ route('master.warehouses.store') }}" class="max-w-4xl">
        @csrf
        @include('master.warehouses._form')

        <div class="sticky bottom-0 mt-6 flex justify-end gap-2 border-t border-slate-200 bg-white/90 py-3 backdrop-blur">
            <a href="{{ route('master.warehouses.index') }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary"><x-heroicon-o-check class="size-5" /> Simpan Gudang</button>
        </div>
    </form>
</x-layouts.app>
