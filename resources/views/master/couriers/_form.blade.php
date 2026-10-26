@php
    $services = old('services', $courier->services ?? []);
@endphp

<div class="space-y-6">
    <div class="card">
        <div class="border-b border-slate-100 px-5 py-3">
            <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                Informasi Kurir
            </h2>
        </div>
        <div class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2">
            <x-input name="code" label="Kode Kurir" tip="Kode singkat kurir, misalnya JNE atau SPX. Dipakai untuk filter dan mencocokkan kurir dari pesanan marketplace." icon="heroicon-o-hashtag" :value="$courier->code" placeholder="cth. JNE" class="[&_input]:uppercase" required />
            <x-input name="name" label="Nama Kurir" :value="$courier->name" placeholder="cth. JNE Express" required />
            <x-input name="tracking_url" label="URL Lacak Resi" tip="Alamat halaman lacak kurir. Tulis {resi} di tempat nomor resi, nanti nomor resi di pesanan bisa diklik langsung ke halaman lacak." icon="heroicon-o-link" :value="$courier->tracking_url" class="sm:col-span-2"
                     placeholder="https://contoh.com/lacak?resi={resi}" hint="Gunakan {resi} sebagai tempat nomor resi." />
            <x-input name="pickup_cutoff" type="time" tip="Jam terakhir kurir biasanya menjemput paket. Patokan untuk tim gudang: pesanan yang masuk setelah jam ini umumnya baru dikirim besok." label="Batas Jam Pickup" :value="$courier->pickup_cutoff ? substr($courier->pickup_cutoff, 0, 5) : null"
                     hint="Pesanan setelah jam ini dikirim besok." />
            <x-input name="shopee_logistics_channel_id" label="ID Channel Logistik Shopee" tip="ID logistik dari Shopee (logistics_channel_id) untuk kurir ini, supaya pesanan Shopee otomatis tercatat dengan kurir yang tepat." :value="$courier->shopee_logistics_channel_id"
                     placeholder="opsional" hint="Untuk pemetaan saat integrasi API Shopee." />
        </div>
    </div>

    {{-- Layanan --}}
    <div class="card" x-data="{ services: @js(array_values($services)) }">
        <div class="flex items-center justify-between gap-4 border-b border-slate-100 px-5 py-3">
            <div>
                <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                    Jenis Layanan
                </h2>
                <p class="mt-0.5 text-sm text-slate-500">cth. REG (Reguler), YES (Yakin Esok Sampai), CARGO.</p>
            </div>
            <button type="button" class="btn-secondary" @click="services.push({ code: '', name: '' })">
                <x-heroicon-o-plus class="size-5" /> Tambah
            </button>
        </div>
        <div class="space-y-3 p-5">
            @error('services')
                <p class="flex items-center gap-1 text-sm text-rose-600"><x-heroicon-o-exclamation-triangle class="size-4" /> {{ $message }}</p>
            @enderror
            @foreach ($errors->get('services.*') as $messages)
                <p class="flex items-center gap-1 text-sm text-rose-600"><x-heroicon-o-exclamation-triangle class="size-4" /> {{ $messages[0] }}</p>
            @endforeach

            <template x-for="(service, index) in services" :key="index">
                <div class="flex items-center gap-3">
                    <input type="text" class="form-input w-32 uppercase" placeholder="Kode" :name="`services[${index}][code]`" x-model="service.code">
                    <input type="text" class="form-input flex-1" placeholder="Nama layanan" :name="`services[${index}][name]`" x-model="service.name">
                    <button type="button" class="btn-icon hover:bg-rose-50 hover:text-rose-600" title="Hapus layanan" @click="services.splice(index, 1)">
                        <x-heroicon-o-trash class="size-5" />
                    </button>
                </div>
            </template>
            <p x-show="services.length === 0" class="text-sm text-slate-500">Belum ada layanan.</p>
        </div>
    </div>

    <div class="card">
        <div class="border-b border-slate-100 px-5 py-3">
            <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                Kontak Pickup
            </h2>
        </div>
        <div class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2">
            <x-input name="contact_name" label="Nama Kontak" icon="heroicon-o-user" :value="$courier->contact_name" />
            <x-input name="contact_phone" label="No. Telepon" icon="heroicon-o-phone" :value="$courier->contact_phone" placeholder="08xxxxxxxxxx" />
            <x-textarea name="notes" label="Catatan" :value="$courier->notes" class="sm:col-span-2" />
        </div>
    </div>

    <x-active-toggle :checked="$courier->is_active" title="Status Kurir" description="Kurir nonaktif tidak muncul sebagai pilihan pengiriman." />
</div>
