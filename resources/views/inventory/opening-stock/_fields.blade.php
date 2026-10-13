<x-select name="warehouse_id" label="Gudang" required>
    <option value="">Pilih gudang</option>
    @foreach ($warehouses as $warehouse)
        <option value="{{ $warehouse->id }}" @selected((string) old('warehouse_id', $warehouses->count() === 1 ? $warehouse->id : '') === (string) $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>
    @endforeach
</x-select>
<div class="grid gap-4 sm:grid-cols-2">
    <x-input name="movement_date" type="date" label="Tanggal" :value="old('movement_date', now()->toDateString())" :max="now()->toDateString()" required />
    <x-input name="reference" label="Referensi" :value="old('reference', 'Stok awal')" />
</div>
