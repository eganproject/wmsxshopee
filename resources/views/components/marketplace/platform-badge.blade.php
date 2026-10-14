@props(['platform'])

{{-- Penanda platform marketplace: Shopee (oranye) atau TikTok Shop (hitam). --}}
<span {{ $attributes->class([
    'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium whitespace-nowrap',
    'bg-brand-50 text-brand-700' => $platform === 'shopee',
    'bg-slate-900 text-white' => $platform === 'tiktok',
]) }}>
    @if ($platform === 'shopee')
        <x-heroicon-s-shopping-bag class="size-3.5" /> Shopee
    @else
        <x-heroicon-s-musical-note class="size-3.5" /> TikTok
    @endif
</span>
