import './bootstrap';
import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import { enhance, enhanceDate, enhanceSelect, registerAlpine } from './form-controls';
import { fixedBinsFor, spreadToFixedBins } from './fixed-bins';
import { labelBuilder } from './labels';
import { globalSearch } from './search';
import { initAutoScan } from './scan';
import { initUi, registerToasts } from './ui';

Alpine.plugin(collapse);
registerAlpine(Alpine);
Alpine.data('labelBuilder', labelBuilder);
Alpine.data('globalSearch', globalSearch);
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
        const nav = this.$el.querySelector('nav');
        let scrollTimer;
        nav?.addEventListener('scroll', () => {
            hide();
            // Thumb scrollbar tampil sebentar saat menu digulir, lalu memudar lagi.
            nav.classList.add('is-scrolling');
            clearTimeout(scrollTimer);
            scrollTimer = setTimeout(() => nav.classList.remove('is-scrolling'), 800);
        }, { passive: true });
    },
}));

// Tooltip penjelasan (judul + isi + langkah berikutnya) untuk semua elemen [data-hint] di halaman,
// termasuk ikon info <x-hint>. Posisinya fixed agar tidak terpotong container yang bisa digulir.
function initHintTips() {
    const tip = document.getElementById('hint-tip');
    if (! tip) return;
    const parts = {
        title: tip.querySelector('[data-part=title]'),
        body: tip.querySelector('[data-part=body]'),
        next: tip.querySelector('[data-part=next]'),
        nextText: tip.querySelector('[data-part=next-text]'),
        arrow: tip.querySelector('[data-part=arrow]'),
    };
    let current = null;
    let timer;
    const place = (target) => {
        const rect = target.getBoundingClientRect();
        const width = tip.offsetWidth;
        const gutter = 12;
        const left = Math.min(Math.max(gutter, rect.left + rect.width / 2 - width / 2), window.innerWidth - width - gutter);
        const below = rect.bottom + 8 + tip.offsetHeight < window.innerHeight;
        tip.style.left = `${left}px`;
        tip.style.top = below ? `${rect.bottom + 8}px` : `${rect.top - tip.offsetHeight - 8}px`;
        parts.arrow.style.left = `${Math.min(Math.max(12, rect.left + rect.width / 2 - left - 5), width - 22)}px`;
        parts.arrow.classList.toggle('-top-[5px]', below);
        parts.arrow.classList.toggle('-bottom-[5px]', ! below);
        parts.arrow.classList.toggle('rotate-45', below);
        parts.arrow.classList.toggle('rotate-[225deg]', ! below);
    };
    const open = (target) => {
        if (! target.dataset.hint) return;
        clearTimeout(timer);
        current = target;
        parts.title.textContent = target.dataset.hintTitle || '';
        parts.title.classList.toggle('hidden', ! target.dataset.hintTitle);
        parts.body.textContent = target.dataset.hint;
        parts.body.classList.toggle('mt-1', !! target.dataset.hintTitle);
        parts.nextText.textContent = target.dataset.hintNext || '';
        parts.next.classList.toggle('hidden', ! target.dataset.hintNext);
        tip.classList.remove('hidden');
        place(target);
        requestAnimationFrame(() => tip.classList.remove('opacity-0'));
    };
    const close = () => {
        clearTimeout(timer);
        current = null;
        tip.classList.add('hidden', 'opacity-0');
    };
    document.addEventListener('mouseover', (event) => {
        const target = event.target.closest?.('[data-hint]');
        if (target === current) return;
        clearTimeout(timer);
        if (! target) {
            if (current) close();
            return;
        }
        // Jeda singkat supaya tooltip tidak berkedip saat kursor hanya lewat.
        timer = setTimeout(() => open(target), current ? 0 : 250);
    });
    document.addEventListener('focusin', (event) => {
        const target = event.target.closest?.('[data-hint]');
        target ? open(target) : current && close();
    });
    document.addEventListener('focusout', (event) => event.target.closest?.('[data-hint]') === current && close());
    // Layar sentuh: ketuk ikon info untuk membuka, ketuk di luar untuk menutup.
    document.addEventListener('click', (event) => {
        const icon = event.target.closest?.('[data-hint].hint-icon');
        if (icon) {
            event.preventDefault();
            if (current !== icon) open(icon);
        } else if (current && ! event.target.closest?.('[data-hint]')) {
            close();
        }
    });
    window.addEventListener('scroll', close, { passive: true, capture: true });
    window.addEventListener('keydown', (event) => event.key === 'Escape' && close());
}

window.Alpine = Alpine;
window.Optima = { ...window.Optima, enhance, enhanceSelect, enhanceDate, toast, fixedBinsFor, spreadToFixedBins };
initUi(toast);
initAutoScan();
initHintTips();

// Select & tanggal diperkaya setelah Alpine selesai mengisi nilai awal (x-model).
document.addEventListener('alpine:initialized', () => enhance());

Alpine.start();
