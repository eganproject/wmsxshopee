<x-layouts.app :title="'Edit '.$return->number">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('sales.returns.index') }}" class="hover:text-slate-700">Retur Penjualan</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('sales.returns.show', $return) }}" class="hover:text-slate-700">{{ $return->number }}</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Edit</span>
    </x-slot:breadcrumb>

    <x-page-header :title="'Edit '.$return->number" description="Draft masih bisa diubah sampai retur diterima." icon="heroicon-o-pencil-square">
        <a href="{{ route('sales.returns.show', $return) }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    <form method="POST" action="{{ route('sales.returns.update', $return) }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')
        @include('sales.returns._form')
        @include('sales.returns._actions')
    </form>
</x-layouts.app>
