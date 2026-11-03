/** Fetch JSON ke server Optima dengan token CSRF & pesan error berbahasa Indonesia. */
export class ApiError extends Error {
    constructor(message, status, data) {
        super(message);
        this.status = status;
        this.data = data;
    }
}

export function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

export function setCsrf(token) {
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && token) meta.content = token;
}

export async function refreshSession() {
    const response = await fetch('/m/session', { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
    if (response.status === 401 || response.redirected) throw new ApiError('Sesi login habis. Buka aplikasi lalu login lagi.', 401);
    if (!response.ok) throw new ApiError('Server tidak merespons.', response.status);
    const data = await response.json();
    setCsrf(data.csrf);
    return data;
}

/**
 * body: objek (dikirim JSON) atau FormData. Melempar ApiError untuk status 4xx/5xx
 * dan TypeError bila jaringan putus (dipakai antrean offline).
 */
export async function request(url, { method = 'GET', body, retried = false, idempotencyKey = null } = {}) {
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    // Kunci yang sama untuk kiriman ulang dari antrean: server tidak memproses aksi yang sama dua kali.
    if (idempotencyKey) headers['X-Idempotency-Key'] = idempotencyKey;
    let payload;
    if (body instanceof FormData) {
        payload = body;
    } else if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        payload = JSON.stringify(body);
    }
    if (method !== 'GET') headers['X-CSRF-TOKEN'] = csrf();

    const response = await fetch(url, { method, headers, body: payload, credentials: 'same-origin' });

    if (response.status === 419 && !retried) {
        await refreshSession();
        return request(url, { method, body, retried: true, idempotencyKey });
    }

    const type = response.headers.get('content-type') || '';
    const data = type.includes('json') ? await response.json().catch(() => ({})) : {};

    if (!response.ok) {
        let message = data.message || `Gagal (HTTP ${response.status}).`;
        if (response.status === 401) message = 'Sesi login habis. Login lagi.';
        if (response.status === 403) message = data.message && data.message !== 'This action is unauthorized.' ? data.message : 'Akun Anda tidak punya akses untuk aksi ini.';
        if (response.status === 422 && data.errors) message = Object.values(data.errors).flat()[0] || message;
        if (response.status >= 500) message = 'Server sedang bermasalah. Coba lagi sebentar.';
        throw new ApiError(message, response.status, data);
    }

    return data;
}

export const getJson = (url) => request(url);
export const postJson = (url, body) => request(url, { method: 'POST', body });
