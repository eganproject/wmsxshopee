@props(['title', 'description' => null, 'icon' => null])

<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
    <div class="flex items-center gap-3">
        @if ($icon)
            <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600 ring-1 ring-brand-100">
                <x-dynamic-component :component="$icon" class="size-6" />
            </span>
        @endif
        <div>
            <h1 class="text-xl font-semibold text-slate-900 sm:text-2xl">{{ $title }}</h1>
            @if ($description)
                <p class="mt-0.5 text-sm text-slate-500">{{ $description }}</p>
            @endif
        </div>
    </div>
    @if ($slot->isNotEmpty())
        <div class="flex shrink-0 items-center gap-2">{{ $slot }}</div>
    @endif
</div>
