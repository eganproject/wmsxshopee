/*
 * Pencarian global (Ctrl+K / Cmd+K, atau "/" di luar isian).
 * Halaman & aksi cepat disaring di browser; data (produk, pesanan, resi, dll.)
 * dicari ke server lewat route('search') yang sudah membatasi hak akses & gudang.
 */

const DEBOUNCE = 200;
const MIN_LENGTH = 2;

const escapeHtml = (text) => String(text ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
})[char]);

const isEditable = (el) => el?.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el?.tagName);

export const globalSearch = (config) => ({
    open: false,
    query: '',
    loading: false,
    failed: false,
    groups: [],
    searched: '',
    active: 0,
    pages: config.pages ?? [],
    endpoint: config.endpoint,
    timer: null,
    controller: null,

    init() {
        window.addEventListener('keydown', (event) => {
            const key = event.key?.toLowerCase();
            if ((event.ctrlKey || event.metaKey) && ! event.altKey && key === 'k') {
                event.preventDefault();
                this.open ? this.close() : this.show();
            } else if (key === '/' && ! this.open && ! event.ctrlKey && ! event.metaKey && ! isEditable(event.target)) {
                event.preventDefault();
                this.show();
            }
        });
        this.$watch('query', () => this.schedule());
    },

    show() {
        this.open = true;
        this.active = 0;
        this.$nextTick(() => {
            this.$refs.input?.focus();
            this.$refs.input?.select();
        });
    },

    close() {
        this.open = false;
        this.controller?.abort();
    },

    /** Halaman & aksi yang cocok dengan kata kunci (semua kata harus ada). */
    get pageMatches() {
        const words = this.query.trim().toLowerCase().split(/\s+/).filter(Boolean);
        if (! words.length) return this.pages.filter((page) => page.kind === 'page').slice(0, 8);

        return this.pages
            .filter((page) => words.every((word) => `${page.title} ${page.subtitle ?? ''} ${page.keywords ?? ''}`.toLowerCase().includes(word)))
            .slice(0, 6);
    },

    /** Semua grup yang tampil, berurutan, untuk navigasi keyboard. */
    get sections() {
        const sections = [];
        const pages = this.pageMatches;
        const term = this.query.trim();
        const remote = this.groups;

        // Kata kunci yang mirip nomor/SKU (mis. hasil scan) mendahulukan data; selain itu halaman dulu.
        const pageSection = pages.length ? { key: 'pages', label: term ? 'Halaman & Aksi' : 'Buka Halaman', items: pages } : null;
        if (pageSection && (! term || ! remote.length || /\s/.test(term) || ! /\d/.test(term))) sections.push(pageSection);
        sections.push(...remote);
        if (pageSection && ! sections.includes(pageSection)) sections.push(pageSection);

        let index = 0;
        return sections.map((section) => ({
            ...section,
            items: section.items.map((item) => ({ ...item, index: index++ })),
        }));
    },

    get total() {
        return this.sections.reduce((sum, section) => sum + section.items.length, 0);
    },

    get tooShort() {
        const length = this.query.trim().length;
        return length > 0 && length < MIN_LENGTH;
    },

    get empty() {
        return this.query.trim().length >= MIN_LENGTH && ! this.loading && ! this.failed
            && this.searched === this.query.trim() && this.total === 0;
    },

    schedule() {
        clearTimeout(this.timer);
        this.active = 0;
        const term = this.query.trim();

        if (term.length < MIN_LENGTH) {
            this.controller?.abort();
            this.groups = [];
            this.loading = false;
            this.failed = false;
            this.searched = term;
            return;
        }

        this.loading = true;
        this.timer = setTimeout(() => this.fetch(term), DEBOUNCE);
    },

    async fetch(term) {
        this.controller?.abort();
        this.controller = new AbortController();

        try {
            const url = new URL(this.endpoint, window.location.origin);
            url.searchParams.set('q', term);
            const response = await fetch(url, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: this.controller.signal,
            });
            if (! response.ok) throw new Error(response.status);
            const data = await response.json();
            if (term !== this.query.trim()) return;
            this.groups = data.groups ?? [];
            this.searched = term;
            this.failed = false;
            this.active = 0;
            this.loading = false;
        } catch (error) {
            if (error.name === 'AbortError') return;
            this.groups = [];
            this.failed = true;
            this.loading = false;
        }
    },

    move(step) {
        if (! this.total) return;
        this.active = (this.active + step + this.total) % this.total;
        this.$nextTick(() => this.$refs.list?.querySelector(`[data-index="${this.active}"]`)?.scrollIntoView({ block: 'nearest' }));
    },

    go(event) {
        const item = this.sections.flatMap((section) => section.items).find((item) => item.index === this.active);
        if (! item) return;
        // Ctrl/Cmd+Enter membuka di tab baru.
        if (event?.ctrlKey || event?.metaKey) {
            window.open(item.url, '_blank');
            return;
        }
        this.close();
        window.location.href = item.url;
    },

    /** Teks dengan bagian yang cocok ditandai; tiap potongan di-escape supaya aman dari HTML. */
    highlight(text) {
        const words = this.query.trim().split(/\s+/).filter(Boolean).map((word) => word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
        if (! words.length) return escapeHtml(text);

        const pattern = new RegExp(`(${words.join('|')})`, 'gi');
        return String(text ?? '').split(pattern).map((part, i) => (i % 2 ? `<mark>${escapeHtml(part)}</mark>` : escapeHtml(part))).join('');
    },
});
