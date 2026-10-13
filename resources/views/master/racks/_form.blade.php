<div class="space-y-6" x-data="{ generate: @js((bool) old('generate_locations', ! $rack->exists)), levels: @js((int) old('levels', $rack->levels)), positions: @js((int) old('positions', 5)), code: @js(old('code', $rack->code) ?? '') }">
    <div class="card">
        <div class="border-b border-slate-100 px-6 py-4">
            <h2 class="flex items-center gap-2 font-semibold text-slate-900">
                <x-heroicon-o-view-columns class="size-5 text-slate-400" /> Informasi Rak
            </h2>
        </div>
        <div class="grid gap-5 p-6 sm:grid-cols-2">
            <x-select name="warehouse_zone_id" label="Zona" class="sm:col-span-2" required>
                <option value="">Pilih zona</option>
                @foreach ($zones->groupBy(fn ($zone) => $zone->warehouse->code.' · '.$zone->warehouse->name) as $warehouseLabel => $warehouseZones)
                    <optgroup label="{{ $warehouseLabel }}">
                        @foreach ($warehouseZones as $zone)
                            <option value="{{ $zone->id }}" @selected(old('warehouse_zone_id', $rack->warehouse_zone_id) == $zone->id)>
                                {{ $zone->code }} · {{ $zone->name }}
                            </option>
                        @endforeach
                    </optgroup>
                @endforeach
            </x-select>
            <x-input name="code" label="Kode Rak" icon="heroicon-o-hashtag" :value="$rack->code" placeholder="cth. A01" class="uppercase"
                     hint="Unik per gudang. Dipakai sebagai awalan kode lokasi bin." x-model="code" required />
            <x-input name="name" label="Nama Rak" :value="$rack->name" placeholder="opsional" />
            <x-input name="levels" type="number" label="Jumlah Level (tingkat)" :value="$rack->levels" min="1" max="50" x-model.number="levels" required />
            <x-textarea name="description" label="Keterangan" :value="$rack->description" class="sm:col-span-2" />
        </div>
    </div>

    <div class="card">
        <label class="flex cursor-pointer items-start gap-3 px-6 py-4">
            <input type="hidden" name="generate_locations" value="0">
            <input type="checkbox" name="generate_locations" value="1" class="form-checkbox mt-1" x-model="generate">
            <span>
                <span class="flex items-center gap-2 font-semibold text-slate-900">
                    <x-heroicon-o-sparkles class="size-5 text-slate-400" /> Buat lokasi bin otomatis
                </span>
                <span class="mt-0.5 block text-sm text-slate-500">
                    @if ($rack->exists)
                        Rak ini sudah memiliki {{ $rack->locations_count }} lokasi. Hanya lokasi yang belum ada yang akan dibuat.
                    @else
                        Buat lokasi untuk setiap level dan posisi di rak ini sekaligus.
                    @endif
                </span>
            </span>
        </label>
        <div x-show="generate" x-collapse x-cloak>
            <div class="grid gap-5 border-t border-slate-100 p-6 sm:grid-cols-2">
                <x-input name="positions" type="number" label="Jumlah Bin per Level" value="5" min="1" max="100" x-model.number="positions" />
                <div class="rounded-lg bg-slate-50 p-4 text-sm text-slate-600">
                    <p>Total: <span class="font-semibold text-slate-900" x-text="(levels || 0) * (positions || 0)"></span> lokasi</p>
                    <p class="mt-1">Contoh kode:
                        <span class="font-mono font-medium text-slate-900" x-text="`${(code || 'RAK').toUpperCase()}-01-01`"></span> s/d
                        <span class="font-mono font-medium text-slate-900" x-text="`${(code || 'RAK').toUpperCase()}-${String(levels || 1).padStart(2, '0')}-${String(positions || 1).padStart(2, '0')}`"></span>
                    </p>
                    <p class="mt-1 text-xs text-slate-500">Format: KODE RAK-LEVEL-POSISI</p>
                </div>
            </div>
        </div>
    </div>

    <x-active-toggle :checked="$rack->is_active" title="Status Rak" description="Rak nonaktif tidak bisa dipakai untuk penempatan barang baru." />
</div>
