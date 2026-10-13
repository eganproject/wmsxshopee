{{--
    Modal konfirmasi global. Pemakaian:
    <button type="button" @click="$dispatch('confirm', { form: 'form-id', title: '...', message: '...' })">
--}}
<div x-data="confirmDialog" x-cloak @confirm.window="show($event.detail)" @keydown.escape.window="open = false">
    <div x-show="open" x-transition.opacity class="fixed inset-0 z-[70] bg-black/40"></div>

    <div x-show="open" class="fixed inset-0 z-[80] flex items-center justify-center p-4">
        <div x-show="open" x-transition @click.outside="open = false"
             class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
            <div class="flex gap-4">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-full"
                      :class="variant === 'danger' ? 'bg-rose-100 text-rose-600' : 'bg-amber-100 text-amber-600'">
                    <x-heroicon-o-exclamation-triangle class="size-5" />
                </span>
                <div>
                    <h3 class="text-base font-semibold text-slate-900" x-text="title"></h3>
                    <p class="mt-1.5 text-sm text-slate-600" x-text="message"></p>
                </div>
            </div>
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="open = false">Batal</button>
                <button type="button" :class="variant === 'danger' ? 'btn-danger' : 'btn-primary'" @click="submit()" x-text="confirmText"></button>
            </div>
        </div>
    </div>
</div>
