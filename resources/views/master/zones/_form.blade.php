<div class="space-y-6">
    <div class="card">
        <div class="border-b border-slate-100 px-5 py-3">
            <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                Informasi Zona
            </h2>
            <p class="mt-0.5 text-sm text-slate-500">Zona membagi area gudang sesuai fungsinya, misalnya penerimaan, penyimpanan, atau packing.</p>
        </div>
        <div class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2">
            <x-select name="warehouse_id" label="Gudang" class="sm:col-span-2" required>
                <option value="">Pilih gudang</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected(old('warehouse_id', $zone->warehouse_id) == $warehouse->id)>
                        {{ $warehouse->code }} · {{ $warehouse->name }}{{ $warehouse->is_active ? '' : ' (nonaktif)' }}
                    </option>
                @endforeach
            </x-select>
            <x-input name="code" label="Kode Zona" icon="heroicon-o-hashtag" :value="$zone->code" placeholder="cth. A" class="[&_input]:uppercase"
                     hint="Unik di dalam satu gudang." required />
            <x-input name="name" label="Nama Zona" :value="$zone->name" placeholder="cth. Zona Penyimpanan A" required />
            <x-select name="type" label="Jenis Zona" class="sm:col-span-2" required>
                @foreach ($types as $value => $label)
                    <option value="{{ $value }}" @selected(old('type', $zone->type) === $value)>{{ $label }}</option>
                @endforeach
            </x-select>
            <x-textarea name="description" label="Keterangan" :value="$zone->description" class="sm:col-span-2" />
        </div>
    </div>

    <x-active-toggle :checked="$zone->is_active" title="Status Zona" description="Zona nonaktif tidak bisa dipakai untuk penempatan barang baru." />
</div>
