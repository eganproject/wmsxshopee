<div class="space-y-6">
    <div class="card">
        <div class="border-b border-slate-100 px-5 py-3">
            <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                Informasi Gudang
            </h2>
        </div>
        <div class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2">
            <x-input name="code" label="Kode Gudang" tip="Kode singkat yang muncul di dokumen, filter dan nomor transaksi. Sebaiknya tidak diganti setelah dipakai." icon="heroicon-o-hashtag" :value="$warehouse->code" placeholder="cth. JKT01"
                     hint="Huruf, angka, - dan _. Otomatis huruf kapital." class="[&_input]:uppercase" required />
            <x-input name="name" label="Nama Gudang" :value="$warehouse->name" placeholder="cth. Gudang Utama Jakarta" required />
            <x-input name="pic_name" label="Penanggung Jawab" tip="Orang yang bertanggung jawab atas gudang ini. Hanya sebagai informasi kontak." icon="heroicon-o-user" :value="$warehouse->pic_name" placeholder="cth. Budi Santoso" />
            <x-input name="phone" label="No. Telepon" icon="heroicon-o-phone" :value="$warehouse->phone" placeholder="08xxxxxxxxxx" />
        </div>
    </div>

    <div class="card">
        <div class="border-b border-slate-100 px-5 py-3">
            <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                Alamat
            </h2>
            <p class="mt-0.5 text-sm text-slate-500">Dipakai sebagai alamat pickup kurir.</p>
        </div>
        <div class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-3">
            <x-textarea name="address" label="Alamat Lengkap" :value="$warehouse->address" class="sm:col-span-3" />
            <x-input name="city" label="Kota / Kabupaten" :value="$warehouse->city" />
            <x-input name="province" label="Provinsi" :value="$warehouse->province" />
            <x-input name="postal_code" label="Kode Pos" :value="$warehouse->postal_code" inputmode="numeric" />
        </div>
    </div>

    <div class="card">
        <div class="border-b border-slate-100 px-5 py-3">
            <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                Gudang Cadangan (Gudang Besar)
            </h2>
            <p class="mt-0.5 text-sm text-slate-500">Isi bila gudang ini gudang kecil untuk proses pesanan dan punya gudang besar sebagai stok cadangan. Pesanan tetap diambil dari gudang ini, tapi stok yang dikirim ke Shopee/TikTok = stok gudang ini + stok gudang cadangan + yang sedang dalam perjalanan. Kosongkan untuk gudang biasa.</p>
        </div>
        <div class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2">
            <x-select name="supply_warehouse_id" label="Gudang Cadangan" tip="Gudang besar yang menyimpan stok cadangan untuk gudang ini. Pesanan tetap diambil dari gudang ini, tapi stok yang dikirim ke Shopee / TikTok ikut menghitung stok gudang cadangan. Kekurangan diisi lewat Transfer Antar Gudang.">
                <option value="">Tidak ada (gudang biasa)</option>
                @foreach ($supplyOptions as $option)
                    <option value="{{ $option->id }}" @selected(old('supply_warehouse_id', $warehouse->supply_warehouse_id) == $option->id)>{{ $option->code }} · {{ $option->name }}{{ $option->is_active ? '' : ' (nonaktif)' }}</option>
                @endforeach
            </x-select>
            <x-input name="supply_stock_percent" type="number" tip="Persentase stok gudang cadangan yang ikut dijual di marketplace. Pakai angka di bawah 100 sebagai pengaman supaya tidak oversell selama barang belum ditransfer." min="0" max="100" label="Stok Cadangan yang Dijual (%)" :value="$warehouse->supply_stock_percent ?? 100"
                     hint="Buffer aman. Contoh 90 = hanya 90% stok gudang cadangan yang ikut dijual." />
        </div>
    </div>

    <x-active-toggle :checked="$warehouse->is_active" title="Status Gudang" description="Gudang nonaktif tidak bisa dipakai untuk transaksi baru." />

    <div class="card p-5">
        <x-textarea name="notes" label="Catatan" :value="$warehouse->notes" />
    </div>
</div>
