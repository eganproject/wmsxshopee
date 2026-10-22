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
        @if ($returnOrder)
            <input type="hidden" name="return_order" value="{{ $returnOrder->id }}">
            <div class="mb-5 flex items-start gap-3 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
                <x-heroicon-o-information-circle class="mt-0.5 size-5 shrink-0" />
                <p>Produk untuk pesanan <a href="{{ route('sales.orders.show', $returnOrder) }}" class="font-medium underline">{{ $returnOrder->number }}</a>. Biarkan SKU sama dengan SKU marketplace supaya pesanan langsung terhubung; setelah disimpan Anda kembali ke pesanan.</p>
            </div>
        @endif
        @include('master.products._form')

        <div class="sticky bottom-0 mt-6 flex justify-end gap-3 border-t border-slate-200 bg-slate-50/90 py-4 backdrop-blur">
            <a href="{{ route('master.products.index') }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary"><x-heroicon-o-check class="size-5" /> Simpan Produk</button>
        </div>
    </form>
</x-layouts.app>
