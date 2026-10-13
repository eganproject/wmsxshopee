@if ($receipt->isDraft())
    <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium whitespace-nowrap text-slate-600">
        <x-heroicon-o-document class="size-3.5" /> Draft
    </span>
@else
    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium whitespace-nowrap text-emerald-700">
        <x-heroicon-o-check-circle class="size-3.5" /> Diterima
    </span>
@endif
