import { print as nativePrint } from './bridge';
import { drawCode128 } from './code128';

/**
 * Printer thermal Bluetooth: pengaturan disimpan di HP, label digambar di canvas
 * (hitam-putih, 8 titik/mm = 203 dpi) lalu dikirim ke aplikasi untuk dicetak.
 */
const KEY = 'optima-printer';
const DEFAULTS = { address: '', name: '', lang: 'escpos', widthMm: 58, labelWidthMm: 50, labelHeightMm: 30, gapMm: 2, autoQc: false };

export function settings() {
    try {
        return { ...DEFAULTS, ...JSON.parse(localStorage.getItem(KEY) || '{}') };
    } catch (e) {
        return { ...DEFAULTS };
    }
}

export function saveSettings(value) {
    try {
        localStorage.setItem(KEY, JSON.stringify({ ...settings(), ...value }));
    } catch (e) {
        // abaikan
    }
}

function job(extra) {
    const s = settings();
    if (!s.address) throw new Error('Pilih printer dulu di menu Cetak Label.');
    const label = s.lang === 'tspl';
    return { printer: { address: s.address, lang: s.lang, widthMm: label ? s.labelWidthMm : s.widthMm, gapMm: s.gapMm }, ...extra };
}

export function printCanvas(canvas, copies = 1) {
    return nativePrint(job({ image: canvas.toDataURL('image/png'), copies }));
}

/** Resi marketplace (PDF) diambil aplikasi memakai login yang sama, lalu dicetak. */
export function printUrl(url, copies = 1) {
    return nativePrint(job({ url: new URL(url, location.href).href, copies }));
}

/* ---------- Gambar label ---------- */

const DOT = 8;

function canvasFor(widthMm, heightMm) {
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(widthMm * DOT);
    canvas.height = Math.round(heightMm * DOT);
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = '#000';
    ctx.textBaseline = 'top';
    return [canvas, ctx];
}

function wrap(ctx, text, maxWidth, maxLines) {
    const words = String(text).split(/\s+/);
    const lines = [];
    let line = '';
    for (const word of words) {
        const test = line ? `${line} ${word}` : word;
        if (ctx.measureText(test).width > maxWidth && line) {
            lines.push(line);
            line = word;
        } else {
            line = test;
        }
    }
    if (line) lines.push(line);
    if (lines.length > maxLines) {
        lines.length = maxLines;
        lines[maxLines - 1] = lines[maxLines - 1].replace(/.{0,2}$/, '…');
    }
    return lines;
}

/** Ukuran label: pakai ukuran label (TSPL) atau lebar kertas struk (ESC/POS). */
function labelSize() {
    const s = settings();
    return s.lang === 'tspl' ? [s.labelWidthMm, s.labelHeightMm] : [s.widthMm, Math.round(s.widthMm * 0.6)];
}

export function productLabel({ name, variant, sku, barcode, location }) {
    const [w, h] = labelSize();
    const [canvas, ctx] = canvasFor(w, h);
    const pad = 2.5 * DOT;
    const width = canvas.width - pad * 2;
    let y = pad;

    if (location) {
        ctx.font = `bold ${2.6 * DOT}px monospace`;
        const tw = ctx.measureText(location).width + DOT * 1.5;
        ctx.lineWidth = 3;
        ctx.strokeRect(canvas.width - pad - tw, y - 2, tw, 3.6 * DOT);
        ctx.fillText(location, canvas.width - pad - tw + DOT * 0.75, y + 2);
    }

    ctx.font = `bold ${3.2 * DOT}px sans-serif`;
    const lines = wrap(ctx, name, width - (location ? 14 * DOT : 0), 2);
    lines.forEach((line) => {
        ctx.fillText(line, pad, y);
        y += 3.8 * DOT;
    });
    if (variant) {
        ctx.font = `${2.8 * DOT}px sans-serif`;
        ctx.fillText(variant, pad, y);
        y += 3.4 * DOT;
    }

    const textH = 3 * DOT;
    const barH = Math.max(5 * DOT, canvas.height - y - pad - textH - DOT);
    drawCode128(ctx, barcode || sku, pad, y + DOT * 0.5, width, barH);
    y += DOT * 0.5 + barH + DOT * 0.6;
    ctx.font = `bold ${2.7 * DOT}px monospace`;
    ctx.fillText(sku, pad, y);
    if (barcode && barcode !== sku) {
        ctx.font = `${2.4 * DOT}px monospace`;
        ctx.textAlign = 'right';
        ctx.fillText(barcode, canvas.width - pad, y + 2);
        ctx.textAlign = 'left';
    }
    return canvas;
}

export function locationLabel({ code, warehouse }) {
    const [w, h] = labelSize();
    const [canvas, ctx] = canvasFor(w, h);
    const pad = 2.5 * DOT;
    const width = canvas.width - pad * 2;
    ctx.font = `${2.6 * DOT}px sans-serif`;
    ctx.fillText(warehouse || 'Lokasi rak', pad, pad);
    let size = 9 * DOT;
    ctx.font = `bold ${size}px monospace`;
    while (ctx.measureText(code).width > width && size > 4 * DOT) {
        size -= DOT / 2;
        ctx.font = `bold ${size}px monospace`;
    }
    ctx.fillText(code, pad, pad + 3.4 * DOT);
    const top = pad + 3.4 * DOT + size + DOT;
    drawCode128(ctx, code, pad, top, width, Math.max(5 * DOT, canvas.height - top - pad));
    return canvas;
}

/** Struk manifest serah terima untuk kertas roll. */
export function manifestReceipt(manifest, signature) {
    const s = settings();
    const widthMm = s.lang === 'tspl' ? s.labelWidthMm : s.widthMm;
    const lineH = 3.6 * DOT;
    const rows = manifest.orders.length;
    const height = (34 + rows * 4.2 + (signature ? 22 : 12));
    const [canvas, ctx] = canvasFor(widthMm, height);
    const pad = 2 * DOT;
    const right = canvas.width - pad;
    let y = pad;

    ctx.font = `bold ${3.6 * DOT}px sans-serif`;
    ctx.fillText('MANIFEST SERAH TERIMA', pad, y); y += 5 * DOT;
    ctx.font = `${2.8 * DOT}px sans-serif`;
    [[manifest.number, manifest.courier], [manifest.date, `${rows} paket`], [manifest.warehouse || '', manifest.officer || '']].forEach(([a, b]) => {
        ctx.fillText(a, pad, y);
        ctx.textAlign = 'right'; ctx.fillText(b, right, y); ctx.textAlign = 'left';
        y += lineH;
    });
    ctx.fillRect(pad, y, canvas.width - pad * 2, 2); y += DOT;
    ctx.font = `${2.7 * DOT}px monospace`;
    manifest.orders.forEach((o, i) => {
        ctx.fillText(`${String(i + 1).padStart(2, ' ')}. ${o.tracking || o.number}`, pad, y);
        y += 4.2 * DOT;
    });
    ctx.fillRect(pad, y, canvas.width - pad * 2, 2); y += DOT * 1.5;
    ctx.font = `${2.8 * DOT}px sans-serif`;
    ctx.fillText(`Diterima: ${manifest.receiver || '________________'}`, pad, y); y += lineH;
    if (signature) {
        const sh = 16 * DOT;
        const sw = Math.min(canvas.width - pad * 2, signature.width * (sh / signature.height));
        ctx.drawImage(signature, pad, y, sw, sh);
    }
    return canvas;
}
