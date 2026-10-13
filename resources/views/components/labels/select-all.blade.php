{{-- Kotak centang "pilih semua di halaman ini" untuk header tabel. --}}
@props(['ids'])

<th {{ $attributes->merge(['class' => 'w-10 py-0 pr-0 pl-4']) }}>
    <input type="checkbox" class="form-checkbox block" title="Pilih semua di halaman ini" aria-label="Pilih semua"
           x-data="{ ids: @js(collect($ids)->map(fn ($id) => (string) $id)->values()) }"
           :checked="ids.length && ids.every(id => selected.includes(id))"
           @change="selected = $event.target.checked ? [...new Set([...selected, ...ids])] : selected.filter(id => ! ids.includes(id))">
</th>
