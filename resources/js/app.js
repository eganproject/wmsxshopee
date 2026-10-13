import './bootstrap';
import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';

Alpine.plugin(collapse);

// Modal konfirmasi global: <button @click="$dispatch('confirm', { ... })">
Alpine.data('confirmDialog', () => ({
    open: false,
    title: '',
    message: '',
    confirmText: 'Ya, lanjutkan',
    variant: 'danger',
    form: null,
    show(detail) {
        this.title = detail.title ?? 'Konfirmasi';
        this.message = detail.message ?? 'Apakah Anda yakin?';
        this.confirmText = detail.confirmText ?? 'Ya, lanjutkan';
        this.variant = detail.variant ?? 'danger';
        this.form = document.getElementById(detail.form);
        this.open = true;
    },
    submit() {
        this.form?.submit();
        this.open = false;
    },
}));

window.Alpine = Alpine;
Alpine.start();
