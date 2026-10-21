/*
 * Lokasi tetap SKU berurutan (bin utama lalu cadangan) di form penerimaan & transfer.
 *
 * defaults: [[gudang, varian, [[lokasi, sisaRuang|null], ...]], ...] dari server.
 * Sisa ruang = kapasitas bin dikurangi stok di bin itu; null = tanpa batas.
 */
export function fixedBinsFor(defaults, warehouse, variant) {
    const hit = (defaults || []).find((d) => String(d[0]) === String(warehouse) && String(d[1]) === String(variant));
    return hit ? hit[2].map(([id, room]) => ({ id: String(id), room })) : [];
}

/*
 * Bila qty baris melebihi sisa ruang bin lokasi tetapnya, kelebihannya dipindah
 * ke bin lokasi tetap berikutnya (ditambahkan ke baris yang sudah ada untuk bin
 * itu, atau baris baru). Baris di bin di luar lokasi tetap tidak diubah.
 *
 * opts: { bins, qtyKey, locKey, same(row): baris lain dengan barang (& asal) yang sama,
 *         make(locId, qty): baris baru, moved(from, to, qty)?: hook pindah qty }
 * Mengembalikan true bila ada yang dipindah.
 */
export function spreadToFixedBins(items, item, opts) {
    const { bins, qtyKey, locKey, same, make, moved } = opts;
    let index = bins.findIndex((b) => b.id === String(item[locKey]));
    if (index < 0) return false;

    const qtyOf = (row) => parseInt(row[qtyKey]) || 0;
    let current = item;
    let changed = false;

    while (index < bins.length - 1) {
        const bin = bins[index];
        const used = items.filter((r) => r !== current && same(r) && String(r[locKey]) === bin.id).reduce((t, r) => t + qtyOf(r), 0);
        const left = bin.room === null || bin.room === undefined ? Infinity : Math.max(0, bin.room - used);
        const qty = qtyOf(current);
        if (qty <= left) break;

        const next = bins[index + 1].id;
        const excess = qty - left;
        const target = items.find((r) => r !== current && same(r) && String(r[locKey]) === next);

        if (left === 0 && ! target) {
            // Bin ini sudah penuh: seluruh baris pindah ke bin berikutnya.
            current[locKey] = next;
        } else if (target) {
            target[qtyKey] = qtyOf(target) + excess;
            moved?.(current, target, excess);
            if (left === 0) {
                items.splice(items.indexOf(current), 1);
            } else {
                current[qtyKey] = left;
            }
            current = target;
        } else {
            current[qtyKey] = left;
            const row = make(next, excess);
            moved?.(current, row, excess);
            items.push(row);
            current = row;
        }

        changed = true;
        index++;
    }

    return changed;
}
