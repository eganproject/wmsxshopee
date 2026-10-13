{{--
    Toast notifikasi. Pesan dari session (success / error / warning / info) dan dari JavaScript
    (window.Optima.toast) tampil di tempat yang sama. Error tidak hilang sendiri.
--}}
@php
    $flashes = collect(['success', 'error', 'warning', 'info'])
        ->filter(fn ($type) => session()->has($type) && filled(session($type)))
        ->map(fn ($type) => ['type' => $type, 'message' => session($type)])
        ->values();
@endphp

<div x-data x-init="@js($flashes).forEach((flash) => $store.toasts.push(flash.message, flash.type))"
     class="pointer-events-none fixed inset-x-0 top-4 z-[60] flex flex-col items-center gap-2 px-4 sm:items-end sm:px-6"
     aria-live="polite">
    <template x-for="toast in $store.toasts.items" :key="toast.id">
        <div x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-2"
             x-transition:leave="transition ease-in duration-200" x-transition:leave-end="opacity-0"
             :role="toast.type === 'error' ? 'alert' : 'status'"
             class="pointer-events-auto flex w-full max-w-sm items-start gap-2.5 rounded-xl border bg-white p-3 shadow-lg"
             :class="{
                 'border-rose-200': toast.type === 'error',
                 'border-amber-200': toast.type === 'warning',
                 'border-slate-200': toast.type === 'success' || toast.type === 'info',
             }">
            <x-heroicon-o-check-circle class="size-5 shrink-0 text-emerald-600" x-show="toast.type === 'success'" />
            <x-heroicon-o-x-circle class="size-5 shrink-0 text-rose-600" x-show="toast.type === 'error'" />
            <x-heroicon-o-exclamation-triangle class="size-5 shrink-0 text-amber-600" x-show="toast.type === 'warning'" />
            <x-heroicon-o-information-circle class="size-5 shrink-0 text-sky-600" x-show="toast.type === 'info'" />
            <p class="flex-1 pt-0.5 text-sm whitespace-pre-line text-slate-700" x-text="toast.message"></p>
            <button type="button" @click="$store.toasts.dismiss(toast.id)" class="text-slate-400 hover:text-slate-600" aria-label="Tutup">
                <x-heroicon-o-x-mark class="size-4" />
            </button>
        </div>
    </template>
</div>
