<div class="space-y-6">
    <div class="card">
        <div class="border-b border-slate-100 px-6 py-4">
            <h2 class="flex items-center gap-2 font-semibold text-slate-900">
                <x-heroicon-o-scale class="size-5 text-slate-400" /> Informasi Satuan
            </h2>
        </div>
        <div class="grid gap-5 p-6 sm:grid-cols-2">
            <x-input name="code" label="Kode Satuan" icon="heroicon-o-hashtag" :value="$unit->code" placeholder="cth. PCS"
                     hint="Huruf, angka, - dan _. Otomatis huruf kapital." class="uppercase" required />
            <x-input name="name" label="Nama Satuan" :value="$unit->name" placeholder="cth. Pieces" required />
        </div>
    </div>

    <x-active-toggle :checked="$unit->is_active" title="Status Satuan" description="Satuan nonaktif tidak muncul sebagai pilihan di form produk." />
</div>
