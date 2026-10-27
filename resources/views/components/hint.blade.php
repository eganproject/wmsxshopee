{{--
    Ikon info kecil yang menampilkan tooltip penjelasan (gaya sama di semua halaman, terang & gelap).
    Isi tooltip dibaca oleh initHintTips di resources/js/app.js.

    <x-hint text="Stok fisik dikurangi stok yang dipesan." />
    <x-hint title="Tertahan" text="..." next="Langkah yang disarankan berikutnya." />
--}}
@props(['text', 'title' => null, 'next' => null])

<button type="button" {{ $attributes->class('hint-icon') }}
        data-hint="{{ $text }}" @if ($title) data-hint-title="{{ $title }}" @endif @if ($next) data-hint-next="{{ $next }}" @endif
        aria-label="{{ $title ? $title.': ' : '' }}{{ $text }}">
    <x-heroicon-o-information-circle />
</button>
