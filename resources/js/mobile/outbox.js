import { ApiError, refreshSession, request } from './api';
import { emit } from './bridge';
import { outboxAll, outboxDelete, outboxPut } from './db';

/**
 * Antrean offline. Aksi (scan serah terima, QC selesai, opname, penerimaan, retur)
 * dikirim langsung bila online; bila jaringan putus disimpan di HP dan dikirim
 * otomatis begitu online lagi. Penolakan dari server (mis. resi sudah dikirim)
 * ditandai Gagal supaya bisa ditinjau di menu Antrean.
 */

/** FormData -> bentuk yang bisa disimpan di IndexedDB (File disimpan sebagai Blob). */
function packForm(form) {
    return Array.from(form.entries()).map(([key, value]) =>
        value instanceof Blob ? [key, { blob: value, name: value.name || 'foto.jpg' }] : [key, value]);
}

function unpackForm(entries) {
    const form = new FormData();
    entries.forEach(([key, value]) => (value && value.blob ? form.append(key, value.blob, value.name) : form.append(key, value)));
    return form;
}

function bodyOf(item) {
    return item.form ? unpackForm(item.form) : item.json;
}

async function changed() {
    const items = await outboxAll().catch(() => []);
    emit('outbox', { count: items.length, failed: items.filter((i) => i.status === 'failed').length });
}

const isNetworkError = (e) => !(e instanceof ApiError) || e.status === 0 || e.status >= 500 || e.status === 401 || e.status === 419;

/**
 * Kirim sekarang, atau antre bila offline.
 * @returns {{queued: boolean, data?: any}}
 * @throws ApiError untuk penolakan server (422/403/404) agar langsung tampil ke petugas.
 */
export async function send({ url, body, label, kind = 'umum' }) {
    const item = {
        id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
        url,
        label,
        kind,
        json: body instanceof FormData ? null : body,
        form: body instanceof FormData ? packForm(body) : null,
        createdAt: new Date().toISOString(),
        status: 'pending',
        error: null,
        attempts: 0,
    };

    if (navigator.onLine) {
        try {
            const data = await request(url, { method: 'POST', body });
            return { queued: false, data };
        } catch (e) {
            if (!isNetworkError(e)) throw e;
        }
    }

    await outboxPut(item);
    await changed();
    return { queued: true };
}

let flushing = false;

/** Kirim ulang semua antrean yang menunggu, berurutan sesuai waktu scan. */
export async function flush({ includeFailed = false } = {}) {
    if (flushing || !navigator.onLine) return;
    flushing = true;
    try {
        const items = (await outboxAll()).sort((a, b) => a.createdAt.localeCompare(b.createdAt));
        const todo = items.filter((i) => i.status === 'pending' || (includeFailed && i.status === 'failed'));
        if (!todo.length) return;
        await refreshSession().catch(() => null);

        for (const item of todo) {
            try {
                const data = await request(item.url, { method: 'POST', body: bodyOf(item) });
                await outboxDelete(item.id);
                emit('outbox-sent', { item, data });
            } catch (e) {
                if (isNetworkError(e)) {
                    item.attempts += 1;
                    item.error = e.message;
                    await outboxPut(item);
                    if (e.status === 401) break;
                    continue;
                }
                if (e.data?.duplicate) {
                    // Sudah tercatat di server sebelumnya: tidak perlu dikirim lagi.
                    await outboxDelete(item.id);
                    continue;
                }
                item.status = 'failed';
                item.error = e.message;
                await outboxPut(item);
            }
        }
    } finally {
        flushing = false;
        await changed();
    }
}

export async function retry(id) {
    const item = (await outboxAll()).find((i) => i.id === id);
    if (!item) return;
    item.status = 'pending';
    item.error = null;
    await outboxPut(item);
    await flush();
}

export async function discard(id) {
    await outboxDelete(id);
    await changed();
}

export { outboxAll as items, changed as refreshCount };

window.addEventListener('online', () => flush());
setInterval(() => flush(), 30000);
