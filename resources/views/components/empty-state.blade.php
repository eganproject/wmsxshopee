{{--
    Tampilan kosong untuk daftar. Jika halaman sedang difilter/dicari, otomatis
    berganti jadi "Tidak ada hasil" dengan tombol hapus filter.

    <x-empty-state colspan="7" icon="heroicon-o-cube" title="Belum ada produk" description="...">
        <a href="..." class="btn-primary">Tambah Produk</a>
    </x-empty-state>
--}}
@props([
    'icon' => 'heroicon-o-inbox',
    'title',
    'description' => null,
    'colspan' => null,
    'filtered' => null,
    'resetUrl' => null,
    'ignore' => [],
])

@php
    // Parameter yang bukan filter (halaman, urutan, tab, induk seperti ?warehouse=) tidak dihitung.
    $ignored = ['page', 'per_page', 'sort', 'direction', 'tab', ...$ignore];
    $filtered ??= collect(request()->query())->except($ignored)->filter(fn ($value) => filled($value))->isNotEmpty();
@endphp

@if ($colspan)
    <tr><td colspan="{{ $colspan }}">
@endif

<div {{ $attributes->class('mx-auto flex max-w-md flex-col items-center px-5 py-14 text-center') }}>
    <span class="flex size-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
        <x-dynamic-component :component="$filtered ? 'heroicon-o-magnifying-glass' : $icon" class="size-6" />
    </span>

    @if ($filtered)
        <p class="mt-4 font-medium text-slate-800">Tidak ada hasil yang cocok</p>
        <p class="mt-1 text-sm text-slate-500">Coba kata kunci lain atau hapus filter yang sedang dipakai.</p>
        <div class="mt-4">
            <a href="{{ $resetUrl ?? url()->current() }}" class="btn-secondary">
                <x-heroicon-o-x-mark class="size-5" /> Hapus filter
            </a>
        </div>
    @else
        <p class="mt-4 font-medium text-slate-800">{{ $title }}</p>
        @if ($description)
            <p class="mt-1 text-sm text-slate-500">{{ $description }}</p>
        @endif
        @if ($slot->isNotEmpty())
            <div class="mt-4 flex flex-wrap justify-center gap-2">{{ $slot }}</div>
        @endif
    @endif
</div>

@if ($colspan)
    </td></tr>
@endif
