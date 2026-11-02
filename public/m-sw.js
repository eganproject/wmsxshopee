/*
 * Service worker aplikasi Android (halaman /m): halaman yang pernah dibuka tetap
 * bisa dibuka saat sinyal hilang. Data (scan, QC, dll.) tidak lewat sini; itu
 * disimpan di antrean IndexedDB oleh resources/js/mobile/outbox.js.
 */
const PAGES = 'optima-m-pages-v1';
const ASSETS = 'optima-m-assets-v1';
const OFFLINE = '/m/offline';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(PAGES).then((cache) => cache.add(new Request(OFFLINE, { credentials: 'same-origin' }))).catch(() => null));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k.startsWith('optima-m') && ![PAGES, ASSETS].includes(k)).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    // Aset hasil build punya hash di nama file: aman diambil dari cache dulu.
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(
            caches.open(ASSETS).then(async (cache) => {
                const hit = await cache.match(request);
                if (hit) return hit;
                const response = await fetch(request);
                if (response.ok) cache.put(request, response.clone());
                return response;
            }),
        );
        return;
    }

    // Halaman /m: ambil dari server dulu, pakai salinan tersimpan saat offline.
    const isPage = request.mode === 'navigate' && (url.pathname === '/m' || url.pathname.startsWith('/m/'));
    if (!isPage || url.pathname === '/m/session') return;

    event.respondWith(
        fetch(request)
            .then((response) => {
                // Jangan simpan halaman login / redirect (sesi habis).
                if (response.ok && !response.redirected) {
                    const copy = response.clone();
                    caches.open(PAGES).then((cache) => cache.put(request, copy));
                }
                return response;
            })
            .catch(async () => (await caches.match(request)) || (await caches.match(OFFLINE)) || Response.error()),
    );
});
