<x-select name="warehouse_id" label="Gudang" tip="Gudang yang menerima stok awal dari file impor." required>
    <option value="">Pilih gudang</option>
    @foreach ($warehouses as $warehouse)
        <option value="{{ $warehouse->id }}" @selected((string) old('warehouse_id', $warehouses->count() === 1 ? $warehouse->id : '') === (string) $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>
    @endforeach
</x-select>
<div class="grid gap-4 sm:grid-cols-2">
    <x-input name="movement_date" type="date" tip="Tanggal saldo awal dicatat di kartu stok." label="Tanggal" :value="old('movement_date', now()->toDateString())" :max="now()->toDateString()" required />
    <x-input name="reference" label="Referensi" tip="Keterangan yang muncul di kartu stok, misalnya Stok awal Oktober." :value="old('reference', 'Stok awal')" />
</div>
