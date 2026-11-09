import { getJson } from './api';
import { kvGet, kvSet } from './db';

/**
 * Data master gudang aktif (barang, paket, rak, supplier, stok) disimpan di HP supaya
 * scan SKU / rak tetap dikenali saat offline. Diperbarui saat online, maks. tiap 10 menit.
 */
let cache = null;
let loading = null;

function index(data) {
    const byCode = new Map();
    data.variants.forEach((v) => {
        byCode.set(v.sku.toLowerCase(), { type: 'variant', item: v });
        if (v.barcode) byCode.set(String(v.barcode).toLowerCase(), { type: 'variant', item: v });
    });
    data.bundles.forEach((b) => {
        byCode.set(b.sku.toLowerCase(), { type: 'bundle', item: b });
        if (b.barcode) byCode.set(String(b.barcode).toLowerCase(), { type: 'bundle', item: b });
    });
    const locations = new Map(data.locations.map((l) => [l.code.toLowerCase(), l]));
    const variants = new Map(data.variants.map((v) => [v.id, v]));
    const locationById = new Map(data.locations.map((l) => [l.id, l]));
    return { ...data, byCode, locations, variants, locationById };
}

let refreshing = null;

async function download() {
    const data = await getJson('/m/catalog');
    await kvSet('catalog', data).catch(() => null);
    cache = index(data);
    return cache;
}

/** Perbarui data di belakang layar; scan tetap pakai data di HP sambil menunggu. */
function refreshInBackground() {
    refreshing ||= download().catch(() => null).finally(() => (refreshing = null));
}

export async function catalog({ fresh = false } = {}) {
    if (cache && !fresh) return cache;
    if (loading) return loading;

    loading = (async () => {
        const warehouse = document.querySelector('meta[name="warehouse-id"]')?.content;
        const stored = await kvGet('catalog').catch(() => null);
        const valid = stored && String(stored.warehouse_id) === String(warehouse);
        const age = valid ? Date.now() - new Date(stored.generated_at).getTime() : Infinity;

        // Sudah ada data di HP: langsung dipakai, kalau lewat 10 menit diperbarui di belakang.
        if (valid && !fresh) {
            cache = index(stored);
            if (navigator.onLine && age > 10 * 60 * 1000) refreshInBackground();
            return cache;
        }
        if (navigator.onLine) {
            try {
                return await download();
            } catch (e) {
                if (!valid) throw e;
            }
        }
        if (!valid) throw new Error('Data barang belum tersimpan di HP. Sambungkan internet sekali dulu.');
        cache = index(stored);
        return cache;
    })();

    try {
        return await loading;
    } finally {
        loading = null;
    }
}

/** Cari kode hasil scan: barang, paket, atau rak. */
export async function resolveCode(code) {
    const data = await catalog();
    const key = String(code).trim().toLowerCase();
    const location = data.locations.get(key);
    if (location) return { type: 'location', item: location };
    return data.byCode.get(key) || null;
}

export function variantLabel(variant) {
    return variant.variant ? `${variant.name} / ${variant.variant}` : variant.name;
}
