@props([
    'name',
    'label' => null,
    'type' => 'text',
    'icon' => null,
    'hint' => null,
    'tip' => null,
    'value' => null,
    'bag' => 'default',
    'required' => false,
])

@php
    $id = $attributes->get('id', $name);
    $error = $errors->getBag($bag)->first($name);
    $isPassword = $type === 'password';
@endphp

<div {{ $attributes->only('class') }}>
    @if ($label)
        <label for="{{ $id }}" class="form-label">
            {{ $label }} @if ($required)<span class="text-rose-500">*</span>@endif
            @if ($tip)<x-hint :text="$tip" :title="$label" />@endif
        </label>
    @endif

    <div class="relative" @if ($isPassword) x-data="{ visible: false }" @endif>
        @if ($icon)
            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2.5 text-slate-400">
                <x-dynamic-component :component="$icon" class="size-4" />
            </span>
        @endif

        <input id="{{ $id }}" name="{{ $name }}"
               @if ($isPassword) :type="visible ? 'text' : 'password'" type="password" @else type="{{ $type }}" @endif
               @unless ($isPassword) value="{{ old($name, $value) }}" @endunless
               @required($required)
               {{ $attributes->except(['class', 'id'])->class([
                   'form-input',
                   'pl-8' => $icon,
                   'pr-9' => $isPassword,
                   'form-input-error' => $error,
               ]) }}>

        @if ($isPassword)
            <button type="button" @click="visible = !visible" tabindex="-1"
                    class="absolute inset-y-0 right-0 flex items-center pr-2.5 text-slate-400 hover:text-slate-600"
                    :aria-label="visible ? 'Sembunyikan password' : 'Tampilkan password'">
                <x-heroicon-o-eye class="size-4" x-show="!visible" />
                <x-heroicon-o-eye-slash class="size-4" x-show="visible" x-cloak />
            </button>
        @endif
    </div>

    @if ($error)
        <p class="mt-1 flex items-center gap-1 text-xs text-rose-600">
            <x-heroicon-o-exclamation-triangle class="size-4 shrink-0" /> {{ $error }}
        </p>
    @elseif ($hint)
        <p class="mt-1 text-xs text-slate-400">{{ $hint }}</p>
    @endif
</div>
