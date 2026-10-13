<div class="space-y-6">
    <div class="card">
        <div class="border-b border-slate-100 px-5 py-3">
            <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                Informasi Supplier
            </h2>
        </div>
        <div class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2">
            <x-input name="code" label="Kode Supplier" icon="heroicon-o-hashtag" :value="$supplier->code" placeholder="cth. SUP001" class="[&_input]:uppercase" required />
            <x-input name="name" label="Nama Supplier" :value="$supplier->name" placeholder="cth. CV Sumber Textile" required />
            <x-input name="npwp" label="NPWP" :value="$supplier->npwp" placeholder="opsional" />
            <x-input name="payment_term_days" type="number" min="0" max="365" label="Termin Bayar (hari)" icon="heroicon-o-calendar-days"
                     :value="$supplier->payment_term_days" placeholder="cth. 30" hint="Isi 0 untuk tunai / COD." />
        </div>
    </div>

    <div class="card">
        <div class="border-b border-slate-100 px-5 py-3">
            <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                Kontak & Alamat
            </h2>
        </div>
        <div class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2">
            <x-input name="contact_name" label="Nama Kontak" icon="heroicon-o-user" :value="$supplier->contact_name" />
            <x-input name="phone" label="No. Telepon" icon="heroicon-o-phone" :value="$supplier->phone" placeholder="08xxxxxxxxxx" />
            <x-input name="email" type="email" label="Email" icon="heroicon-o-envelope" :value="$supplier->email" />
            <x-input name="city" label="Kota" icon="heroicon-o-map-pin" :value="$supplier->city" />
            <x-textarea name="address" label="Alamat" :value="$supplier->address" rows="2" class="sm:col-span-2" />
            <x-textarea name="notes" label="Catatan" :value="$supplier->notes" class="sm:col-span-2" />
        </div>
    </div>

    <x-active-toggle :checked="$supplier->is_active" title="Status Supplier" description="Supplier nonaktif tidak muncul sebagai pilihan di penerimaan barang." />
</div>
