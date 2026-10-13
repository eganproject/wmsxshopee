<x-layouts.guest :title="$title">
    <div class="flex min-h-full items-center justify-center bg-white px-6 py-16">
        <div class="max-w-md text-center">
            <span class="mx-auto flex size-12 items-center justify-center rounded-xl bg-slate-100 text-slate-600">
                <x-dynamic-component :component="$icon" class="size-6" />
            </span>
            <p class="mt-5 text-sm text-slate-500">{{ $code }}</p>
            <h1 class="mt-1 text-xl font-semibold text-slate-900">{{ $title }}</h1>
            <p class="mt-2 text-sm text-slate-500">{{ $message }}</p>
            <div class="mt-6 flex justify-center gap-2">
                <a href="{{ url()->previous() !== url()->current() ? url()->previous() : url('/') }}" class="btn-secondary">
                    <x-heroicon-o-arrow-left class="size-5" /> Kembali
                </a>
                <a href="{{ url('/') }}" class="btn-primary"><x-heroicon-o-home class="size-5" /> Ke Dashboard</a>
            </div>
        </div>
    </div>
</x-layouts.guest>
