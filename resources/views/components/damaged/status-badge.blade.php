@props(['document'])

@if ($document->isDraft())
    <span class="inline-flex cursor-help items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium whitespace-nowrap text-slate-600"
          data-hint-title="Draft" data-hint="{{ \App\Models\DamagedDocument::STATUSES['draft']['hint'] }}">
        <x-heroicon-o-document class="size-3.5" /> Draft
    </span>
@else
    <span class="inline-flex cursor-help items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium whitespace-nowrap text-emerald-700"
          data-hint-title="{{ \App\Models\DamagedDocument::STATUSES['approved']['label'] }}" data-hint="{{ \App\Models\DamagedDocument::STATUSES['approved']['hint'] }}">
        <x-heroicon-o-check-circle class="size-3.5" /> Disetujui
    </span>
@endif
