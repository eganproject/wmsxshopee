/*
 * Halaman mobile Optima (/m) yang dimuat aplikasi Android. Tiap halaman fitur adalah
 * komponen Alpine di bawah. Scan datang dari kamera aplikasi, scanner fisik, atau
 * kolom ketik, semuanya lewat satu jalur: bridge.on('scan').
 */
import Alpine from 'alpinejs';
import { ApiError, getJson, refreshSession, request } from './mobile/api';
import * as bridge from './mobile/bridge';
import { catalog, resolveCode, variantLabel } from './mobile/catalog';
import { kvGet, kvSet } from './mobile/db';
import * as outbox from './mobile/outbox';
import * as printer from './mobile/printer';

window.Alpine = Alpine;

/* ---------- Utilitas ---------- */

const norm = (v) => String(v ?? '').trim().toLowerCase();
const warehouseId = () => document.querySelector('meta[name="warehouse-id"]')?.content || '';

function toast(message, kind = 'info', ms = 3200) {
    Alpine.store('ui').toast(message, kind, ms);
}

/** Perkecil foto kamera (maks. 1600 px, JPEG) supaya cepat terkirim & hemat antrean offline. */
async function shrinkPhoto(file, max = 1600) {
    if (!file || !file.type?.startsWith('image/')) return file;
    try {
        const bitmap = await createImageBitmap(file);
        const scale = Math.min(1, max / Math.max(bitmap.width, bitmap.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.82));
        return blob ? new File([blob], 'foto.jpg', { type: 'image/jpeg' }) : file;
    } catch (e) {
        return file;
    }
}

/*
 * Penerima scan: halaman fitur mendaftarkan handler-nya; tanpa handler (beranda, dsb.)
 * scan membuka halaman hasil scan universal.
 */
const scanHandlers = [];
let scanOptions = {};

export function useScan(handler, options = {}) {
    scanHandlers.push(handler);
    scanOptions = options;
}

bridge.on('scan', ({ code }) => {
    if (!code) return;
    const handler = scanHandlers[scanHandlers.length - 1];
    if (handler) {
        handler(code.trim());
    } else {
        window.location.href = `/m/scan?code=${encodeURIComponent(code.trim())}`;
    }
});

function openScanner(options = {}) {
    bridge.openScanner({ ...scanOptions, ...options });
}

/** $scan() di template: buka kamera scan dengan petunjuk halaman aktif. */
Alpine.magic('scan', () => (options = {}) => openScanner(options));

/* ---------- Store global ---------- */

Alpine.store('ui', {
    toasts: [],
    toast(message, kind = 'info', ms = 3200) {
        const id = Math.random();
        this.toasts.push({ id, message, kind });
        setTimeout(() => (this.toasts = this.toasts.filter((t) => t.id !== id)), ms);
    },
});

Alpine.store('net', {
    online: navigator.onLine,
    queued: 0,
    failed: 0,
});

window.addEventListener('online', () => (Alpine.store('net').online = true));
window.addEventListener('offline', () => (Alpine.store('net').online = false));
bridge.on('outbox', ({ count, failed }) => {
    Alpine.store('net').queued = count;
    Alpine.store('net').failed = failed;
});
bridge.on('outbox-sent', ({ item }) => toast(`Terkirim: ${item.label}`, 'ok', 2200));

/* ---------- Kerangka: menu bawah, tombol scan, kolom scan cadangan ---------- */

Alpine.data('mobileShell', () => ({
    fallbackOpen: false,
    fallbackHint: '',
    fallbackCode: '',
    init() {
        outbox.refreshCount();
        outbox.flush();
        bridge.on('scanner-fallback', (options) => {
            this.fallbackHint = options.hint || 'Ketik atau tempel kode';
            this.fallbackCode = '';
            this.fallbackOpen = true;
            this.$nextTick(() => this.$refs.fallbackInput?.focus());
        });
        // Warna status bar aplikasi mengikuti tema halaman.
        const syncBars = () => {
            const dark = document.documentElement.classList.contains('dark');
            bridge.setSystemBars(dark ? '#121212' : '#f5f5f5', !dark);
        };
        syncBars();
        bridge.on('theme', () => {
            window.optimaTheme?.apply();
            syncBars();
        });
        new MutationObserver(syncBars).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    },
    scan() {
        openScanner();
    },
    submitFallback() {
        const code = this.fallbackCode.trim();
        if (!code) return;
        this.fallbackOpen = false;
        bridge.emit('scan', { code, format: 'manual' });
    },
}));

/* ---------- Beranda ---------- */

Alpine.data('mobileHome', (latest) => ({
    update: null,
    init() {
        const info = bridge.appInfo();
        if (info && latest && Number(latest.versionCode) > Number(info.versionCode)) {
            this.update = latest;
        }
        // Siapkan data barang & antrean QC di HP selagi online.
        catalog().catch(() => null);
    },
    download() {
        bridge.openExternal(this.update.url);
    },
}));

/* ---------- QC Kemas: resi dulu, lalu SKU ---------- */

Alpine.data('qcApp', () => ({
    step: 'find',
    code: '',
    queue: [],
    order: null,
    scanned: {},
    alert: null,
    last: null,
    photo: null,
    photoUrl: null,
    busy: false,
    loadingQueue: false,

    async init() {
        useScan((code) => (this.step === 'find' ? this.find(code) : this.scanItem(code)), { hint: 'Scan resi atau no. pesanan' });
        this.queue = (await kvGet(`qc-queue-${warehouseId()}`).catch(() => null))?.orders || [];
        this.refreshQueue();
    },

    async refreshQueue() {
        if (!navigator.onLine) return;
        this.loadingQueue = true;
        try {
            const data = await getJson('/m/qc/queue');
            this.queue = data.orders;
            await kvSet(`qc-queue-${warehouseId()}`, data);
        } catch (e) {
            // tetap pakai antrean tersimpan
        } finally {
            this.loadingQueue = false;
        }
    },

    openCamera() {
        openScanner({ hint: this.step === 'find' ? 'Scan resi atau no. pesanan' : 'Scan SKU / barcode barang' });
    },

    async find(raw) {
        const code = String(raw || this.code).trim();
        if (!code) return;
        this.code = '';
        const key = norm(code);
        let order = this.queue.find((o) => [o.tracking, o.number, o.external].some((v) => norm(v) === key));

        if (!order) {
            const local = await resolveCode(code).catch(() => null);
            if (local && local.type !== 'location') {
                bridge.feedback('error');
                this.alert = { kind: 'bad', title: 'Scan resi dulu', text: 'Ini barcode barang. Mulai QC dengan scan resi atau no. pesanan, baru scan barangnya.' };
                return;
            }
            if (navigator.onLine) {
                try {
                    order = (await getJson(`/m/qc/find?code=${encodeURIComponent(code)}`)).order;
                } catch (e) {
                    bridge.feedback('error');
                    this.alert = { kind: 'bad', title: 'Pesanan tidak bisa di-QC', text: e.message };
                    return;
                }
            } else {
                bridge.feedback('error');
                this.alert = { kind: 'bad', title: 'Tidak ada di antrean offline', text: `"${code}" belum tersimpan di HP. Coba lagi saat online.` };
                return;
            }
        }

        bridge.feedback('ok');
        this.order = order;
        this.scanned = Object.fromEntries(order.lines.map((l) => [l.variant_id, 0]));
        this.alert = null;
        this.last = null;
        this.photo = null;
        this.photoUrl = null;
        this.step = 'items';
        scanOptions = { hint: 'Scan SKU / barcode barang' };
    },

    lineFor(code) {
        const key = norm(code);
        return this.order.lines.find((l) => norm(l.sku) === key || (l.barcode && norm(l.barcode) === key));
    },

    scanItem(raw) {
        const code = String(raw || this.code).trim();
        if (!code) return;
        this.code = '';
        const key = norm(code);

        if ([this.order.tracking, this.order.number, this.order.external].some((v) => norm(v) === key)) {
            bridge.feedback('warn');
            this.alert = { kind: 'warn', title: 'Resi sudah discan', text: 'Sekarang scan barang satu per satu.' };
            return;
        }

        const bundle = this.order.bundles?.[key];
        if (bundle) {
            const over = Object.entries(bundle).some(([id, qty]) => (this.scanned[id] || 0) + qty > (this.order.lines.find((l) => String(l.variant_id) === String(id))?.qty || 0));
            if (over) return this.reject('Paket ini sudah lengkap discan', code);
            Object.entries(bundle).forEach(([id, qty]) => (this.scanned[id] = (this.scanned[id] || 0) + qty));
            return this.accept(code, 'Isi paket ditambahkan');
        }

        const line = this.lineFor(code);
        if (!line) return this.reject(`Barang salah: ${code}`, code, 'Tidak ada di pesanan ini. Keluarkan dari paket.');
        if ((this.scanned[line.variant_id] || 0) >= line.qty) return this.reject(`${line.sku} sudah cukup`, code, `Pesanan hanya ${line.qty}. Jangan masukkan lagi.`);

        this.scanned[line.variant_id] = (this.scanned[line.variant_id] || 0) + 1;
        this.accept(code, line.name);
    },

    accept(code, text) {
        this.alert = null;
        this.last = { code, text };
        bridge.feedback(this.complete ? 'ok' : 'scan');
        if (this.complete) toast('Semua barang cocok. Foto paket lalu selesaikan.', 'ok');
    },

    reject(title, code, text = '') {
        bridge.feedback('error');
        this.alert = { kind: 'bad', title, text };
    },

    adjust(line, delta) {
        const next = Math.max(0, Math.min(line.qty, (this.scanned[line.variant_id] || 0) + delta));
        this.scanned[line.variant_id] = next;
    },

    get done() {
        return this.order ? this.order.lines.reduce((sum, l) => sum + Math.min(this.scanned[l.variant_id] || 0, l.qty), 0) : 0;
    },
    get total() {
        return this.order ? this.order.lines.reduce((sum, l) => sum + l.qty, 0) : 0;
    },
    get complete() {
        return this.order && this.order.lines.every((l) => (this.scanned[l.variant_id] || 0) === l.qty);
    },
    get percent() {
        return this.total ? Math.round((this.done / this.total) * 100) : 0;
    },

    async pickPhoto(event) {
        const file = event.target.files?.[0];
        event.target.value = '';
        if (!file) return;
        this.photo = await shrinkPhoto(file);
        this.photoUrl = URL.createObjectURL(this.photo);
    },

    removePhoto() {
        this.photo = null;
        this.photoUrl = null;
    },

    async finish() {
        if (!this.complete || this.busy) return;
        this.busy = true;
        const order = this.order;
        const body = new FormData();
        Object.entries(this.scanned).forEach(([id, qty]) => body.append(`scanned[${id}]`, qty));
        if (this.photo) body.append('photo', this.photo, 'paket.jpg');

        try {
            const result = await outbox.send({ url: order.store_url, body, label: `QC ${order.number}`, kind: 'qc' });
            this.queue = this.queue.filter((o) => o.id !== order.id);
            kvSet(`qc-queue-${warehouseId()}`, { orders: this.queue }).catch(() => null);

            if (result.queued) {
                toast(`QC ${order.number} disimpan di HP, dikirim saat online.`, 'warn', 4000);
            } else {
                toast(result.data.message, 'ok');
                if (result.data.label_url && printer.settings().autoQc && printer.settings().address) {
                    printer.printUrl(result.data.label_url).then(() => toast('Resi dicetak.', 'ok')).catch((e) => toast(e.message, 'bad', 5000));
                }
            }
            this.reset();
        } catch (e) {
            bridge.feedback('error');
            this.alert = { kind: 'bad', title: 'QC belum tersimpan', text: e.message };
        } finally {
            this.busy = false;
        }
    },

    reset() {
        this.step = 'find';
        this.order = null;
        this.alert = null;
        this.last = null;
        this.photo = null;
        this.photoUrl = null;
        scanOptions = { hint: 'Scan resi atau no. pesanan' };
    },
}));

/* ---------- Serah terima kurir: scan beruntun ---------- */

Alpine.data('handoverApp', () => ({
    code: '',
    results: [],
    camera: false,
    sent: 0,

    init() {
        useScan((code) => this.scan(code), { mode: 'top', continuous: true, hint: 'Scan resi paket' });
        bridge.on('scanner', ({ open }) => (this.camera = open));
    },

    toggleCamera() {
        this.camera ? bridge.closeScanner() : openScanner();
        if (!bridge.isApp) return;
        this.camera = !this.camera;
    },

    async scan(raw) {
        const code = String(raw || this.code).trim();
        this.code = '';
        if (!code) return;
        if (this.results.some((r) => norm(r.code) === norm(code) && r.status !== 'bad')) {
            bridge.feedback('warn');
            this.results.unshift({ id: Math.random(), code, status: 'warn', text: 'Sudah discan barusan', at: new Date() });
            return;
        }

        const row = { id: Math.random(), code, status: 'sending', text: 'Mengirim…', at: new Date() };
        this.results.unshift(row);
        try {
            const result = await outbox.send({ url: '/m/handover/scan', body: { code }, label: `Serah terima ${code}`, kind: 'handover' });
            const item = this.results.find((r) => r.id === row.id);
            if (result.queued) {
                Object.assign(item, { status: 'queued', text: 'Tersimpan di HP, dikirim saat online' });
                bridge.feedback('scan');
            } else {
                const o = result.data.order;
                Object.assign(item, { status: 'ok', text: `${o.customer} · ${o.courier}`, number: o.number });
                this.sent += 1;
                bridge.feedback('ok');
            }
        } catch (e) {
            const item = this.results.find((r) => r.id === row.id);
            Object.assign(item, { status: 'bad', text: e.message });
            bridge.feedback('error');
        }
    },

    time(date) {
        const s = Math.round((Date.now() - date.getTime()) / 1000);
        return s < 5 ? 'baru saja' : s < 60 ? `${s} dtk` : `${Math.round(s / 60)} mnt`;
    },
}));

/* ---------- Tanda tangan kurir ---------- */

Alpine.data('signApp', (config) => ({
    name: config.receiver || '',
    drawn: false,
    busy: false,
    signed: !!config.signed,
    manifest: null,
    ctx: null,

    init() {
        const canvas = this.$refs.pad;
        const ratio = window.devicePixelRatio || 1;
        const resize = () => {
            canvas.width = canvas.offsetWidth * ratio;
            canvas.height = canvas.offsetHeight * ratio;
            this.ctx = canvas.getContext('2d');
            this.ctx.scale(ratio, ratio);
            this.ctx.lineWidth = 2.6;
            this.ctx.lineCap = 'round';
            this.ctx.lineJoin = 'round';
            this.ctx.strokeStyle = '#111';
        };
        resize();
        let drawing = false;
        const point = (e) => {
            const rect = canvas.getBoundingClientRect();
            return [e.clientX - rect.left, e.clientY - rect.top];
        };
        canvas.addEventListener('pointerdown', (e) => {
            drawing = true;
            canvas.setPointerCapture(e.pointerId);
            this.ctx.beginPath();
            this.ctx.moveTo(...point(e));
        });
        canvas.addEventListener('pointermove', (e) => {
            if (!drawing) return;
            this.ctx.lineTo(...point(e));
            this.ctx.stroke();
            this.drawn = true;
        });
        ['pointerup', 'pointercancel'].forEach((ev) => canvas.addEventListener(ev, () => (drawing = false)));
    },

    clear() {
        const canvas = this.$refs.pad;
        this.ctx.clearRect(0, 0, canvas.width, canvas.height);
        this.drawn = false;
    },

    /** Tanda tangan diekspor hitam di atas putih (aman untuk tema gelap & printer). */
    signatureData() {
        const src = this.$refs.pad;
        const out = document.createElement('canvas');
        out.width = src.width;
        out.height = src.height;
        const ctx = out.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, out.width, out.height);
        ctx.drawImage(src, 0, 0);
        return out;
    },

    async save() {
        if (!this.name.trim()) return toast('Isi nama kurir dulu.', 'warn');
        if (!this.drawn) return toast('Minta kurir tanda tangan dulu.', 'warn');
        this.busy = true;
        try {
            const sig = this.signatureData();
            const data = await request(config.signUrl, { method: 'POST', body: { receiver_name: this.name.trim(), signature: sig.toDataURL('image/png') } });
            this.manifest = data.manifest;
            this.signed = true;
            this.sigCanvas = sig;
            toast(data.message, 'ok');
            if (printer.settings().address) this.printManifest();
        } catch (e) {
            toast(e.message, 'bad', 5000);
        } finally {
            this.busy = false;
        }
    },

    async printManifest() {
        try {
            if (!this.manifest) this.manifest = (await getJson(config.manifestUrl)).manifest;
            let sig = this.sigCanvas || null;
            if (!sig && this.manifest.signature_url) {
                sig = await new Promise((resolve) => {
                    const img = new Image();
                    img.onload = () => resolve(img);
                    img.onerror = () => resolve(null);
                    img.src = this.manifest.signature_url;
                });
            }
            await printer.printCanvas(printer.manifestReceipt(this.manifest, sig));
            toast('Manifest dicetak.', 'ok');
        } catch (e) {
            toast(e.message, 'bad', 5000);
        }
    },
}));

/* ---------- Penerimaan barang (bin opsional) ---------- */

Alpine.data('receiptApp', (config) => ({
    data: null,
    supplier: '',
    reference: '',
    notes: '',
    photo: null,
    photoUrl: null,
    lines: [],
    active: null,
    code: '',
    busy: false,
    result: null,
    confirmed: {},

    async init() {
        useScan((code) => this.scan(code), { hint: 'Scan barang atau kode rak' });
        try {
            this.data = await catalog();
        } catch (e) {
            toast(e.message, 'bad', 5000);
        }
    },

    get suppliers() {
        return this.data?.suppliers || [];
    },
    get locations() {
        return this.data ? Array.from(this.data.locationById.values()) : [];
    },

    async scan(raw) {
        const code = String(raw || this.code).trim();
        this.code = '';
        if (!code) return;
        if (this.result) return this.confirmPutaway(code);
        const hit = await resolveCode(code).catch(() => null);
        if (!hit) {
            bridge.feedback('error');
            return toast(`Kode "${code}" tidak dikenal.`, 'bad');
        }
        if (hit.type === 'location') {
            if (this.active === null) {
                bridge.feedback('warn');
                return toast('Pilih barangnya dulu, lalu scan rak tujuannya.', 'warn');
            }
            this.lines[this.active].location_id = hit.item.id;
            bridge.feedback('ok');
            return toast(`${this.lines[this.active].sku} → ${hit.item.code}`, 'ok', 1800);
        }
        if (hit.type === 'bundle') {
            hit.item.items.forEach((i) => this.add(this.data.variants.get(i.variant_id), i.qty));
        } else {
            this.add(hit.item, 1);
        }
        bridge.feedback('scan');
    },

    add(variant, qty) {
        if (!variant) return;
        const index = this.lines.findIndex((l) => l.variant_id === variant.id);
        if (index >= 0) {
            this.lines[index].received += qty;
            this.active = index;
            return;
        }
        this.lines.unshift({
            variant_id: variant.id,
            sku: variant.sku,
            name: variantLabel(variant),
            received: qty,
            damaged: 0,
            // Lokasi tetap SKU jadi saran awal; boleh dikosongkan (stok masuk tanpa bin).
            location_id: variant.bins?.[0] ?? '',
        });
        this.active = 0;
    },

    remove(index) {
        this.lines.splice(index, 1);
        this.active = null;
    },

    locationCode(id) {
        return id ? this.data?.locationById.get(Number(id))?.code : null;
    },

    get totalReceived() {
        return this.lines.reduce((s, l) => s + Number(l.received || 0), 0);
    },

    async pickPhoto(event) {
        const file = event.target.files?.[0];
        event.target.value = '';
        if (!file) return;
        this.photo = await shrinkPhoto(file);
        this.photoUrl = URL.createObjectURL(this.photo);
    },

    async submit(action) {
        if (!this.supplier) return toast('Pilih supplier dulu.', 'warn');
        if (!this.lines.length) return toast('Scan barang yang diterima dulu.', 'warn');
        this.busy = true;
        const body = new FormData();
        body.append('supplier_id', this.supplier);
        body.append('warehouse_id', warehouseId());
        body.append('supplier_reference', this.reference);
        body.append('notes', this.notes);
        body.append('action', action);
        if (this.photo) body.append('attachment', this.photo, 'surat-jalan.jpg');
        this.lines.forEach((l, i) => {
            body.append(`items[${i}][product_variant_id]`, l.variant_id);
            body.append(`items[${i}][qty_received]`, l.received || 0);
            body.append(`items[${i}][qty_damaged]`, l.damaged || 0);
            body.append(`items[${i}][warehouse_location_id]`, l.location_id || '');
        });
        try {
            const result = await outbox.send({ url: '/m/receipts', body, label: `Penerimaan ${this.lines.length} barang`, kind: 'receipt' });
            if (result.queued) {
                toast('Penerimaan disimpan di HP, dikirim saat online.', 'warn', 4000);
                setTimeout(() => (window.location.href = config.indexUrl), 900);
                return;
            }
            toast(result.data.message, 'ok', 4000);
            this.result = result.data;
            scanOptions = { hint: 'Scan label rak untuk konfirmasi' };
            if (!result.data.putaway?.length) setTimeout(() => (window.location.href = config.indexUrl), 1500);
        } catch (e) {
            bridge.feedback('error');
            toast(e.message, 'bad', 6000);
        } finally {
            this.busy = false;
        }
    },

    confirmPutaway(code) {
        const row = (this.result.putaway || []).find((p) => norm(p.location) === norm(code) && !this.confirmed[p.sku + p.location]);
        if (!row) {
            bridge.feedback('error');
            return toast(`Rak ${code} bukan tujuan barang mana pun di sini.`, 'bad');
        }
        this.confirmed[row.sku + row.location] = true;
        bridge.feedback('ok');
        toast(`${row.sku} disimpan di ${row.location}`, 'ok', 1800);
    },
}));

/* Halaman detail penerimaan: panduan putaway dengan scan rak. */
Alpine.data('putawayApp', (rows) => ({
    rows,
    confirmed: {},
    init() {
        useScan((code) => {
            const row = this.rows.find((p) => norm(p.location) === norm(code) && !this.confirmed[p.sku + p.location]);
            if (!row) {
                bridge.feedback('error');
                return toast(`Rak ${code} bukan tujuan barang di penerimaan ini.`, 'bad');
            }
            this.confirmed[row.sku + row.location] = true;
            bridge.feedback('ok');
        }, { hint: 'Scan label rak tujuan' });
    },
    get remaining() {
        return this.rows.filter((r) => !this.confirmed[r.sku + r.location]).length;
    },
}));

/* ---------- Stock opname per rak ---------- */

Alpine.data('opnameApp', (config) => ({
    lines: [],
    bin: null,
    blind: true,
    touched: {},
    busy: false,
    code: '',
    loaded: false,

    async init() {
        useScan((code) => this.scan(code), { hint: 'Scan rak lalu barangnya' });
        const key = `opname-${config.id}`;
        const stored = await kvGet(key).catch(() => null);
        if (stored) this.lines = stored.lines;
        if (navigator.onLine) {
            try {
                const data = await getJson(config.linesUrl);
                // Pertahankan hitungan yang belum terkirim.
                const pending = Object.fromEntries((stored?.lines || []).filter((l) => l.dirty).map((l) => [l.key, l]));
                this.lines = data.lines.map((l) => {
                    const row = { ...l, key: `${l.variant_id}:${l.location_id ?? 'none'}` };
                    return pending[row.key] ? { ...row, counted_qty: pending[row.key].counted_qty, dirty: true } : row;
                });
                Object.values(pending).filter((p) => !this.lines.some((l) => l.key === p.key)).forEach((p) => this.lines.push(p));
                this.persist();
            } catch (e) {
                toast(e.message, 'bad');
            }
        }
        this.loaded = true;
        catalog().catch(() => null);
    },

    persist() {
        kvSet(`opname-${config.id}`, { lines: JSON.parse(JSON.stringify(this.lines)) }).catch(() => null);
    },

    get bins() {
        const map = new Map();
        this.lines.forEach((l) => {
            const k = l.location ?? 'Tanpa bin';
            const entry = map.get(k) || { code: k, id: l.location_id, total: 0, counted: 0 };
            entry.total += 1;
            if (l.counted_qty !== null && l.counted_qty !== undefined) entry.counted += 1;
            map.set(k, entry);
        });
        return Array.from(map.values());
    },

    get binLines() {
        if (!this.bin) return [];
        return this.lines.filter((l) => (l.location_id ?? null) === (this.bin.id ?? null));
    },

    get dirtyCount() {
        return this.lines.filter((l) => l.dirty).length;
    },

    openBin(entry) {
        this.bin = { id: entry.id ?? null, code: entry.code };
    },

    async scan(raw) {
        const code = String(raw || this.code).trim();
        this.code = '';
        if (!code) return;
        const hit = await resolveCode(code).catch(() => null);
        const line = this.lines.find((l) => norm(l.location) === norm(code));
        if (hit?.type === 'location' || line) {
            const loc = hit?.item || { id: line.location_id, code: line.location };
            this.bin = { id: loc.id, code: loc.code };
            bridge.feedback('ok');
            return;
        }
        if (!this.bin) {
            bridge.feedback('warn');
            return toast('Scan kode rak dulu, baru barangnya.', 'warn');
        }
        const variantId = hit?.type === 'variant' ? hit.item.id : null;
        let row = this.binLines.find((l) => (variantId && l.variant_id === variantId) || norm(l.sku) === norm(code) || (l.barcode && norm(l.barcode) === norm(code)));
        if (!row) {
            if (!variantId) {
                bridge.feedback('error');
                return toast(`Barang "${code}" tidak dikenal.`, 'bad');
            }
            row = {
                id: null, variant_id: variantId, sku: hit.item.sku, barcode: hit.item.barcode, name: variantLabel(hit.item),
                location_id: this.bin.id, location: this.bin.id ? this.bin.code : null, system_qty: 0, counted_qty: null,
                key: `${variantId}:${this.bin.id ?? 'none'}`, found: true,
            };
            this.lines.push(row);
            row = this.lines[this.lines.length - 1];
            toast(`${row.sku} belum tercatat di rak ini, ditambahkan.`, 'warn');
        }
        this.set(row, (row.counted_qty ?? 0) + 1);
        bridge.feedback('scan');
    },

    set(row, value) {
        row.counted_qty = Math.max(0, Number.isFinite(+value) ? +value : 0);
        row.dirty = true;
        this.persist();
    },

    diff(row) {
        return row.counted_qty === null || row.counted_qty === undefined ? null : row.counted_qty - row.system_qty;
    },

    async save(action = 'save') {
        const dirty = this.lines.filter((l) => l.dirty);
        if (!dirty.length && action !== 'post') return toast('Belum ada hitungan baru.', 'warn');
        this.busy = true;
        const body = {
            action,
            lines: dirty.map((l) => ({ id: l.id, product_variant_id: l.variant_id, warehouse_location_id: l.location_id, counted_qty: l.counted_qty })),
        };
        try {
            const result = await outbox.send({ url: config.saveUrl, body, label: `Opname ${config.number} (${dirty.length} barang)`, kind: 'opname' });
            dirty.forEach((l) => (l.dirty = false));
            this.persist();
            toast(result.queued ? 'Hitungan disimpan di HP, dikirim saat online.' : result.data.message, result.queued ? 'warn' : 'ok', 3500);
            if (!result.queued && result.data.posted) window.location.href = '/m/opname';
            this.bin = null;
        } catch (e) {
            toast(e.message, 'bad', 6000);
        } finally {
            this.busy = false;
        }
    },
}));

/* ---------- Cek stok rak -> penyesuaian menunggu approval ---------- */

Alpine.data('stockCheckApp', (config) => ({
    location: null,
    items: [],
    code: '',
    reason: '',
    notes: '',
    busy: false,
    loading: false,

    init() {
        useScan((code) => this.scan(code), { hint: 'Scan kode rak' });
        catalog().catch(() => null);
    },

    async scan(raw) {
        const code = String(raw || this.code).trim();
        this.code = '';
        if (!code) return;
        const hit = await resolveCode(code).catch(() => null);
        if (!this.location || hit?.type === 'location') return this.openBin(code, hit);

        const variant = hit?.type === 'variant' ? hit.item : null;
        let row = this.items.find((i) => (variant && i.variant_id === variant.id) || norm(i.sku) === norm(code) || (i.barcode && norm(i.barcode) === norm(code)));
        if (!row) {
            if (!variant) {
                bridge.feedback('error');
                return toast(`Barang "${code}" tidak dikenal.`, 'bad');
            }
            this.items.push({ variant_id: variant.id, sku: variant.sku, barcode: variant.barcode, name: variantLabel(variant), system_qty: 0, counted: null, found: true });
            row = this.items[this.items.length - 1];
        }
        row.counted = (row.counted ?? 0) + 1;
        bridge.feedback('scan');
    },

    async openBin(code, hit) {
        this.loading = true;
        try {
            const data = await getJson(`/m/stock-check/bin?code=${encodeURIComponent(code)}`);
            this.location = data.location;
            this.items = data.items.map((i) => ({ ...i, counted: null }));
        } catch (e) {
            if (e instanceof ApiError || hit?.type !== 'location') {
                bridge.feedback('error');
                toast(e.message, 'bad');
                return;
            }
            // Offline: pakai stok yang tersimpan di HP.
            const data = await catalog();
            this.location = { id: hit.item.id, code: hit.item.code };
            this.items = data.stock.filter(([, loc]) => loc === hit.item.id).map(([vid, , qty]) => {
                const v = data.variants.get(vid);
                return { variant_id: vid, sku: v?.sku, barcode: v?.barcode, name: v ? variantLabel(v) : `#${vid}`, system_qty: qty, counted: null };
            });
            toast('Offline: stok sistem dari data tersimpan di HP.', 'warn');
        } finally {
            this.loading = false;
        }
        bridge.feedback('ok');
    },

    diff(row) {
        return row.counted === null ? null : row.counted - row.system_qty;
    },

    get changes() {
        return this.items.filter((i) => i.counted !== null && i.counted !== i.system_qty);
    },

    get counted() {
        return this.items.filter((i) => i.counted !== null).length;
    },

    get autoReason() {
        const d = this.changes.map((i) => this.diff(i));
        if (d.length && d.every((x) => x < 0)) return 'lost';
        if (d.length && d.every((x) => x > 0)) return 'found';
        return 'input_error';
    },

    async submit() {
        if (!this.changes.length) {
            toast('Semua cocok, tidak ada selisih. Rak berikutnya!', 'ok');
            return this.reset();
        }
        this.busy = true;
        const reason = this.reason || this.autoReason;
        const body = {
            reason,
            reference: `Cek rak ${this.location.code}`,
            notes: this.notes || (reason === 'other' ? 'Cek stok rak dari aplikasi' : ''),
            items: this.changes.map((i) => ({ product_variant_id: i.variant_id, warehouse_location_id: this.location.id, qty: this.diff(i) })),
        };
        try {
            const result = await outbox.send({ url: '/m/stock-check', body, label: `Cek rak ${this.location.code}`, kind: 'adjustment' });
            toast(result.queued ? 'Disimpan di HP, diajukan saat online.' : result.data.message, result.queued ? 'warn' : 'ok', 4000);
            this.reset();
        } catch (e) {
            toast(e.message, 'bad', 6000);
        } finally {
            this.busy = false;
        }
    },

    reset() {
        this.location = null;
        this.items = [];
        this.reason = '';
        this.notes = '';
    },
}));

/* ---------- Retur: layak jual / rusak + foto ---------- */

Alpine.data('returnApp', (config) => ({
    code: '',
    order: null,
    rows: [],
    notes: '',
    busy: false,
    loading: false,
    data: null,

    async init() {
        useScan((code) => (this.order ? toast('Selesaikan retur ini dulu, atau tekan Ganti.', 'warn') : this.lookup(code)), { hint: 'Scan resi retur' });
        this.data = await catalog().catch(() => null);
    },

    async lookup(raw) {
        const code = String(raw || this.code).trim();
        this.code = '';
        if (!code) return;
        if (!navigator.onLine) return toast('Cari pesanan retur butuh internet.', 'warn');
        this.loading = true;
        try {
            const data = await getJson(`/sales/returns/lookup?code=${encodeURIComponent(code)}`);
            const order = data.orders[0];
            this.order = { ...order, scanned: code };
            this.rows = order.items.filter((i) => i.remaining > 0).map((i) => ({
                ...i,
                condition: 'good',
                qty: i.remaining,
                reason: '',
                photo: null,
                photoUrl: null,
                location_id: this.data?.variants.get(i.product_variant_id)?.bins?.[0] ?? '',
            }));
            if (!this.rows.length) toast(`Semua barang ${order.number} sudah diretur sebelumnya.`, 'warn', 5000);
            bridge.feedback('ok');
        } catch (e) {
            bridge.feedback('error');
            toast(e.message, 'bad', 5000);
        } finally {
            this.loading = false;
        }
    },

    locationCode(id) {
        return id ? this.data?.locationById.get(Number(id))?.code : null;
    },

    get locations() {
        return this.data ? Array.from(this.data.locationById.values()) : [];
    },

    async pickPhoto(row, event) {
        const file = event.target.files?.[0];
        event.target.value = '';
        if (!file) return;
        row.photo = await shrinkPhoto(file);
        row.photoUrl = URL.createObjectURL(row.photo);
    },

    get ready() {
        return this.rows.some((r) => r.qty > 0) && this.rows.every((r) => r.qty === 0 || r.condition === 'missing' || r.reason);
    },

    async submit(action) {
        if (!this.ready) return toast('Pilih alasan retur untuk tiap barang.', 'warn');
        this.busy = true;
        const body = new FormData();
        body.append('sales_order_id', this.order.id);
        body.append('warehouse_id', this.order.warehouse_id);
        body.append('tracking_number', this.order.scanned);
        body.append('notes', this.notes);
        body.append('action', action);
        this.rows.filter((r) => r.qty > 0).forEach((r, i) => {
            body.append(`items[${i}][sales_order_item_id]`, r.id);
            body.append(`items[${i}][qty_good]`, r.condition === 'good' ? r.qty : 0);
            body.append(`items[${i}][qty_damaged]`, r.condition === 'damaged' ? r.qty : 0);
            body.append(`items[${i}][qty_missing]`, r.condition === 'missing' ? r.qty : 0);
            body.append(`items[${i}][reason]`, r.condition === 'missing' ? 'missing' : r.reason);
            body.append(`items[${i}][warehouse_location_id]`, r.condition === 'good' ? r.location_id || '' : '');
            if (r.photo) body.append(`items[${i}][photo]`, r.photo, `retur-${i}.jpg`);
        });
        try {
            const result = await outbox.send({ url: '/m/returns', body, label: `Retur ${this.order.number}`, kind: 'return' });
            toast(result.queued ? 'Retur disimpan di HP, dikirim saat online.' : result.data.message, result.queued ? 'warn' : 'ok', 4500);
            setTimeout(() => window.location.reload(), 1200);
        } catch (e) {
            bridge.feedback('error');
            toast(e.message, 'bad', 6000);
        } finally {
            this.busy = false;
        }
    },
}));

/* ---------- Cetak label & printer ---------- */

Alpine.data('printApp', (config) => ({
    s: printer.settings(),
    printers: [],
    loadingPrinters: false,
    mode: config.canProducts ? 'product' : 'location',
    code: '',
    target: null,
    copies: 1,
    preview: null,
    busy: false,
    isApp: bridge.isApp,

    init() {
        useScan((code) => this.scan(code), { hint: 'Scan SKU atau kode rak' });
        catalog().catch(() => null);
        this.$watch('s', () => {
            printer.saveSettings(this.s);
            this.render();
        }, { deep: true });
    },

    async loadPrinters() {
        this.loadingPrinters = true;
        try {
            this.printers = await bridge.listPrinters();
            if (!this.printers.length) toast('Belum ada printer. Pasangkan (pairing) printer di pengaturan Bluetooth HP dulu.', 'warn', 6000);
        } catch (e) {
            toast(e.message, 'bad', 5000);
        } finally {
            this.loadingPrinters = false;
        }
    },

    choose(p) {
        this.s.address = p.address;
        this.s.name = p.name;
        this.printers = [];
    },

    async scan(raw) {
        const code = String(raw || this.code).trim();
        this.code = '';
        if (!code) return;
        const hit = await resolveCode(code).catch(() => null);
        if (!hit) {
            bridge.feedback('error');
            return toast(`Kode "${code}" tidak dikenal.`, 'bad');
        }
        if (hit.type === 'location' && config.canLocations) {
            this.mode = 'location';
            this.target = { code: hit.item.code };
        } else if (hit.type === 'variant' && config.canProducts) {
            this.mode = 'product';
            const v = hit.item;
            const bin = v.bins?.[0] ? (await catalog()).locationById.get(v.bins[0])?.code : null;
            this.target = { name: v.name, variant: v.variant, sku: v.sku, barcode: v.barcode, location: bin };
        } else {
            bridge.feedback('warn');
            return toast('Kode ini tidak bisa dibuatkan label di sini.', 'warn');
        }
        bridge.feedback('ok');
        this.render();
    },

    render() {
        if (!this.target) return;
        try {
            const canvas = this.mode === 'location'
                ? printer.locationLabel({ code: this.target.code, warehouse: config.warehouse })
                : printer.productLabel(this.target);
            this.preview = canvas.toDataURL();
            this.canvas = canvas;
        } catch (e) {
            toast(e.message, 'bad');
        }
    },

    async print() {
        if (!this.canvas) return;
        this.busy = true;
        try {
            await printer.printCanvas(this.canvas, this.copies);
            toast(`${this.copies} label dicetak.`, 'ok');
        } catch (e) {
            toast(e.message, 'bad', 5000);
        } finally {
            this.busy = false;
        }
    },

    async test() {
        this.busy = true;
        try {
            await printer.printCanvas(printer.productLabel({ name: 'Tes printer Optima', variant: new Date().toLocaleString('id-ID'), sku: 'TES-123' }));
            toast('Halaman tes dikirim ke printer.', 'ok');
        } catch (e) {
            toast(e.message, 'bad', 5000);
        } finally {
            this.busy = false;
        }
    },
}));

/* Tombol cetak resi di halaman hasil scan pesanan. */
Alpine.data('printResi', (url) => ({
    busy: false,
    async go() {
        this.busy = true;
        try {
            await printer.printUrl(url);
            toast('Resi dicetak.', 'ok');
        } catch (e) {
            toast(e.message, 'bad', 5000);
        } finally {
            this.busy = false;
        }
    },
}));

/* Tombol aksi sederhana (POST JSON lalu muat ulang), cth. konfirmasi terima barang. */
Alpine.data('postAction', (url, confirmText = '') => ({
    busy: false,
    async go() {
        if (confirmText && !confirm(confirmText)) return;
        this.busy = true;
        try {
            const data = await request(url, { method: 'POST', body: {} });
            toast(data.message || 'Tersimpan.', 'ok');
            setTimeout(() => window.location.reload(), 900);
        } catch (e) {
            toast(e.message, 'bad', 6000);
            this.busy = false;
        }
    },
}));

/* ---------- Antrean offline ---------- */

Alpine.data('outboxApp', () => ({
    items: [],
    filter: 'all',
    async init() {
        await this.load();
        bridge.on('outbox', () => this.load());
    },
    async load() {
        this.items = (await outbox.items().catch(() => [])).sort((a, b) => b.createdAt.localeCompare(a.createdAt));
    },
    get shown() {
        return this.filter === 'all' ? this.items : this.items.filter((i) => i.status === this.filter);
    },
    get pending() {
        return this.items.filter((i) => i.status === 'pending').length;
    },
    get failed() {
        return this.items.filter((i) => i.status === 'failed').length;
    },
    async sync() {
        if (!navigator.onLine) return toast('Masih offline. Antrean dikirim otomatis begitu online.', 'warn');
        await outbox.flush();
        await this.load();
    },
    retry(item) {
        outbox.retry(item.id).then(() => this.load());
    },
    discard(item) {
        if (confirm(`Hapus "${item.label}" dari antrean? Data ini tidak akan dikirim.`)) outbox.discard(item.id).then(() => this.load());
    },
    when(item) {
        return new Date(item.createdAt).toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    },
    icon(kind) {
        return { qc: 'pkgcheck', handover: 'truck', receipt: 'inbox', opname: 'clip', adjustment: 'layers', return: 'undo' }[kind] || 'cloud';
    },
    tone(kind) {
        return { qc: 'vio', handover: 'info', receipt: 'ok', opname: 'warn', adjustment: 'amber', return: 'bad' }[kind] || 'plain';
    },
}));

/* ---------- Akun ---------- */

Alpine.data('accountApp', () => ({
    theme: window.optimaTheme?.get() || 'system',
    info: bridge.appInfo(),
    setTheme(theme) {
        this.theme = theme;
        window.optimaTheme?.set(theme);
    },
    changeServer() {
        if (confirm('Ganti alamat server? Anda perlu login lagi.')) bridge.changeServer();
    },
    async logout(form) {
        // Bersihkan halaman tersimpan supaya akun berikutnya tidak melihat data akun ini.
        try {
            const keys = await caches.keys();
            await Promise.all(keys.filter((k) => k.startsWith('optima-m')).map((k) => caches.delete(k)));
        } catch (e) {
            // abaikan
        }
        form.submit();
    },
}));

/* ---------- Service worker: halaman tetap terbuka saat offline ---------- */

if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost')) {
    navigator.serviceWorker.register('/m-sw.js', { scope: '/m' }).catch(() => null);
}

window.optimaMobile = { refreshSession, flush: outbox.flush };

Alpine.start();
