<div class="space-y-6">
    <div class="card">
        <div class="border-b border-slate-100 px-5 py-3">
            <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                Informasi Lokasi
            </h2>
            <p class="mt-0.5 text-sm text-slate-500">Lokasi bin adalah titik terkecil penyimpanan barang yang di-scan saat put-away dan picking.</p>
        </div>
        <div class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2">
            <x-select name="warehouse_rack_id" label="Rak" tip="Rak tempat lokasi bin ini berada. Gudang dan zona mengikuti rak." class="sm:col-span-2" required>
                <option value="">Pilih rak</option>
                @foreach ($racks->groupBy(fn ($rack) => $rack->zone->warehouse->code.' · Zona '.$rack->zone->code) as $groupLabel => $groupRacks)
                    <optgroup label="{{ $groupLabel }}">
                        @foreach ($groupRacks as $rack)
                            <option value="{{ $rack->id }}" @selected(old('warehouse_rack_id', $location->warehouse_rack_id) == $rack->id)>
                                Rak {{ $rack->code }}{{ $rack->name ? ' · '.$rack->name : '' }} ({{ $rack->levels }} level)
                            </option>
                        @endforeach
                    </optgroup>
                @endforeach
            </x-select>
            <x-input name="code" label="Kode Lokasi" tip="Alamat bin dengan format RAK-LEVEL-POSISI, misalnya A01-02-03. Kode ini di-scan saat menaruh dan mengambil barang." icon="heroicon-o-qr-code" :value="$location->code" placeholder="cth. A01-02-03" class="[&_input]:uppercase sm:col-span-2"
                     hint="Unik per gudang. Kode ini yang dicetak sebagai label barcode." required />
            <x-input name="level" type="number" tip="Tingkat rak, dihitung dari bawah (1 = paling bawah)." label="Level" :value="$location->level" min="1" />
            <x-input name="position" type="number" tip="Urutan bin di level tersebut, dihitung dari kiri." label="Posisi" :value="$location->position" min="1" />
            <x-select name="type" label="Jenis Lokasi" tip="Bentuk tempat simpan: Bin (kotak kecil), Shelf (papan rak), Pallet, atau Lantai. Hanya sebagai informasi." required>
                @foreach ($types as $value => $label)
                    <option value="{{ $value }}" @selected(old('type', $location->type) === $value)>{{ $label }}</option>
                @endforeach
            </x-select>
            <div></div>
            <x-input name="max_qty" type="number" tip="Perkiraan jumlah pcs maksimal yang muat. Kosongkan bila tidak dibatasi." label="Kapasitas Maks. (pcs)" :value="$location->max_qty" min="0" placeholder="opsional" />
            <x-input name="max_weight_kg" type="number" tip="Beban maksimal bin dalam kg. Kosongkan bila tidak dibatasi." step="0.01" label="Berat Maks. (kg)" :value="$location->max_weight_kg" min="0" placeholder="opsional" />
        </div>
    </div>

    <x-active-toggle :checked="$location->is_active" title="Status Lokasi" description="Lokasi nonaktif tidak bisa dipakai untuk penempatan barang baru." />
</div>
