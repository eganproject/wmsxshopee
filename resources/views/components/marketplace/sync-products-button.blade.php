@php
    $lastRun = \App\Services\MarketplaceProductSync::lastRunAt();
    $title = 'Tarik produk dari semua toko Shopee & TikTok, cocokkan SKU otomatis, lalu kirim stok. Otomatis tiap jam'
        .($lastRun ? ' · terakhir '.$lastRun->diffForHumans() : '').'.';
@endphp

@if (auth()->user()->can('shopee.mapping') || auth()->user()->can('tiktok.mapping'))
    <form method="POST" action="{{ route('marketplace.sync-products') }}" x-data="{ busy: false }" @submit="busy = true" @pageshow.window="busy = false">
        @csrf
        <button type="submit" class="btn-primary" title="{{ $title }}" :disabled="busy">
            <span class="inline-flex" :class="busy && 'animate-spin'"><x-heroicon-o-arrow-path class="size-5" /></span>
            <span x-text="busy ? 'Menyinkron...' : 'Sinkron Produk'">Sinkron Produk</span>
        </button>
    </form>
@endif
