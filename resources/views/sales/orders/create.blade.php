<x-layouts.app title="Pesanan Baru">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('sales.orders.index') }}" class="hover:text-slate-700">Pesanan</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Baru</span>
    </x-slot:breadcrumb>

    <x-page-header title="Pesanan Baru" description="Penjualan di luar marketplace: WhatsApp, toko offline, reseller. Pesanan Shopee masuk otomatis." icon="heroicon-o-shopping-cart">
        <a href="{{ route('sales.orders.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    <form method="POST" action="{{ route('sales.orders.store') }}" data-dirty-guard>
        @csrf
        @include('sales.orders._form')
        @include('sales.orders._actions')
    </form>
</x-layouts.app>
