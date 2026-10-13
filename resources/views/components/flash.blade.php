@php
    $types = [
        'success' => ['icon' => 'heroicon-o-check-circle', 'class' => 'text-emerald-500'],
        'error' => ['icon' => 'heroicon-o-x-circle', 'class' => 'text-rose-500'],
        'info' => ['icon' => 'heroicon-o-information-circle', 'class' => 'text-sky-500'],
    ];
@endphp

<div class="pointer-events-none fixed inset-x-0 top-4 z-[60] flex flex-col items-center gap-2 px-4 sm:items-end sm:px-6">
    @foreach ($types as $type => $style)
        @if (session($type))
            <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 5000)" x-show="show"
                 x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-2"
                 x-transition:leave="transition ease-in duration-200" x-transition:leave-end="opacity-0"
                 class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-lg">
                <x-dynamic-component :component="$style['icon']" class="size-6 shrink-0 {{ $style['class'] }}" />
                <p class="flex-1 pt-0.5 text-sm text-slate-700">{{ session($type) }}</p>
                <button type="button" @click="show = false" class="text-slate-400 hover:text-slate-600">
                    <x-heroicon-o-x-mark class="size-5" />
                </button>
            </div>
        @endif
    @endforeach
</div>
