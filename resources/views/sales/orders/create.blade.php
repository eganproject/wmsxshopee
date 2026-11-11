@php
    $outbound = $order->isOutbound();
    $listUrl = $outbound ? route('sales.orders.index', ['kind' => 'outbound']) : route('sales.orders.index');
    $title = $outbound ? 'Barang Keluar Manual Baru' : 'Pesanan Baru';
@endphp

<x-layouts.app :title="$title">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ $listUrl }}" class="hover:text-slate-700">{{ $outbound ? 'Barang Keluar Manual' : 'Pesanan' }}</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Baru</span>
    </x-slot:breadcrumb>

    <x-page-header :title="$title"
                   :description="$outbound
                       ? 'Barang keluar tanpa penjualan: sampel, endorse, kirim ke kantor, hadiah. Tetap lewat picking, QC Kemas, lalu serah terima sebelum stok berkurang.'
                       : 'Penjualan di luar marketplace: WhatsApp, toko offline, reseller. Pesanan Shopee masuk otomatis.'"
                   :icon="$outbound ? 'heroicon-o-arrow-up-tray' : 'heroicon-o-shopping-cart'">
        <a href="{{ $listUrl }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    <form method="POST" action="{{ route('sales.orders.store') }}" data-dirty-guard>
        @csrf
        @include('sales.orders._form')
        @include('sales.orders._actions')
    </form>
</x-layouts.app>
