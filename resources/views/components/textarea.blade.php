@props([
    'name',
    'label' => null,
    'value' => null,
    'hint' => null,
    'rows' => 3,
])

@php
    $id = $attributes->get('id', $name);
    $error = $errors->first($name);
@endphp

<div {{ $attributes->only('class') }}>
    @if ($label)
        <label for="{{ $id }}" class="form-label">{{ $label }}</label>
    @endif

    <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}"
              {{ $attributes->except(['class', 'id'])->class(['form-input', 'form-input-error' => $error]) }}>{{ old($name, $value) }}</textarea>

    @if ($error)
        <p class="mt-1 flex items-center gap-1 text-xs text-rose-600">
            <x-heroicon-o-exclamation-triangle class="size-4 shrink-0" /> {{ $error }}
        </p>
    @elseif ($hint)
        <p class="mt-1 text-xs text-slate-400">{{ $hint }}</p>
    @endif
</div>
