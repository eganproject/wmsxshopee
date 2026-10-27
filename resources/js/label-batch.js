import { PDFDocument, StandardFonts, rgb } from 'pdf-lib';

/*
 * Cetak resi gabungan: setiap bagian (label Shopee per toko, label TikTok per paket,
 * label manual) diunduh terpisah, lalu disatukan jadi satu PDF di browser.
 * Bagian yang gagal dilewati dan alasannya ditampilkan, bagian lain tetap dicetak.
 */

const MM = 72 / 25.4;
const CONCURRENCY = 3;

const root = document.getElementById('label-batch');
const parts = JSON.parse(document.getElementById('label-batch-parts').textContent);
const list = root.querySelector('[data-parts]');
const status = root.querySelector('[data-status]');
const frame = root.querySelector('[data-frame]');
const printButton = root.querySelector('[data-print]');
const downloadLink = root.querySelector('[data-download]');

function setPartState(index, state, message = '') {
    const row = list.children[index];
    row.dataset.state = state;
    row.querySelector('[data-message]').textContent = message;
}

function decodeBase64(text) {
    const binary = atob(text);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
    return bytes;
}

async function fetchPart(part) {
    const response = await fetch(part.url, { headers: { Accept: 'application/pdf, application/json' }, credentials: 'same-origin' });
    const type = response.headers.get('Content-Type') ?? '';

    if (!response.ok) {
        const body = type.includes('json') ? await response.json().catch(() => ({})) : {};
        throw new Error(body.message ?? `Gagal mengambil label (HTTP ${response.status}).`);
    }

    if (type.includes('json')) {
        const body = await response.json();
        // Shopee kadang memecah label satu bagian jadi beberapa PDF (base64).
        if (body.pdfs) return { pdfs: body.pdfs.map(decodeBase64), warning: body.warning ?? '' };
        return { labels: body.labels ?? [] };
    }

    return { pdf: await response.arrayBuffer() };
}

/** Jalankan pengunduhan beberapa sekaligus, hasil tetap berurutan sesuai bagian. */
async function fetchAll() {
    const results = new Array(parts.length);
    let next = 0;
    let done = 0;

    async function worker() {
        while (next < parts.length) {
            const index = next++;
            setPartState(index, 'loading', 'Mengambil label...');
            try {
                results[index] = await fetchPart(parts[index]);
                // Sebagian label gagal: yang berhasil tetap dicetak, alasannya ditampilkan.
                setPartState(index, results[index].warning ? 'error' : 'ok', results[index].warning ?? '');
            } catch (error) {
                results[index] = { error: error.message };
                setPartState(index, 'error', error.message);
            }
            done++;
            status.textContent = `Mengambil label ${done} dari ${parts.length} bagian...`;
        }
    }

    await Promise.all(Array.from({ length: Math.min(CONCURRENCY, parts.length) }, worker));

    return results;
}

/* ---------- Label manual digambar langsung ke PDF (100 x 150 mm) ---------- */

// Font standar PDF hanya mengenal karakter WinAnsi; karakter lain diganti agar tidak gagal.
const clean = (text) => String(text ?? '').replace(/[^\x20-\x7E\xA0-\xFF\n]/g, '?');

function wrap(text, font, size, width) {
    const lines = [];
    for (const paragraph of clean(text).split('\n')) {
        let line = '';
        for (const word of paragraph.split(/\s+/).filter(Boolean)) {
            const candidate = line ? `${line} ${word}` : word;
            if (line && font.widthOfTextAtSize(candidate, size) > width) {
                lines.push(line);
                line = word;
            } else {
                line = candidate;
            }
        }
        lines.push(line);
    }
    return lines;
}

function drawManualLabel(doc, fonts, label) {
    const W = 100 * MM;
    const H = 150 * MM;
    const pad = 3 * MM;
    const page = doc.addPage([W, H]);
    const black = rgb(0, 0, 0);
    const grey = rgb(0.35, 0.35, 0.35);
    let y = H;

    const text = (value, x, top, size, font = fonts.regular, color = black) => {
        page.drawText(clean(value), { x, y: top - size, size, font, color });
    };
    const rule = (at) => page.drawLine({ start: { x: 0, y: at }, end: { x: W, y: at }, thickness: 0.8, color: black });
    const lines = (value, top, size, font = fonts.regular, max = 4) => {
        let cursor = top;
        for (const line of wrap(value, font, size, W - pad * 2).slice(0, max)) {
            text(line, pad, cursor, size, font);
            cursor -= size * 1.3;
        }
        return cursor;
    };

    page.drawRectangle({ x: 0.4, y: 0.4, width: W - 0.8, height: H - 0.8, borderColor: black, borderWidth: 0.8 });

    // Kurir & metode
    text(label.courier, pad, y - pad, 13, fonts.bold);
    if (label.service) text(label.service, pad, y - pad - 16, 8.5);
    if (label.method) {
        const size = 8.5;
        const width = fonts.bold.widthOfTextAtSize(label.method.toUpperCase(), size) + 5 * MM;
        page.drawRectangle({ x: W - pad - width, y: y - pad - 5 * MM, width, height: 5 * MM, borderColor: black, borderWidth: 1 });
        text(label.method.toUpperCase(), W - pad - width + 2.5 * MM, y - pad - 0.8 * MM, size, fonts.bold);
    }
    y -= 13 * MM;
    rule(y);

    // Barcode resi
    if (label.tracking && label.barcode) {
        const width = W - pad * 2;
        const unit = width / label.barcode.width;
        for (const [x, w] of label.barcode.bars) {
            page.drawRectangle({ x: pad + x * unit, y: y - 2.5 * MM - 18 * MM, width: w * unit, height: 18 * MM, color: black });
        }
        const size = 11;
        const width2 = fonts.mono.widthOfTextAtSize(clean(label.tracking), size);
        text(label.tracking, (W - width2) / 2, y - 22 * MM, size, fonts.mono);
        y -= 28 * MM;
    } else {
        const value = label.tracking || 'Resi ditulis / ditempel kurir';
        const size = 10;
        const width = fonts.regular.widthOfTextAtSize(clean(value), size);
        text(value, (W - width) / 2, y - 6 * MM, size, label.tracking ? fonts.mono : fonts.regular, label.tracking ? black : grey);
        y -= 15 * MM;
    }
    rule(y);

    // Penerima
    text('PENERIMA', pad, y - 2 * MM, 6.5, fonts.regular, grey);
    let cursor = lines(label.customer, y - 2 * MM - 9, 10.5, fonts.bold, 1);
    if (label.phone) cursor = lines(label.phone, cursor, 8.5, fonts.regular, 1);
    cursor = lines(label.address, cursor, 8.5, fonts.regular, 4);
    y = cursor - 1.5 * MM;
    rule(y);

    // Pengirim
    text('PENGIRIM', pad, y - 2 * MM, 6.5, fonts.regular, grey);
    cursor = lines(label.sender, y - 2 * MM - 9, 8.5, fonts.bold, 1);
    cursor = lines(label.sender_address, cursor, 8.5, fonts.regular, 2);
    y = cursor - 1.5 * MM;
    rule(y);

    // No. pesanan & info pengiriman
    const half = W / 2;
    text('NO. PESANAN', pad, y - 2 * MM, 6.5, fonts.regular, grey);
    text(label.number, pad, y - 2 * MM - 9, 8.5, fonts.bold);
    if (label.external_number) text(label.external_number, pad, y - 2 * MM - 20, 7.5, fonts.mono);
    text(label.info_caption.toUpperCase(), half + pad, y - 2 * MM, 6.5, fonts.regular, grey);
    wrap(label.info, fonts.regular, 8.5, half - pad * 2).slice(0, 2).forEach((line, i) => text(line, half + pad, y - 2 * MM - 9 - i * 11, 8.5));
    page.drawLine({ start: { x: half, y }, end: { x: half, y: y - 13 * MM }, thickness: 0.8, color: black });
    y -= 13 * MM;
    rule(y);

    // Isi paket
    text(`ISI PAKET (${label.qty} PCS)`, pad, y - 2 * MM, 6.5, fonts.regular, grey);
    cursor = y - 2 * MM - 10;
    for (const item of label.items) {
        if (cursor < pad + 8) break;
        text(item.sku ?? '-', pad, cursor, 8, fonts.mono);
        const qty = `x ${item.qty}`;
        text(qty, W - pad - fonts.bold.widthOfTextAtSize(qty, 8), cursor, 8, fonts.bold);
        cursor -= 10.5;
    }
    if (label.more_items && cursor >= pad + 8) text(`+ ${label.more_items} baris lainnya`, pad, cursor, 8);
}

/* ---------- Gabungkan ---------- */

async function merge(results) {
    const doc = await PDFDocument.create();
    const fonts = {
        regular: await doc.embedFont(StandardFonts.Helvetica),
        bold: await doc.embedFont(StandardFonts.HelveticaBold),
        mono: await doc.embedFont(StandardFonts.Courier),
    };

    for (const [index, result] of results.entries()) {
        if (!result || result.error) continue;
        try {
            if (result.pdf || result.pdfs) {
                for (const pdf of result.pdfs ?? [result.pdf]) {
                    const source = await PDFDocument.load(pdf, { ignoreEncryption: true });
                    const pages = await doc.copyPages(source, source.getPageIndices());
                    pages.forEach((page) => doc.addPage(page));
                }
            } else {
                result.labels.forEach((label) => drawManualLabel(doc, fonts, label));
            }
        } catch (error) {
            setPartState(index, 'error', `PDF label tidak bisa dibaca: ${error.message}`);
        }
    }

    return doc.getPageCount() ? doc.save() : null;
}

async function run() {
    status.textContent = `Mengambil label 0 dari ${parts.length} bagian...`;
    const results = await fetchAll();
    status.textContent = 'Menyatukan label...';

    const bytes = await merge(results);
    const failed = list.querySelectorAll('[data-state="error"]').length;

    if (!bytes) {
        root.dataset.state = 'error';
        status.textContent = 'Tidak ada label yang bisa dicetak. Lihat alasannya di bawah.';
        return;
    }

    const url = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
    frame.src = url;
    downloadLink.href = url;
    root.dataset.state = 'ready';
    status.dataset.tone = failed ? 'warn' : 'ok';
    status.textContent = failed
        ? `Label siap dicetak, tetapi ${failed} bagian gagal dan tidak ikut tercetak.`
        : 'Semua label siap dicetak dalam satu PDF.';
    if (!failed) root.querySelector('[data-parts-wrap]').open = false;
}

printButton.addEventListener('click', () => {
    try {
        frame.contentWindow.focus();
        frame.contentWindow.print();
    } catch {
        window.open(frame.src, '_blank');
    }
});

run();
