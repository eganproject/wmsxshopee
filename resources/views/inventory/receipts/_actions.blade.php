<div class="sticky bottom-0 mt-6 flex items-center justify-between gap-3 border-t border-slate-200 bg-white/90 py-3 backdrop-blur">
    <p class="hidden items-center gap-1.5 text-xs text-slate-500 sm:flex">
        <x-heroicon-o-information-circle class="size-4" /> Stok baru bertambah setelah penerimaan dikonfirmasi diterima.
    </p>
    <div class="flex gap-2">
        <a href="{{ route('inventory.receipts.index') }}" class="btn-secondary">Batal</a>
        <button type="submit" name="action" value="draft" class="btn-secondary"><x-heroicon-o-document class="size-5" /> Simpan Draft</button>
        @can('receipts.receive')
            <button type="submit" name="action" value="receive" class="btn-primary"><x-heroicon-o-check class="size-5" /> Simpan & Terima</button>
        @endcan
    </div>
</div>
