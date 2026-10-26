@props([
    'name',
    'label' => null,
    'hint' => null,
    'tip' => null,
    'required' => false,
])

@php
    $id = $attributes->get('id', $name);
    $error = $errors->first($name);
@endphp

<div {{ $attributes->only('class') }}>
    @if ($label)
        <label for="{{ $id }}" class="form-label">
            {{ $label }} @if ($required)<span class="text-rose-500">*</span>@endif
            @if ($tip)<x-hint :text="$tip" :title="$label" />@endif
        </label>
    @endif

    <select id="{{ $id }}" name="{{ $name }}" @required($required)
            {{ $attributes->except(['class', 'id'])->class(['form-input', 'form-input-error' => $error]) }}>
        {{ $slot }}
    </select>

    @if ($error)
        <p class="mt-1 flex items-center gap-1 text-xs text-rose-600">
            <x-heroicon-o-exclamation-triangle class="size-4 shrink-0" /> {{ $error }}
        </p>
    @elseif ($hint)
        <p class="mt-1 text-xs text-slate-400">{{ $hint }}</p>
    @endif
</div>
