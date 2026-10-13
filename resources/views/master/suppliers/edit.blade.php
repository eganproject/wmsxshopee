<x-layouts.app title="Edit Supplier">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('master.suppliers.index') }}" class="hover:text-slate-700">Master Supplier</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Edit Supplier</span>
    </x-slot:breadcrumb>

    <x-page-header title="Edit Supplier" :description="$supplier->code.' · '.$supplier->name" icon="heroicon-o-pencil-square">
        <a href="{{ route('master.suppliers.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    <form method="POST" action="{{ route('master.suppliers.update', $supplier) }}" class="max-w-4xl">
        @csrf
        @method('PUT')
        @include('master.suppliers._form')

        <div class="sticky bottom-0 mt-6 flex justify-end gap-2 border-t border-slate-200 bg-white/90 py-3 backdrop-blur">
            <a href="{{ route('master.suppliers.index') }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary"><x-heroicon-o-check class="size-5" /> Simpan Perubahan</button>
        </div>
    </form>

    <x-activity.history :subject="$supplier" class="max-w-4xl" />
</x-layouts.app>
