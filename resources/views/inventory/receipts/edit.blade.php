<x-layouts.app :title="'Edit '.$receipt->number">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('inventory.receipts.index') }}" class="hover:text-slate-700">Penerimaan Barang</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('inventory.receipts.show', $receipt) }}" class="hover:text-slate-700">{{ $receipt->number }}</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Edit</span>
    </x-slot:breadcrumb>

    <x-page-header :title="'Edit '.$receipt->number" description="Draft masih bisa diubah sampai dikonfirmasi diterima." icon="heroicon-o-pencil-square">
        <a href="{{ route('inventory.receipts.show', $receipt) }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    <form method="POST" action="{{ route('inventory.receipts.update', $receipt) }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')
        @include('inventory.receipts._form')
        @include('inventory.receipts._actions')
    </form>
</x-layouts.app>
