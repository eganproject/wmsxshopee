/*
 * Form Retur Penjualan (buat / ubah): sumber retur (dari pesanan / tanpa pesanan),
 * cari pesanan lewat resi / no. SO / no. pesanan, scan SKU per barang, dan foto bukti.
 *
 * Dipindah dari x-data inline di blade supaya kutip ganda / komentar di kode
 * tidak bisa memutus atribut HTML (yang membuat kodenya tampil sebagai teks).
 */
export function salesReturnForm(config) {
    return {
        source: config.source,
        warehouse: String(config.warehouse ?? ''),
        tracking: String(config.tracking ?? ''),
        order: config.order,
        choices: [],
        code: '',
        lookupError: '',
        looking: false,
        orderRows: [],
        extraRows: [],
        manualRows: [],
        variants: config.variants,
        locations: config.locations,
        reasons: config.reasons,
        errors: config.errors,
        scan: '',
        scanError: '',
        uid: 0,

        init() {
            const saved = config.items;
            if (this.source === 'order') {
                this.orderRows = this.order ? this.buildOrderRows(saved) : [];
                // Baris tanpa baris pesanan = barang di luar pesanan (kelebihan / barang lain di paket).
                this.extraRows = this.order ? saved.filter((row) => ! row.sales_order_item_id).map((row) => this.row(row)) : [];
                this.manualRows = [this.blankRow()];
            } else {
                this.manualRows = saved.length ? saved.map((row) => this.row(row)) : [this.blankRow()];
            }
        },

        row(data) {
            return { ...config.blank, ...data, uid: ++this.uid, preview: '' };
        },

        blankRow() {
            // Baris baru ikut lokasi bin & alasan baris terakhir supaya tidak perlu pilih ulang.
            const last = this.manualRows[this.manualRows.length - 1];
            return this.row({ warehouse_location_id: last ? last.warehouse_location_id : '', reason: last ? last.reason : '' });
        },

        buildOrderRows(saved = []) {
            return this.order.items.map((line) => {
                const prev = saved.find((row) => String(row.sales_order_item_id) === String(line.id)) || {};
                return this.row({ ...prev, sales_order_item_id: String(line.id), product_variant_id: String(line.product_variant_id), line });
            });
        },

        get rows() {
            return this.source === 'order' ? [...this.orderRows, ...this.extraRows] : this.manualRows;
        },

        addExtra(variantId = '', qty = '') {
            const last = this.extraRows[this.extraRows.length - 1] || this.orderRows[0];
            this.extraRows.push(this.row({
                product_variant_id: String(variantId),
                qty_good: qty,
                warehouse_location_id: last ? last.warehouse_location_id : '',
                reason: last ? last.reason : '',
            }));
        },

        removeExtra(item) {
            this.extraRows = this.extraRows.filter((r) => r.uid !== item.uid);
            this.errors = {};
        },

        get variantOptions() {
            return this.variants.map((v) => ({ value: v.id, text: v.label }));
        },

        get locationOptions() {
            return this.locations
                .filter((l) => String(l.warehouse_id) === String(this.warehouse))
                .map((l) => ({ value: l.id, text: l.code }));
        },

        qty(item) {
            return (parseInt(item.qty_good) || 0) + (parseInt(item.qty_damaged) || 0) + (parseInt(item.qty_missing) || 0);
        },

        totals() {
            const sum = (key) => this.rows.reduce((total, item) => total + (parseInt(item[key]) || 0), 0);
            return { good: sum('qty_good'), damaged: sum('qty_damaged'), missing: sum('qty_missing') };
        },

        setWarehouse(id) {
            this.warehouse = String(id);
            document.getElementById('warehouse_id')?.tomselect?.setValue(this.warehouse, true);
        },

        async lookup() {
            const code = this.code.trim();
            if (! code || this.looking) return;
            this.looking = true;
            this.lookupError = '';
            this.choices = [];
            try {
                const params = new URLSearchParams({ code, except: config.except });
                const response = await fetch(config.lookupUrl + '?' + params, { headers: { Accept: 'application/json' } });
                const data = await response.json();
                if (! response.ok) { this.lookupError = data.message || 'Pesanan tidak ditemukan.'; return; }
                if (data.matched_tracking && ! this.tracking) this.tracking = code;
                if (data.orders.length === 1) this.pick(data.orders[0]); else this.choices = data.orders;
                this.code = '';
            } catch (e) {
                this.lookupError = 'Gagal mencari pesanan. Coba lagi.';
            } finally {
                this.looking = false;
            }
        },

        pick(order) {
            this.order = order;
            this.choices = [];
            this.orderRows = this.buildOrderRows();
            this.extraRows = [];
            this.errors = {};
            this.setWarehouse(order.warehouse_id);
            this.$nextTick(() => this.$refs.itemScan?.focus());
        },

        resetOrder() {
            this.order = null;
            this.orderRows = [];
            this.extraRows = [];
            this.$nextTick(() => this.$refs.orderScan?.focus());
        },

        addScanned() {
            const code = this.scan.trim().toUpperCase();
            if (! code) return;
            const match = (v) => v.sku.toUpperCase() === code || (v.barcode && v.barcode.toUpperCase() === code) || (v.aliases || []).includes(code);
            this.scan = '';
            this.scanError = '';
            if (this.source === 'order') {
                // Scan barang dari paket: jumlah layak jual baris itu bertambah 1, maksimal sisa yang bisa diretur.
                // Barang yang tidak ada di pesanan, atau sudah melebihi jumlah kirim, masuk ke baris "di luar pesanan".
                const rows = this.orderRows.filter((r) => match(r.line));
                const row = rows.find((r) => this.qty(r) < r.line.remaining);
                if (row) { row.qty_good = (parseInt(row.qty_good) || 0) + 1; return; }
                const variant = this.variants.find(match);
                if (! variant) { window.reportScanMiss?.(code, 'return'); this.scanError = `SKU / barcode ${code} tidak ditemukan.`; return; }
                const extra = this.extraRows.find((r) => String(r.product_variant_id) === String(variant.id));
                if (extra) { extra.qty_good = (parseInt(extra.qty_good) || 0) + 1; } else { this.addExtra(variant.id, 1); }
                this.scanError = rows.length
                    ? `${code} sudah melebihi jumlah yang dikirim; kelebihannya dicatat sebagai barang di luar pesanan.`
                    : `${code} tidak ada di pesanan ${this.order.number}; dicatat sebagai barang di luar pesanan.`;
                return;
            }
            const variant = this.variants.find(match);
            if (! variant) { window.reportScanMiss?.(code, 'return'); this.scanError = `SKU / barcode ${code} tidak ditemukan.`; return; }
            const existing = this.manualRows.find((r) => String(r.product_variant_id) === String(variant.id));
            if (existing) { existing.qty_good = (parseInt(existing.qty_good) || 0) + 1; return; }
            const empty = this.manualRows.find((r) => ! r.product_variant_id);
            if (empty) { empty.product_variant_id = String(variant.id); empty.qty_good = empty.qty_good || 1; return; }
            this.manualRows.push({ ...this.blankRow(), product_variant_id: String(variant.id), qty_good: 1 });
        },

        preview(item, event) {
            const file = event.target.files[0];
            item.preview = file ? URL.createObjectURL(file) : '';
        },

        clearPhoto(item) {
            item.photo_path = '';
            item.photo_url = '';
            item.preview = '';
            const input = document.getElementById(`photo-${item.uid}`);
            if (input) input.value = '';
        },
    };
}
