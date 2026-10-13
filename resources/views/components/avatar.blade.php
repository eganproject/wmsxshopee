@props(['user'])

@php
    $palette = ['bg-brand-100 text-brand-700', 'bg-sky-100 text-sky-700', 'bg-emerald-100 text-emerald-700', 'bg-violet-100 text-violet-700', 'bg-amber-100 text-amber-700'];
    $color = $palette[$user->id % count($palette)];
@endphp

<span {{ $attributes->class(["inline-flex shrink-0 items-center justify-center rounded-full font-semibold {$color}"]) }}>
    {{ $user->initials() }}
</span>
