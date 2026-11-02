/**
 * Jembatan ke aplikasi Android (window.OptimaNative). Di browser biasa fungsi
 * kamera diganti kolom ketik / scanner USB, cetak tidak tersedia.
 */
const native = typeof window !== 'undefined' ? window.OptimaNative : undefined;
export const isApp = !!native;

const listeners = {};
const pending = new Map();
let seq = 0;

window.OptimaBridge = {
    _resolve(id, payload) {
        const done = pending.get(id);
        if (!done) return;
        pending.delete(id);
        payload.ok ? done.resolve(payload.data) : done.reject(new Error(payload.message || 'Gagal.'));
    },
    _event(name, data) {
        (listeners[name] || []).slice().forEach((fn) => fn(data || {}));
    },
};

export function on(name, fn) {
    (listeners[name] ||= []).push(fn);
    return () => off(name, fn);
}

export function off(name, fn) {
    listeners[name] = (listeners[name] || []).filter((f) => f !== fn);
}

export function emit(name, data) {
    window.OptimaBridge._event(name, data);
}

function call(method, ...args) {
    return new Promise((resolve, reject) => {
        const id = `c${++seq}`;
        pending.set(id, { resolve, reject });
        try {
            native[method](id, ...args);
        } catch (e) {
            pending.delete(id);
            reject(e);
        }
    });
}

export function appInfo() {
    try {
        return native ? JSON.parse(native.appInfo()) : null;
    } catch (e) {
        return null;
    }
}

/** Buka kamera. mode "full" (default) atau "top" (kamera di atas, halaman tetap terlihat). */
export function openScanner(options = {}) {
    if (native) {
        native.openScanner(JSON.stringify({ mode: 'full', continuous: false, ...options }));
    } else {
        emit('scanner-fallback', options);
    }
}

export function closeScanner() {
    if (native) native.closeScanner();
}

let audio;
/** Bunyi + getar: scan | ok | warn | error. */
export function feedback(kind = 'ok') {
    if (native) {
        native.feedback(kind);
        return;
    }
    try {
        audio ||= new (window.AudioContext || window.webkitAudioContext)();
        const osc = audio.createOscillator();
        const gain = audio.createGain();
        osc.frequency.value = kind === 'error' ? 220 : kind === 'warn' ? 440 : 880;
        gain.gain.value = 0.08;
        osc.connect(gain).connect(audio.destination);
        osc.start();
        osc.stop(audio.currentTime + (kind === 'error' ? 0.35 : 0.1));
    } catch (e) {
        // tanpa suara
    }
    navigator.vibrate?.(kind === 'error' ? [120, 80, 120] : 40);
}

export function setSystemBars(color, light) {
    native?.setSystemBars(color, light);
}

export function openExternal(url) {
    native ? native.openExternal(url) : window.open(url, '_blank');
}

export function changeServer() {
    native?.changeServer();
}

export function listPrinters() {
    if (!native) return Promise.reject(new Error('Printer Bluetooth hanya bisa dari aplikasi Android.'));
    return call('listPrinters');
}

export function print(job) {
    if (!native) return Promise.reject(new Error('Cetak Bluetooth hanya bisa dari aplikasi Android.'));
    return call('print', JSON.stringify(job));
}

/*
 * Scanner fisik (HP gudang Zebra/Urovo, scanner Bluetooth) mengetik kode sangat cepat
 * lalu Enter. Ketikan cepat di luar kolom input dianggap hasil scan.
 */
let buffer = '';
let last = 0;
document.addEventListener('keydown', (event) => {
    const target = event.target;
    const typing = target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT' || target.isContentEditable);
    if (typing) return;
    const now = Date.now();
    if (now - last > 80) buffer = '';
    last = now;
    if (event.key === 'Enter') {
        if (buffer.length >= 3) {
            emit('scan', { code: buffer.trim(), format: 'keyboard' });
            event.preventDefault();
        }
        buffer = '';
    } else if (event.key.length === 1) {
        buffer += event.key;
    }
});
