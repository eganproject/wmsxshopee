<x-layouts.guest :title="$title">
    <div class="flex min-h-full items-center justify-center bg-slate-50 px-6 py-16">
        <div class="max-w-md text-center">
            <span class="mx-auto flex size-16 items-center justify-center rounded-2xl bg-brand-50 text-brand-500 ring-1 ring-brand-100">
                <x-dynamic-component :component="$icon" class="size-8" />
            </span>
            <p class="mt-6 text-sm font-semibold text-brand-600">{{ $code }}</p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $title }}</h1>
            <p class="mt-2 text-slate-500">{{ $message }}</p>
            <div class="mt-8 flex justify-center gap-3">
                <a href="{{ url()->previous() !== url()->current() ? url()->previous() : url('/') }}" class="btn-secondary">
                    <x-heroicon-o-arrow-left class="size-5" /> Kembali
                </a>
                <a href="{{ url('/') }}" class="btn-primary"><x-heroicon-o-home class="size-5" /> Ke Dashboard</a>
            </div>
        </div>
    </div>
</x-layouts.guest>
