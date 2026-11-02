/** Barcode Code128 (set B) untuk label yang digambar di HP. Mengembalikan lebar modul [batang, spasi, ...]. */
const PATTERNS = [
    '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
    '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
    '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
    '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
    '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
    '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
    '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
    '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
    '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
    '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
    '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
];

export function code128(text) {
    const value = String(text);
    const codes = [104];
    for (const ch of value) {
        const c = ch.charCodeAt(0);
        if (c < 32 || c > 127) throw new Error('Kode berisi karakter yang tidak bisa dibuat barcode.');
        codes.push(c - 32);
    }
    let sum = 104;
    codes.slice(1).forEach((c, i) => (sum += c * (i + 1)));
    codes.push(sum % 103, 106);
    return codes.flatMap((c) => PATTERNS[c].split('').map(Number));
}

/** Gambar barcode di canvas, lebar total = width piksel (modul dibulatkan agar tajam). */
export function drawCode128(ctx, text, x, y, width, height) {
    const modules = code128(text);
    const total = modules.reduce((a, b) => a + b, 0) + 20; // quiet zone 10 modul kiri-kanan
    const unit = Math.max(1, Math.floor(width / total));
    const used = total * unit;
    let cursor = x + Math.floor((width - used) / 2) + 10 * unit;
    ctx.fillStyle = '#000';
    modules.forEach((w, i) => {
        if (i % 2 === 0) ctx.fillRect(cursor, y, w * unit, height);
        cursor += w * unit;
    });
}
