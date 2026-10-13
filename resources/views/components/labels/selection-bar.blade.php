{{--
    Bilah aksi untuk baris yang dicentang di halaman daftar: kirim ID terpilih ke
    halaman Cetak Label. Dipakai di dalam elemen dengan x-data="{ selected: [] }".
--}}
@props(['route', 'param'])

<div x-show="selected.length" x-cloak class="flex flex-wrap items-center gap-3 border-b border-slate-100 bg-slate-50 px-4 py-2 text-sm">
    <span class="text-slate-600"><span class="font-semibold text-slate-900" x-text="selected.length"></span> dipilih</span>
    <form method="GET" action="{{ route($route) }}">
        <template x-for="id in selected" :key="id">
            <input type="hidden" name="{{ $param }}[]" :value="id">
        </template>
        <button type="submit" class="btn-primary"><x-heroicon-o-qr-code class="size-5" /> Cetak Label</button>
    </form>
    {{ $slot }}
    <button type="button" class="text-slate-500 hover:text-slate-900" @click="selected = []">Batalkan pilihan</button>
</div>
