<x-layouts.app title="Konfirmasi Toko TikTok">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('marketplace.shops', ['platform' => 'tiktok']) }}" class="hover:text-slate-700">Toko Marketplace</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Konfirmasi TikTok</span>
    </x-slot:breadcrumb>

    <x-page-header title="Hubungkan toko TikTok" description="TikTok sudah menyetujui akses Optima. Pilih gudang pengirim lalu simpan supaya tokonya tercatat di Optima." icon="heroicon-o-link" />

    <div class="card max-w-2xl">
        <form method="POST" action="{{ route('tiktok.callback.confirm') }}" class="flex flex-col gap-4 p-4">
            @csrf
            <p class="text-sm text-slate-600">
                Otorisasi ini datang langsung dari TikTok (bukan dari tombol Hubungkan Toko di Optima), jadi perlu dikonfirmasi dulu.
                Semua toko di akun Seller Center itu akan terhubung. Kode otorisasi berlaku sekitar 10 menit.
            </p>
            <x-select name="warehouse_id" label="Gudang pengirim" tip="Gudang yang memproses pesanan toko ini." class="md:w-80" required>
                <option value="">Pilih gudang</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected(old('warehouse_id', $warehouseId) == $warehouse->id || $warehouses->count() === 1)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>
                @endforeach
            </x-select>
            <div class="flex gap-2">
                <button type="submit" class="btn-primary"><x-heroicon-o-check class="size-5" /> Simpan Toko</button>
                <a href="{{ route('marketplace.shops', ['platform' => 'tiktok']) }}" class="btn-secondary">Batal</a>
            </div>
        </form>
    </div>
</x-layouts.app>
