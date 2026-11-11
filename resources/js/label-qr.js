import { rgb } from 'pdf-lib';

/*
 * QR nomor resi (AWB) di label cetak: digambar di label manual dan ditempel di atas
 * PDF label Shopee / TikTok pada pojok yang dipilih di Pengaturan Cetak Resi.
 */

const MM = 72 / 25.4;

/** Gambar QR (path SVG per modul dari server) di kotak persegi berlatar putih. */
export function drawQr(page, qr, x, y, size) {
    const unit = size / (qr.n + 4); // 2 modul bingkai putih di tiap sisi
    page.drawRectangle({ x, y, width: size, height: size, color: rgb(1, 1, 1) });
    page.drawSvgPath(qr.d, { x: x + 2 * unit, y: y + size - 2 * unit, scale: unit, color: rgb(0, 0, 0), borderWidth: 0 });
}

/**
 * Tempel QR di pojok halaman sesuai pengaturan. Posisi dihitung pada tampilan
 * halaman (memperhitungkan rotasi), lalu diubah ke koordinat PDF.
 */
export function stampQr(page, qr, config) {
    const box = page.getCropBox();
    const W = box.width;
    const H = box.height;
    const rotation = (((page.getRotation().angle ?? 0) % 360) + 360) % 360;
    const viewW = rotation % 180 ? H : W;
    const viewH = rotation % 180 ? W : H;
    const size = Math.min(config.size_mm * MM, viewW, viewH);
    const clamp = (value, max) => Math.max(0, Math.min(value, max));
    const u = clamp(config.position.endsWith('left') ? config.margin_x_mm * MM : viewW - config.margin_x_mm * MM - size, viewW - size);
    const v = clamp(config.position.startsWith('top') ? config.margin_y_mm * MM : viewH - config.margin_y_mm * MM - size, viewH - size);

    const [x, y] = {
        0: [u, H - v - size],
        90: [v, u],
        180: [W - u - size, v],
        270: [W - v - size, H - u - size],
    }[rotation] ?? [u, H - v - size];

    drawQr(page, qr, box.x + x, box.y + y, size);
}

let pdfjs = null;

/**
 * pdf.js hanya dimuat bila perlu (PDF berisi beberapa resi). Worker-nya dijalankan di
 * halaman ini (globalThis.pdfjsWorker), jadi tidak bergantung pada server mengirim
 * berkas .mjs dengan tipe yang benar.
 */
async function loadPdfjs() {
    if (!pdfjs) {
        const [lib] = await Promise.all([import('pdfjs-dist'), import('pdfjs-dist/build/pdf.worker.min.mjs')]);
        pdfjs = lib;
    }
    return pdfjs;
}

const normalize = (text) => String(text ?? '').replace(/\s+/g, '').toUpperCase();

/** Teks tiap halaman PDF (tanpa spasi, huruf besar) untuk mencari nomor resi. */
async function pageTexts(bytes) {
    const lib = await loadPdfjs();
    const doc = await lib.getDocument({ data: new Uint8Array(bytes).slice() }).promise;
    try {
        const texts = [];
        for (let i = 1; i <= doc.numPages; i++) {
            const content = await (await doc.getPage(i)).getTextContent();
            texts.push(normalize(content.items.map((item) => item.str ?? '').join('')));
        }
        return texts;
    } finally {
        doc.destroy();
    }
}

/**
 * Nomor resi untuk tiap halaman PDF (null = tidak ditempel QR).
 * Satu resi di PDF: semua halaman milik resi itu. Beberapa resi: dicocokkan dari
 * teks halaman; halaman berisi lebih dari satu resi (cth. A4 berisi 4 label) dilewati.
 * Bila PDF tidak berisi teks sama sekali dan jumlah halaman = jumlah resi, dipakai
 * urutan pesanan yang dikirim ke marketplace.
 */
export async function pageAwbs(bytes, pageCount, awbs) {
    if (!awbs.length) return new Array(pageCount).fill(null);
    if (awbs.length === 1) return new Array(pageCount).fill(awbs[0]);

    let texts = [];
    try {
        texts = await pageTexts(bytes);
    } catch {
        texts = [];
    }

    const matched = Array.from({ length: pageCount }, (_, i) => {
        const found = awbs.filter((awb) => (texts[i] ?? '').includes(normalize(awb)));
        return found.length === 1 ? found[0] : null;
    });

    const noText = texts.every((text) => !text);
    if (matched.every((awb) => awb === null) && noText && pageCount === awbs.length) return [...awbs];

    return matched;
}
