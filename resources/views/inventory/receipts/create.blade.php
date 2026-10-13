<x-layouts.app title="Penerimaan Barang Baru">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('inventory.receipts.index') }}" class="hover:text-slate-700">Penerimaan Barang</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Baru</span>
    </x-slot:breadcrumb>

    <x-page-header title="Penerimaan Barang Baru" description="Catat barang yang datang dari supplier, lalu simpan ke lokasi bin." icon="heroicon-o-inbox-arrow-down">
        <a href="{{ route('inventory.receipts.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    <form method="POST" action="{{ route('inventory.receipts.store') }}" data-dirty-guard enctype="multipart/form-data">
        @csrf
        @include('inventory.receipts._form')
        @include('inventory.receipts._actions')
    </form>
</x-layouts.app>
