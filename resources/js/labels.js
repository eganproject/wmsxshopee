import { enhanceSelect } from './form-controls';

/*
 * Halaman Cetak Label: daftar label (item + jumlah), pengaturan, dan pratinjau.
 * Pratinjau memakai halaman cetak yang sama (?preview=1) di dalam iframe,
 * jadi yang terlihat persis dengan hasil cetak.
 */
export function labelBuilder(config) {
    return {
        items: config.items.map((item) => ({ ...item, qty: 1 })),
        sizes: config.sizes,
        size: config.size,
        options: { ...config.options },
        bulkQty: 1,
        start: 1,
        flashKey: null,
        loading: false,

        init() {
            // Pencarian lewat data-remote; opsi hasil cari membawa data item lengkap.
            const search = enhanceSelect(this.$refs.search, {
                placeholder: this.$refs.search.dataset.placeholder,
                plugins: {},
                onChange: (value) => {
                    const option = value && search.options[value];
                    if (option) {
                        this.add(option.item);
                    }
                    search.clear(true);
                    search.clearOptions();
                },
            });
        },

        get sheet() {
            return this.sizes[this.size]?.sheet ?? null;
        },

        get perSheet() {
            return this.sheet ? this.sheet.columns * this.sheet.rows : 1;
        },

        get total() {
            return this.items.reduce((sum, item) => sum + Math.max(0, Number(item.qty) || 0), 0);
        },

        get pageCount() {
            const offset = this.sheet ? Math.max(0, (this.start || 1) - 1) : 0;

            return Math.ceil((this.total + offset) / this.perSheet);
        },

        get previewRatio() {
            const size = this.sizes[this.size];

            return `${size.width} / ${size.height}`;
        },

        get previewUrl() {
            const first = this.items.find((item) => Number(item.qty) > 0) ?? this.items[0];
            if (! first) {
                return null;
            }

            const params = new URLSearchParams({ size: this.size, preview: '1', [`qty[${first.key}]`]: '1' });
            Object.entries(this.options).forEach(([key, value]) => {
                params.set(key, typeof value === 'boolean' ? (value ? '1' : '0') : value);
            });

            return `${config.printUrl}?${params}`;
        },

        add(item) {
            const existing = this.items.find((row) => row.key === item.key);
            if (existing) {
                this.flash(existing.key);

                return;
            }
            this.items.push({ ...item, qty: this.bulkQty > 0 ? this.bulkQty : 1 });
        },

        addRack() {
            const rack = this.$refs.rack.value;
            if (! rack) {
                return;
            }

            this.loading = true;
            window.axios.get(config.lookupUrl, { params: { rack } })
                .then(({ data }) => data.forEach((option) => {
                    if (! this.items.some((row) => row.key === option.value)) {
                        this.items.push({ ...option.item, qty: this.bulkQty > 0 ? this.bulkQty : 1 });
                    }
                }))
                .finally(() => {
                    this.loading = false;
                });
        },

        applyBulkQty() {
            const qty = Math.max(0, Math.min(999, Number(this.bulkQty) || 0));
            this.items.forEach((item) => {
                item.qty = qty;
            });
        },

        flash(key) {
            this.flashKey = key;
            setTimeout(() => {
                if (this.flashKey === key) {
                    this.flashKey = null;
                }
            }, 1200);
        },
    };
}
