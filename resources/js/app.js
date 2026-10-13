import './bootstrap';
import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import { enhance, enhanceDate, enhanceSelect, registerAlpine } from './form-controls';
import { labelBuilder } from './labels';
import { initUi, registerToasts } from './ui';

Alpine.plugin(collapse);
registerAlpine(Alpine);
Alpine.data('labelBuilder', labelBuilder);
const toast = registerToasts(Alpine);

// Modal konfirmasi global: <button @click="$dispatch('confirm', { form: 'form-id', ... })">
// atau <form data-confirm="..."> (lihat ui.js).
Alpine.data('confirmDialog', () => ({
    open: false,
    busy: false,
    title: '',
    message: '',
    confirmText: 'Ya, lanjutkan',
    variant: 'danger',
    form: null,
    submitter: null,
    show(detail) {
        if (this.busy) return;
        this.title = detail.title ?? 'Konfirmasi';
        this.message = detail.message ?? 'Apakah Anda yakin?';
        this.confirmText = detail.confirmText ?? 'Ya, lanjutkan';
        this.variant = detail.variant ?? 'danger';
        this.form = typeof detail.form === 'string' ? document.getElementById(detail.form) : detail.form;
        this.submitter = detail.submitter ?? null;
        this.open = true;
        // Tombol aman (Batal) difokuskan untuk aksi berbahaya, supaya Enter tidak langsung menghapus.
        this.$nextTick(() => (this.variant === 'danger' ? this.$refs.cancel : this.$refs.confirm)?.focus());
    },
    close() {
        if (! this.busy) this.open = false;
    },
    submit() {
        if (! this.form || this.busy) return;
        this.busy = true;
        this.form.dataset.confirmed = '1';
        if (this.form.requestSubmit) {
            this.form.requestSubmit(this.submitter ?? undefined);
        } else {
            this.form.submit();
        }
        // Jika gagal terkirim (mis. validasi browser), modal bisa dipakai lagi.
        setTimeout(() => {
            if (! this.form?.dataset.submitting) {
                this.busy = false;
                this.open = false;
            }
        }, 200);
    },
    init() {
        window.addEventListener('pageshow', () => {
            this.busy = false;
            this.open = false;
        });
    },
}));

// Tombol tema di topbar: Terang → Gelap → Sistem
Alpine.data('themeToggle', () => ({
    theme: window.optimaTheme?.get() ?? 'system',
    labels: { light: 'Tema terang', dark: 'Tema gelap', system: 'Ikuti sistem' },
    cycle() {
        const order = ['light', 'dark', 'system'];
        this.theme = order[(order.indexOf(this.theme) + 1) % order.length];
        window.optimaTheme?.set(this.theme);
    },
}));

// Sidebar diciutkan (ikon saja) di layar lebar, tersimpan per browser.
Alpine.data('sidebarToggle', () => ({
    collapsed: document.documentElement.classList.contains('sidebar-collapsed'),
    toggle() {
        this.collapsed = ! this.collapsed;
        document.documentElement.classList.toggle('sidebar-collapsed', this.collapsed);
        try { localStorage.setItem('sidebar', this.collapsed ? 'collapsed' : 'expanded'); } catch (e) {}
        document.getElementById('sidebar-tip')?.classList.add('hidden');
    },
}));

// Nama menu muncul di samping ikon saat sidebar diciutkan (tooltip fixed agar tidak terpotong scroll menu).
Alpine.data('sidebarTips', () => ({
    init() {
        const tip = document.getElementById('sidebar-tip');
        const active = () => document.documentElement.classList.contains('sidebar-collapsed') && window.matchMedia('(min-width: 64rem)').matches;
        const show = (event) => {
            const target = event.target.closest?.('[data-tip]');
            if (! tip) return;
            if (! target || ! active()) {
                tip.classList.add('hidden');
                return;
            }
            const rect = target.getBoundingClientRect();
            tip.textContent = target.dataset.tip;
            tip.style.left = `${rect.right + 8}px`;
            tip.style.top = `${rect.top + rect.height / 2}px`;
            tip.classList.remove('hidden');
        };
        const hide = () => tip?.classList.add('hidden');
        this.$el.addEventListener('mouseover', show);
        this.$el.addEventListener('focusin', show);
        this.$el.addEventListener('mouseleave', hide);
        this.$el.addEventListener('focusout', hide);
        this.$el.querySelector('nav')?.addEventListener('scroll', hide);
    },
}));

window.Alpine = Alpine;
window.Optima = { ...window.Optima, enhance, enhanceSelect, enhanceDate, toast };
initUi(toast);

// Select & tanggal diperkaya setelah Alpine selesai mengisi nilai awal (x-model).
document.addEventListener('alpine:initialized', () => enhance());

Alpine.start();
