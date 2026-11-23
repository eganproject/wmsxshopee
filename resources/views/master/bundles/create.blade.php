<x-layouts.app title="Tambah Produk Paket">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('master.bundles.index') }}" class="hover:text-slate-700">Produk Paket</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Tambah Paket</span>
    </x-slot:breadcrumb>

    <x-page-header title="Tambah Produk Paket" icon="heroicon-o-plus">
        <a href="{{ route('master.bundles.index') }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    @if ($mapping)
        @php($platform = $mapping instanceof \App\Models\ShopeeSkuMapping ? 'shopee' : 'tiktok')
        <div class="mx-auto mb-6 flex max-w-4xl items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <x-heroicon-o-gift class="mt-0.5 size-5 shrink-0 text-amber-500" />
            <p>
                Paket ini dibuat untuk SKU <span class="font-mono">{{ $mapping->shopee_sku ?? $mapping->seller_sku }}</span> di toko
                {{ $platform === 'shopee' ? 'Shopee' : 'TikTok' }} {{ $mapping->shop->displayName() }}. Lengkapi isi paketnya; setelah disimpan, SKU itu otomatis dipetakan ke paket ini.
                <a href="{{ route("{$platform}.mappings.index", [$mapping->shop, 'status' => 'bundle_like']) }}" class="font-medium underline underline-offset-2">Kembali ke Pemetaan SKU</a>
            </p>
        </div>
    @endif

    <form method="POST" action="{{ route('master.bundles.store') }}" data-dirty-guard class="mx-auto max-w-4xl">
        @csrf
        @if (old('map', request('map')))
            <input type="hidden" name="map" value="{{ old('map', request('map')) }}">
        @endif
        @include('master.bundles._form')

        <div class="sticky bottom-0 mt-6 flex justify-end gap-3 border-t border-slate-200 bg-slate-50/90 py-4 backdrop-blur">
            <a href="{{ route('master.bundles.index') }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary"><x-heroicon-o-check class="size-5" /> Simpan Paket</button>
        </div>
    </form>
</x-layouts.app>
