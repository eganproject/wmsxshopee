@props(['title', 'description' => null, 'icon' => null])

<div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <div class="min-w-0">
        <h1 class="text-xl font-semibold tracking-normal text-slate-900">{{ $title }}</h1>
        @if ($description)
            <p class="mt-0.5 text-sm text-slate-500">{{ $description }}</p>
        @endif
    </div>
    @if ($slot->isNotEmpty())
        <div class="flex shrink-0 items-center gap-2">{{ $slot }}</div>
    @endif
</div>

<x-form-errors />
