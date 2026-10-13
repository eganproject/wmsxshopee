@props(['active'])

@if ($active)
    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
        <span class="size-1.5 rounded-full bg-emerald-500"></span> Aktif
    </span>
@else
    <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">
        <span class="size-1.5 rounded-full bg-slate-400"></span> Nonaktif
    </span>
@endif
