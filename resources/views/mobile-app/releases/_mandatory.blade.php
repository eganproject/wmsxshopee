{{-- Tombol wajib update. Dipakai di form tambah & ubah versi. --}}
<div class="card flex items-center justify-between gap-4 px-5 py-4">
    <div>
        <p class="font-medium text-slate-900">Wajib update</p>
        <p class="text-sm text-slate-500">Aplikasi versi lama tidak bisa dipakai sampai update ini dipasang. Pakai untuk perbaikan penting saja.</p>
    </div>
    <label class="relative inline-flex shrink-0 cursor-pointer items-center">
        <input type="hidden" name="is_mandatory" value="0">
        <input type="checkbox" name="is_mandatory" value="1" class="peer sr-only" @checked(old('is_mandatory', $release->is_mandatory))>
        <span class="h-5 w-9 rounded-full bg-slate-300 transition peer-checked:bg-slate-900 peer-focus-visible:ring-2 peer-focus-visible:ring-slate-300 peer-focus-visible:ring-offset-2"></span>
        <span class="absolute left-0.5 size-4 rounded-full bg-white shadow-sm transition peer-checked:translate-x-4"></span>
    </label>
</div>
