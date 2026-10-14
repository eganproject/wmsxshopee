/**
 * Kolom scan yang terkirim sendiri tanpa Enter / klik: <input data-auto-scan>.
 * Scanner mengetik sangat cepat, jadi begitu ketikan cepat berhenti sebentar
 * kodenya langsung dikirim. Ketikan manual dikirim setelah jeda lebih lama.
 * Bila scanner tetap mengirim Enter, Enter itu yang dipakai (tidak dobel).
 * data-auto-scan="enter" untuk kolom Alpine yang memproses scan lewat @keydown.enter.
 */
const SCANNER_GAP = 50; // ms antar karakter; lebih cepat = scanner
const SCANNER_IDLE = 150; // ms diam setelah burst scanner
const TYPING_IDLE = 1000; // ms diam setelah ketikan manual
const MIN_LENGTH = 3;

function submit(input) {
    if (input.value.trim().length < MIN_LENGTH) return;

    if (input.dataset.autoScan === 'enter') {
        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
    } else {
        input.form?.requestSubmit();
    }
}

function attach(input) {
    let timer = null;
    let last = 0;
    let fast = true;

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            clearTimeout(timer);
            fast = true;
            last = 0;
        }
    });

    input.addEventListener('input', () => {
        const now = performance.now();
        if (last && now - last > SCANNER_GAP) fast = false;
        if (input.value === '') fast = true;
        last = now;

        clearTimeout(timer);
        timer = setTimeout(() => {
            submit(input);
            fast = true;
            last = 0;
        }, fast && input.value.length > 1 ? SCANNER_IDLE : TYPING_IDLE);
    });
}

export function initAutoScan(root = document) {
    root.querySelectorAll('input[data-auto-scan]').forEach(attach);
}
