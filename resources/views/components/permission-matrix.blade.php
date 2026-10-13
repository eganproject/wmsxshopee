{{--
    Matriks checkbox permission per modul.
    $inherited (opsional): ekspresi Alpine yang mengembalikan true bila permission sudah didapat dari role.
--}}
@props(['groups', 'selected' => [], 'inherited' => null])

<div class="grid items-start gap-4 md:grid-cols-2">
    @foreach ($groups as $group)
        @php($names = $group['permissions']->pluck('name'))
        <div class="rounded-xl border border-slate-200"
             x-data="{ names: @js($names) }">
            <div class="flex items-center justify-between gap-3 border-b border-slate-100 bg-slate-50 px-4 py-2.5">
                <span class="flex items-center gap-2 text-sm font-medium text-slate-900">
                    <x-dynamic-component :component="$group['icon']" class="size-4 text-slate-500" />
                    {{ $group['label'] }}
                </span>
                <button type="button" class="text-xs text-slate-600 hover:text-slate-900"
                        @click="
                            const boxes = names.map(n => $root.querySelector(`[data-permission='${n}']`)).filter(b => b && !b.disabled);
                            const all = boxes.every(b => b.checked);
                            boxes.forEach(b => b.checked = !all);
                        ">
                    Pilih semua
                </button>
            </div>
            <div class="divide-y divide-slate-100">
                @foreach ($group['permissions'] as $permission)
                    <label class="flex cursor-pointer items-center gap-3 px-4 py-2 hover:bg-slate-50"
                           @if ($inherited) :class="{{ $inherited }}('{{ $permission['name'] }}') && 'cursor-default opacity-70'" @endif>
                        <input type="checkbox" name="permissions[]" value="{{ $permission['name'] }}"
                               data-permission="{{ $permission['name'] }}" class="form-checkbox"
                               @checked(in_array($permission['name'], $selected))
                               @if ($inherited) :disabled="{{ $inherited }}('{{ $permission['name'] }}')" @endif>
                        <span class="flex-1">
                            <span class="block text-sm text-slate-700">{{ $permission['label'] }}</span>
                            <code class="text-xs text-slate-400">{{ $permission['name'] }}</code>
                        </span>
                        @if ($inherited)
                            <span x-cloak x-show="{{ $inherited }}('{{ $permission['name'] }}')"
                                  class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">
                                <x-heroicon-o-check class="size-3" /> dari role
                            </span>
                        @endif
                    </label>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
