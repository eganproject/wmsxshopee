<div class="space-y-6">
    <div class="card">
        <div class="border-b border-slate-100 px-6 py-4">
            <h2 class="flex items-center gap-2 font-semibold text-slate-900">
                <x-heroicon-o-building-office-2 class="size-5 text-slate-400" /> Informasi Gudang
            </h2>
        </div>
        <div class="grid gap-5 p-6 sm:grid-cols-2">
            <x-input name="code" label="Kode Gudang" icon="heroicon-o-hashtag" :value="$warehouse->code" placeholder="cth. JKT01"
                     hint="Huruf, angka, - dan _. Otomatis huruf kapital." class="uppercase" required />
            <x-input name="name" label="Nama Gudang" :value="$warehouse->name" placeholder="cth. Gudang Utama Jakarta" required />
            <x-input name="pic_name" label="Penanggung Jawab" icon="heroicon-o-user" :value="$warehouse->pic_name" placeholder="cth. Budi Santoso" />
            <x-input name="phone" label="No. Telepon" icon="heroicon-o-phone" :value="$warehouse->phone" placeholder="08xxxxxxxxxx" />
        </div>
    </div>

    <div class="card">
        <div class="border-b border-slate-100 px-6 py-4">
            <h2 class="flex items-center gap-2 font-semibold text-slate-900">
                <x-heroicon-o-map class="size-5 text-slate-400" /> Alamat
            </h2>
            <p class="mt-0.5 text-sm text-slate-500">Dipakai sebagai alamat pickup kurir.</p>
        </div>
        <div class="grid gap-5 p-6 sm:grid-cols-3">
            <x-textarea name="address" label="Alamat Lengkap" :value="$warehouse->address" class="sm:col-span-3" />
            <x-input name="city" label="Kota / Kabupaten" :value="$warehouse->city" />
            <x-input name="province" label="Provinsi" :value="$warehouse->province" />
            <x-input name="postal_code" label="Kode Pos" :value="$warehouse->postal_code" inputmode="numeric" />
        </div>
    </div>

    <x-active-toggle :checked="$warehouse->is_active" title="Status Gudang" description="Gudang nonaktif tidak bisa dipakai untuk transaksi baru." />

    <div class="card p-6">
        <x-textarea name="notes" label="Catatan" :value="$warehouse->notes" />
    </div>
</div>
