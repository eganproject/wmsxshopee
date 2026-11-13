@php
    $outbound = $order->isOutbound();
    $listUrl = $outbound ? route('sales.orders.index', ['kind' => 'outbound']) : route('sales.orders.index');
    $title = $outbound ? 'Barang Keluar Manual Baru' : 'Pesanan Baru';
@endphp

<x-layouts.app :title="$title">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ $listUrl }}" class="hover:text-slate-700">Pesanan</a>
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

    {{-- Satu formulir untuk dua jenis: penjualan, atau barang keluar tanpa penjualan. Jenis tidak bisa diubah setelah disimpan. --}}
    @php($kindTab = 'flex flex-1 items-start gap-3 rounded-lg border px-4 py-3 text-left transition sm:flex-none sm:min-w-72')
    <div class="mb-4 flex flex-col gap-2 sm:flex-row" role="tablist" aria-label="Jenis pesanan" data-testid="kind-switch">
        <a href="{{ route('sales.orders.create') }}" role="tab" aria-selected="{{ $outbound ? 'false' : 'true' }}"
           @class([$kindTab, 'border-brand-500 bg-brand-50 ring-1 ring-brand-500' => ! $outbound, 'border-slate-200 bg-white hover:border-slate-300' => $outbound])>
            <x-heroicon-o-shopping-cart @class(['mt-0.5 size-5 shrink-0', 'text-brand-600' => ! $outbound, 'text-slate-400' => $outbound]) />
            <span>
                <span class="block text-sm font-medium text-slate-900">Penjualan</span>
                <span class="block text-xs text-slate-500">WhatsApp, toko offline, reseller. Ada harga & pembayaran.</span>
            </span>
        </a>
        <a href="{{ route('sales.orders.create', ['kind' => 'outbound']) }}" role="tab" aria-selected="{{ $outbound ? 'true' : 'false' }}"
           @class([$kindTab, 'border-brand-500 bg-brand-50 ring-1 ring-brand-500' => $outbound, 'border-slate-200 bg-white hover:border-slate-300' => ! $outbound])>
            <x-heroicon-o-arrow-up-tray @class(['mt-0.5 size-5 shrink-0', 'text-brand-600' => $outbound, 'text-slate-400' => ! $outbound]) />
            <span>
                <span class="block text-sm font-medium text-slate-900">Barang Keluar Manual</span>
                <span class="block text-xs text-slate-500">Sampel, endorse, kirim ke kantor, hadiah. Tanpa harga, nomor BKM.</span>
            </span>
        </a>
    </div>

    <form method="POST" action="{{ route('sales.orders.store') }}" data-dirty-guard>
        @csrf
        @include('sales.orders._form')
        @include('sales.orders._actions')
    </form>
</x-layouts.app>
