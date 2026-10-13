<x-layouts.app title="Retur Penjualan Baru">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('sales.returns.index') }}" class="hover:text-slate-700">Retur Penjualan</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Baru</span>
    </x-slot:breadcrumb>

    <x-page-header title="Retur Penjualan Baru" description="Scan resi paket retur, cek kondisi tiap barang, lalu terima." icon="heroicon-o-arrow-uturn-left">
        <a href="{{ route('sales.returns.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    <form method="POST" action="{{ route('sales.returns.store') }}" enctype="multipart/form-data">
        @csrf
        @include('sales.returns._form')
        @include('sales.returns._actions')
    </form>
</x-layouts.app>
