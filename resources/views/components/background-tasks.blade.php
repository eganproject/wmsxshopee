{{--
    Sinkron marketplace yang berjalan di antrean (App\Support\BackgroundTasks).
    Selama ada yang berjalan, pil kecil tampil di pojok kanan bawah dan halaman
    menanyakan statusnya tiap 5 detik; hasil yang selesai muncul sebagai toast.
--}}
@php
    $user = auth()->user();
    $state = $user && $user->can('marketplace.view') && \App\Support\BackgroundTasks::has($user->id)
        ? \App\Support\BackgroundTasks::poll($user->id)
        : null;
@endphp

@if ($state)
    <div x-data="{
            running: @js($state['running']),
            timer: null,
            init() {
                @js($state['finished']).forEach((t) => $store.toasts.push(t.message, t.type));
                if (this.running.length) this.timer = setInterval(() => this.check(), 5000);
            },
            async check() {
                try {
                    const res = await fetch(@js(route('marketplace.background-tasks')), { headers: { Accept: 'application/json' } });
                    if (! res.ok) return;
                    const data = await res.json();
                    data.finished.forEach((t) => $store.toasts.push(t.message + ' Muat ulang halaman untuk melihat data terbaru.', t.type, t.type === 'success' ? 15000 : null));
                    this.running = data.running;
                    if (! this.running.length) clearInterval(this.timer);
                } catch (e) {}
            },
         }"
         x-show="running.length" x-cloak
         class="fixed right-4 bottom-4 z-[55] flex max-w-xs items-start gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-lg sm:right-6"
         role="status" aria-live="polite">
        <x-heroicon-o-arrow-path class="mt-0.5 size-4 shrink-0 animate-spin text-sky-600" />
        <div class="min-w-0">
            <p class="font-medium" x-text="running.length > 1 ? running.length + ' sinkron berjalan' : 'Sinkron berjalan'"></p>
            <template x-for="label in running" :key="label">
                <p class="truncate text-xs text-slate-500" x-text="label"></p>
            </template>
        </div>
    </div>
@endif
