@props(['checked' => false, 'title' => 'Status', 'description' => null])

<div class="card flex items-center justify-between gap-4 p-6">
    <div class="flex items-start gap-3">
        <x-heroicon-o-power class="size-6 shrink-0 text-slate-400" />
        <div>
            <p class="font-semibold text-slate-900">{{ $title }}</p>
            @if ($description)
                <p class="text-sm text-slate-500">{{ $description }}</p>
            @endif
        </div>
    </div>
    <label class="relative inline-flex cursor-pointer items-center">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" class="peer sr-only" @checked(old('is_active', $checked))>
        <span class="h-6 w-11 rounded-full bg-slate-200 transition peer-checked:bg-emerald-500 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500 peer-focus-visible:ring-offset-2"></span>
        <span class="absolute left-0.5 size-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
    </label>
</div>
