/*
 * Perilaku UI global:
 * - Toast: window.Optima.toast('Pesan', 'success' | 'error' | 'warning' | 'info').
 * - Bar progres di atas layar saat pindah halaman / kirim form.
 * - Tombol submit menampilkan spinner dan form tidak bisa terkirim dua kali.
 *   Tambahkan `data-no-loading` pada form / link untuk mematikannya (mis. unduhan file).
 * - <form data-confirm="Pesan" data-confirm-title="..." data-confirm-text="..." data-confirm-variant="primary">
 *   meminta konfirmasi lewat modal sebelum dikirim.
 * - <form data-dirty-guard> memperingatkan jika halaman ditinggal saat isian belum disimpan.
 * - Error AJAX (axios) tampil sebagai toast, dan isian yang error difokuskan setelah validasi gagal.
 */

const PROGRESS_DELAY = 150;
const LOADING_TIMEOUT = 20000;

/* ---------- Toast ---------- */

export function registerToasts(Alpine) {
    let nextId = 1;

    Alpine.store('toasts', {
        items: [],
        push(message, type = 'info', timeout = null) {
            if (! message) return;
            // Pesan sama yang muncul beruntun cukup sekali.
            if (this.items.some((item) => item.message === message)) return;

            const id = nextId++;
            this.items.push({ id, message, type });

            // Error & peringatan tetap tampil sampai ditutup, supaya sempat dibaca.
            const duration = timeout ?? (type === 'error' || type === 'warning' ? 0 : 5000);
            if (duration > 0) {
                setTimeout(() => this.dismiss(id), duration);
            }
        },
        dismiss(id) {
            this.items = this.items.filter((item) => item.id !== id);
        },
    });

    return (message, type, timeout) => Alpine.store('toasts').push(message, type, timeout);
}

/* ---------- Bar progres halaman ---------- */

const progress = {
    el: null,
    timer: null,
    safety: null,
    start() {
        if (this.timer || this.el?.classList.contains('is-active')) return;
        this.timer = setTimeout(() => {
            this.el ??= document.getElementById('page-progress');
            this.el?.classList.remove('is-done');
            this.el?.classList.add('is-active');
        }, PROGRESS_DELAY);
        // Unduhan file tidak memuat halaman baru, jadi bar dihentikan sendiri.
        this.safety = setTimeout(() => this.done(), LOADING_TIMEOUT);
    },
    done() {
        clearTimeout(this.timer);
        clearTimeout(this.safety);
        this.timer = null;
        if (this.el?.classList.contains('is-active')) {
            this.el.classList.replace('is-active', 'is-done');
            setTimeout(() => this.el?.classList.remove('is-done'), 300);
        }
    },
};

function isPlainNavigation(event, link) {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return false;
    }
    if (link.target && link.target !== '_self') return false;
    if (link.hasAttribute('download') || link.closest('[data-no-loading]')) return false;

    const href = link.getAttribute('href');
    if (! href || href.startsWith('#') || href.startsWith('javascript:') || href.startsWith('mailto:') || href.startsWith('tel:')) {
        return false;
    }

    const url = new URL(link.href, window.location.href);
    if (url.origin !== window.location.origin) return false;
    // Lompat ke anchor di halaman yang sama tidak memuat ulang.
    if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) return false;

    return true;
}

/* ---------- Status loading form ---------- */

function setLoading(form, submitter) {
    form.dataset.submitting = '1';
    form.setAttribute('aria-busy', 'true');

    const button = submitter ?? form.querySelector('button[type="submit"], button:not([type])');
    // Jangan pakai `disabled`: nilai tombol (name/value) harus ikut terkirim.
    button?.classList.add('is-loading');
    button?.setAttribute('aria-disabled', 'true');
}

function resetLoading() {
    document.querySelectorAll('form[data-submitting]').forEach((form) => {
        delete form.dataset.submitting;
        form.removeAttribute('aria-busy');
    });
    document.querySelectorAll('.is-loading').forEach((button) => {
        button.classList.remove('is-loading');
        button.removeAttribute('aria-disabled');
    });
}

function handleSubmit(event) {
    const form = event.target;
    if (! (form instanceof HTMLFormElement)) return;

    // Form yang perlu dikonfirmasi: tahan dulu, kirim ulang setelah "Ya".
    if (form.dataset.confirm !== undefined && ! form.dataset.confirmed) {
        event.preventDefault();
        if (form.reportValidity()) {
            window.dispatchEvent(new CustomEvent('confirm', {
                detail: {
                    form,
                    submitter: event.submitter,
                    title: form.dataset.confirmTitle,
                    message: form.dataset.confirm || undefined,
                    confirmText: form.dataset.confirmText,
                    variant: form.dataset.confirmVariant ?? 'primary',
                },
            }));
        }
        return;
    }
    delete form.dataset.confirmed;

    if (event.defaultPrevented || form.target === '_blank' || form.hasAttribute('data-no-loading')) return;

    if (form.dataset.submitting) {
        // Klik kedua saat permintaan pertama belum selesai.
        event.preventDefault();
        return;
    }

    form.dataset.clean = '1';
    progress.start();
    if ((form.getAttribute('method') ?? 'get').toLowerCase() !== 'get') {
        setLoading(form, event.submitter);
        setTimeout(resetLoading, LOADING_TIMEOUT);
    }
}

/* ---------- Penjaga perubahan belum disimpan ---------- */

function serialize(form) {
    const pairs = [];
    new FormData(form).forEach((value, key) => {
        if (key === '_token' || key === '_method') return;
        pairs.push(`${key}=${value instanceof File ? value.name : value}`);
    });
    return pairs.join('&');
}

function snapshotDirtyGuards() {
    document.querySelectorAll('form[data-dirty-guard]').forEach((form) => {
        form.dataset.snapshot = serialize(form);
    });
}

function hasUnsavedChanges() {
    return [...document.querySelectorAll('form[data-dirty-guard]')].some(
        (form) => ! form.dataset.clean && form.dataset.snapshot !== undefined && serialize(form) !== form.dataset.snapshot,
    );
}

/* ---------- Fokus ke isian yang error ---------- */

function focusFirstError() {
    const field = document.querySelector('main .form-input-error, main .ts-wrapper.is-invalid .ts-control input, main [aria-invalid="true"]');
    if (! field) return;

    field.scrollIntoView({ block: 'center', behavior: 'smooth' });
    // Jangan merebut fokus dari tombol / kolom yang sudah dipilih pengguna.
    if (document.activeElement === document.body) {
        field.focus({ preventScroll: true });
    }
}

/* ---------- Error AJAX ---------- */

function ajaxErrorMessage(error) {
    const status = error.response?.status;
    if (! error.response) return 'Tidak bisa terhubung ke server. Periksa koneksi lalu coba lagi.';
    if (status === 419) return 'Sesi Anda sudah berakhir. Muat ulang halaman lalu coba lagi.';
    if (status === 401) return 'Anda perlu login ulang.';
    if (status === 403) return 'Anda tidak punya akses untuk tindakan ini.';
    if (status === 404) return 'Data yang dicari tidak ditemukan.';
    if (status === 422) return null; // ditangani form masing-masing
    if (status === 429) return 'Terlalu banyak permintaan. Tunggu sebentar lalu coba lagi.';
    // 409: bentrok stok / kunci data, pesannya sudah berbahasa Indonesia dari server.
    if (status === 409 && error.response.data?.message) return error.response.data.message;
    return status >= 500
        ? 'Terjadi kesalahan di server. Coba lagi, atau hubungi admin jika terus terjadi.'
        : 'Permintaan tidak bisa diproses. Muat ulang halaman lalu coba lagi.';
}

/* ---------- Pasang semua ---------- */

export function initUi(toast) {
    document.addEventListener('submit', handleSubmit);

    // Link unduhan (ekspor Excel, dll.) memicu beforeunload tapi tidak pindah halaman.
    let downloading = false;
    document.addEventListener('click', (event) => {
        const link = event.target.closest?.('a[href]');
        if (! link) return;
        if (isPlainNavigation(event, link)) {
            progress.start();
        } else if (link.hasAttribute('download') || link.closest('[data-no-loading]')) {
            downloading = true;
            setTimeout(() => (downloading = false), 1000);
        }
    });

    window.addEventListener('beforeunload', (event) => {
        if (hasUnsavedChanges()) {
            progress.done();
            event.preventDefault();
            event.returnValue = '';
            return;
        }
        // Menangkap navigasi lain, mis. filter yang memanggil form.submit() saat pilihan berubah.
        if (! downloading) progress.start();
    });

    // Kembali lewat tombol Back (bfcache): bersihkan sisa loading.
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            progress.done();
            resetLoading();
            document.querySelectorAll('form[data-clean]').forEach((form) => delete form.dataset.clean);
        }
    });

    window.axios?.interceptors.response.use(undefined, (error) => {
        if (! window.axios.isCancel(error)) {
            toast(ajaxErrorMessage(error), 'error');
        }
        return Promise.reject(error);
    });

    document.addEventListener('alpine:initialized', () => {
        // Tunggu Tom Select / Flatpickr / x-model selesai mengisi nilai awal.
        setTimeout(snapshotDirtyGuards, 300);
        focusFirstError();
    });
}

export { progress, resetLoading };
