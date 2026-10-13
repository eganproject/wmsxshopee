{{-- Logo Optima: huruf "O" dengan titik aksen di atas latar brand --}}
<span {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center justify-center bg-brand-500 text-white']) }}>
    <svg viewBox="0 0 24 24" fill="none" class="size-3/5" aria-hidden="true">
        <circle cx="11" cy="13" r="7" stroke="currentColor" stroke-width="3" />
        <circle cx="19" cy="5" r="2.5" fill="currentColor" />
    </svg>
    <span class="sr-only">{{ config('app.name') }}</span>
</span>
