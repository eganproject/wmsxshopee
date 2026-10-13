{{--
    Modal konfirmasi global. Pemakaian:
    <button type="button" @click="$dispatch('confirm', { form: 'form-id', title: '...', message: '...' })">
    atau <form data-confirm="Pesan" data-confirm-title="Judul" data-confirm-text="Ya"> (lihat resources/js/ui.js).
--}}
<div x-data="confirmDialog" x-cloak @confirm.window="show($event.detail)" @keydown.escape.window="close()">
    <div x-show="open" x-transition.opacity class="fixed inset-0 z-[70] bg-black/40"></div>

    <div x-show="open" class="fixed inset-0 z-[80] flex items-center justify-center p-4">
        <div x-show="open" x-transition @click.outside="close()" role="alertdialog" aria-modal="true" aria-labelledby="confirm-title"
             class="w-full max-w-md rounded-xl border border-slate-200 bg-white p-5 shadow-xl">
            <div class="flex gap-4">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-full"
                      :class="variant === 'danger' ? 'bg-rose-100 text-rose-600' : 'bg-amber-100 text-amber-600'">
                    <x-heroicon-o-exclamation-triangle class="size-5" />
                </span>
                <div>
                    <h3 id="confirm-title" class="text-base font-semibold text-slate-900" x-text="title"></h3>
                    <p class="mt-1.5 text-sm whitespace-pre-line text-slate-600" x-text="message"></p>
                </div>
            </div>
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" class="btn-secondary" x-ref="cancel" @click="close()" :disabled="busy">Batal</button>
                <button type="button" x-ref="confirm" :class="[variant === 'danger' ? 'btn-danger' : 'btn-primary', busy && 'is-loading']" @click="submit()">
                    <span x-text="busy ? 'Memproses...' : confirmText"></span>
                </button>
            </div>
        </div>
    </div>
</div>
