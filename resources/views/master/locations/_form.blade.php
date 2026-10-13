<div class="space-y-6">
    <div class="card">
        <div class="border-b border-slate-100 px-6 py-4">
            <h2 class="flex items-center gap-2 font-semibold text-slate-900">
                <x-heroicon-o-map-pin class="size-5 text-slate-400" /> Informasi Lokasi
            </h2>
            <p class="mt-0.5 text-sm text-slate-500">Lokasi bin adalah titik terkecil penyimpanan barang yang di-scan saat put-away dan picking.</p>
        </div>
        <div class="grid gap-5 p-6 sm:grid-cols-2">
            <x-select name="warehouse_rack_id" label="Rak" class="sm:col-span-2" required>
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
            <x-input name="code" label="Kode Lokasi" icon="heroicon-o-qr-code" :value="$location->code" placeholder="cth. A01-02-03" class="uppercase sm:col-span-2"
                     hint="Unik per gudang. Kode ini yang dicetak sebagai label barcode." required />
            <x-input name="level" type="number" label="Level" :value="$location->level" min="1" />
            <x-input name="position" type="number" label="Posisi" :value="$location->position" min="1" />
            <x-select name="type" label="Jenis Lokasi" required>
                @foreach ($types as $value => $label)
                    <option value="{{ $value }}" @selected(old('type', $location->type) === $value)>{{ $label }}</option>
                @endforeach
            </x-select>
            <div></div>
            <x-input name="max_qty" type="number" label="Kapasitas Maks. (pcs)" :value="$location->max_qty" min="0" placeholder="opsional" />
            <x-input name="max_weight_kg" type="number" step="0.01" label="Berat Maks. (kg)" :value="$location->max_weight_kg" min="0" placeholder="opsional" />
        </div>
    </div>

    <x-active-toggle :checked="$location->is_active" title="Status Lokasi" description="Lokasi nonaktif tidak bisa dipakai untuk penempatan barang baru." />
</div>
