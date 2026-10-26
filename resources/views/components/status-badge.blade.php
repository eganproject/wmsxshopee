@props(['active'])

@if ($active)
    <span class="inline-flex cursor-help items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700" data-hint="Bisa dipilih dan dipakai di transaksi baru.">Aktif</span>
@else
    <span class="inline-flex cursor-help items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600" data-hint="Disembunyikan dari pilihan transaksi baru. Data dan riwayatnya tetap tersimpan.">Nonaktif</span>
@endif
