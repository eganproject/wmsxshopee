/** IndexedDB kecil: "outbox" (antrean kirim) dan "kv" (cache data master). */
let dbPromise;

function open() {
    dbPromise ||= new Promise((resolve, reject) => {
        const req = indexedDB.open('optima-gudang', 1);
        req.onupgradeneeded = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains('outbox')) db.createObjectStore('outbox', { keyPath: 'id' });
            if (!db.objectStoreNames.contains('kv')) db.createObjectStore('kv');
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
    return dbPromise;
}

function tx(store, mode, fn) {
    return open().then((db) => new Promise((resolve, reject) => {
        const t = db.transaction(store, mode);
        const result = fn(t.objectStore(store));
        t.oncomplete = () => resolve(result instanceof IDBRequest ? result.result : result);
        t.onerror = () => reject(t.error);
    }));
}

export const kvGet = (key) => tx('kv', 'readonly', (s) => s.get(key));
export const kvSet = (key, value) => tx('kv', 'readwrite', (s) => s.put(value, key));
export const outboxAll = () => tx('outbox', 'readonly', (s) => s.getAll());
export const outboxPut = (item) => tx('outbox', 'readwrite', (s) => s.put(item));
export const outboxDelete = (id) => tx('outbox', 'readwrite', (s) => s.delete(id));
