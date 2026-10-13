@props(['checked' => false, 'title' => 'Status', 'description' => null])

<div class="card flex items-center justify-between gap-4 px-5 py-4">
    <div class="flex items-start gap-3">
                <div>
            <p class="font-medium text-slate-900">{{ $title }}</p>
            @if ($description)
                <p class="text-sm text-slate-500">{{ $description }}</p>
            @endif
        </div>
    </div>
    <label class="relative inline-flex cursor-pointer items-center">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" class="peer sr-only" @checked(old('is_active', $checked))>
        <span class="h-5 w-9 rounded-full bg-slate-300 transition peer-checked:bg-slate-900 peer-focus-visible:ring-2 peer-focus-visible:ring-slate-300 peer-focus-visible:ring-offset-2"></span>
        <span class="absolute left-0.5 size-4 rounded-full bg-white shadow-sm transition peer-checked:translate-x-4"></span>
    </label>
</div>
