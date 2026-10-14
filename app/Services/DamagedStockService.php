<?php

namespace App\Services;

use App\Models\DamagedDocument;
use App\Models\DamagedDocumentItem;
use App\Models\DamagedStock;
use App\Models\DamagedStockLedger;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya pintu perubahan stok rusak. Dokumen barang rusak dibuat sebagai
 * draft dulu; stok baru berubah saat disetujui. Saldo rusak per varian per gudang
 * selalu diubah bersama kartu stok rusak dalam satu transaksi; bila dokumen
 * juga menyentuh stok baik (pindah ke rusak, perbaikan, rakit), mutasinya
 * dicatat lewat StockService di transaksi yang sama.
 */
class DamagedStockService
{
    private const ATTEMPTS = 3;

    public function __construct(private StockService $stock) {}

    /**
     * Barang rusak masuk dari dokumen lain (penerimaan barang, retur penjualan).
     * Dipanggil di dalam transaksi dokumen sumber.
     *
     * @param  array<int, array{product_variant_id: int, qty: int, notes?: ?string}>  $lines
     */
    public function receive(Model $source, string $type, int $warehouseId, string $date, string $reference, array $lines, ?int $userId): void
    {
        $lines = array_values(array_filter($lines, fn ($line) => $line['qty'] > 0));

        if (! $lines) {
            return;
        }

        DB::transaction(function () use ($source, $type, $warehouseId, $date, $reference, $lines, $userId) {
            $this->lockVariants(array_column($lines, 'product_variant_id'));

            foreach ($lines as $index => $line) {
                $this->apply([
                    'product_variant_id' => $line['product_variant_id'],
                    'warehouse_id' => $warehouseId,
                    'type' => $type,
                    'source_type' => $source::class,
                    'source_id' => $source->getKey(),
                    'reference' => $reference,
                    'movement_date' => $date,
                    'notes' => $line['notes'] ?? null,
                    'created_by' => $userId,
                ], $line['qty'], $index);
            }
        }, self::ATTEMPTS);
    }

    /**
     * Simpan draft dokumen barang rusak. Stok belum berubah sampai disetujui.
     *
     * @param  array{type: string, warehouse_id: int, document_date: string, supplier_id?: ?int, recipient?: ?string, reference?: ?string, notes?: ?string, created_by?: ?int}  $header
     * @param  array<int, array{role?: string, source?: string, product_variant_id: int, from_location_id?: ?int, to_location_id?: ?int, qty: int, notes?: ?string}>  $items  urut sesuai baris di form
     */
    public function draft(array $header, array $items): DamagedDocument
    {
        return DB::transaction(function () use ($header, $items) {
            $document = DamagedDocument::create([
                ...$header,
                'status' => 'draft',
                'number' => $this->nextNumber($header['type']),
            ]);

            $this->fillItems($document, $items);

            return $document;
        }, self::ATTEMPTS);
    }

    /**
     * Ganti isi draft. Jenis dokumen tidak berubah.
     *
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $items
     */
    public function updateDraft(DamagedDocument $document, array $header, array $items): void
    {
        DB::transaction(function () use ($document, $header, $items) {
            $this->assertDraft(DamagedDocument::whereKey($document->id)->lockForUpdate()->firstOrFail());

            $document->update(collect($header)->except(['type', 'created_by'])->all());
            $document->items()->delete();
            $this->fillItems($document, $items);
        }, self::ATTEMPTS);
    }

    /**
     * Setujui draft: stok rusak & stok baik berubah, dokumen terkunci.
     *
     * @throws ValidationException bila sudah disetujui, atau stok rusak / stok baik tidak cukup
     */
    public function approve(DamagedDocument $document, ?int $userId): DamagedDocument
    {
        return DB::transaction(function () use ($document, $userId) {
            $document = DamagedDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($document);

            $document->load('items');
            $this->lockVariants($document->items->pluck('product_variant_id')->all());

            /** @var array<int, array{0: DamagedDocumentItem, 1: int}> $created [baris, indeks form] */
            $created = $document->items->values()->map(fn ($item, $index) => [$item, $index])->all();

            $this->recordGoodStock($document, $created, $userId);

            foreach ($created as [$item, $index]) {
                $delta = $this->damagedDelta($document->type, $item);

                if ($delta === 0) {
                    continue;
                }

                $this->apply([
                    'product_variant_id' => $item->product_variant_id,
                    'warehouse_id' => $document->warehouse_id,
                    'type' => $document->type,
                    'source_type' => DamagedDocument::class,
                    'source_id' => $document->id,
                    'reference' => $document->number,
                    'movement_date' => $document->document_date,
                    'notes' => $item->notes,
                    'created_by' => $userId,
                ], $delta, $index);
            }

            $document->update(['status' => 'approved', 'approved_by' => $userId, 'approved_at' => now()]);

            return $document;
        }, self::ATTEMPTS);
    }

    /**
     * Buat dan langsung setujui (dipakai tes & proses otomatis).
     *
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $items
     */
    public function record(array $header, array $items): DamagedDocument
    {
        return DB::transaction(fn () => $this->approve($this->draft($header, $items), $header['created_by'] ?? null), self::ATTEMPTS);
    }

    private function assertDraft(DamagedDocument $document): void
    {
        if (! $document->isDraft()) {
            throw ValidationException::withMessages(['document' => "{$document->number} sudah disetujui sebelumnya."]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function fillItems(DamagedDocument $document, array $items): void
    {
        foreach (array_values($items) as $row) {
            $document->items()->create([
                'role' => $row['role'] ?? 'input',
                'source' => $row['source'] ?? 'damaged',
                ...collect($row)->except(['role', 'source'])->all(),
            ]);
        }
    }

    /** Perubahan saldo rusak yang ditimbulkan satu baris dokumen. */
    private function damagedDelta(string $type, DamagedDocumentItem $item): int
    {
        return match ($type) {
            'intake' => $item->qty,
            'supplier_return', 'disposal', 'repair' => -$item->qty,
            'assembly' => $item->role === 'input' && $item->source === 'damaged' ? -$item->qty : 0,
        };
    }

    /**
     * Catat mutasi stok baik yang menyertai dokumen. Pesan error StockService
     * memakai indeks baris mutasi, jadi dipetakan balik ke indeks baris form.
     *
     * @param  array<int, array{0: DamagedDocumentItem, 1: int}>  $created
     */
    private function recordGoodStock(DamagedDocument $document, array $created, ?int $userId): void
    {
        $movementType = ['intake' => 'damage', 'repair' => 'repair', 'assembly' => 'assembly'][$document->type] ?? null;

        if (! $movementType) {
            return;
        }

        $rows = [];
        $formIndex = [];

        foreach ($created as [$item, $index]) {
            $row = match ($document->type) {
                'intake' => ['from_location_id' => $item->from_location_id, 'qty' => $item->qty],
                'repair' => ['to_location_id' => $item->to_location_id, 'qty' => $item->qty],
                'assembly' => match (true) {
                    $item->isOutput() => ['to_location_id' => $item->to_location_id, 'qty' => $item->qty],
                    $item->fromGoodStock() => ['from_location_id' => $item->from_location_id, 'qty' => -$item->qty],
                    default => null,
                },
            };

            if ($row) {
                $formIndex[count($rows)] = $index;
                $rows[] = ['product_variant_id' => $item->product_variant_id, ...$row, 'notes' => $item->notes];
            }
        }

        if (! $rows) {
            return;
        }

        try {
            $movement = $this->stock->record([
                'type' => $movementType,
                'warehouse_id' => $document->warehouse_id,
                'movement_date' => $document->document_date->toDateString(),
                'reference' => $document->number,
                'notes' => $document->typeLabel().($document->notes ? ' · '.$document->notes : ''),
                'created_by' => $userId,
            ], $rows);
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->mapWithKeys(function ($messages, $key) use ($formIndex) {
                if (preg_match('/^items\.(\d+)\.(.+)$/', $key, $match)) {
                    $key = 'items.'.($formIndex[(int) $match[1]] ?? $match[1]).'.'.$match[2];
                }

                return [$key => $messages];
            });

            throw ValidationException::withMessages($messages->all());
        }

        $document->update(['stock_movement_id' => $movement->id]);
    }

    /**
     * @param  array<string, mixed>  $ledger
     */
    private function apply(array $ledger, int $delta, int $index): void
    {
        $stock = DamagedStock::withoutGlobalScope('warehouse_access')
            ->where('product_variant_id', $ledger['product_variant_id'])
            ->where('warehouse_id', $ledger['warehouse_id'])
            ->lockForUpdate()
            ->first();

        $current = $stock?->qty ?? 0;

        if ($current + $delta < 0) {
            $sku = ProductVariant::whereKey($ledger['product_variant_id'])->value('sku');

            throw ValidationException::withMessages([
                "items.{$index}.qty" => "Stok rusak {$sku} di gudang ini tidak cukup (tersedia {$current}).",
            ]);
        }

        if ($stock) {
            $stock->update(['qty' => $current + $delta]);
        } else {
            DamagedStock::create([
                'product_variant_id' => $ledger['product_variant_id'],
                'warehouse_id' => $ledger['warehouse_id'],
                'qty' => $delta,
            ]);
        }

        DamagedStockLedger::create([
            ...$ledger,
            'qty_in' => max($delta, 0),
            'qty_out' => max(-$delta, 0),
        ]);
    }

    /**
     * Urutan kunci sama dengan StockService (varian, urut id) supaya dokumen
     * rusak dan mutasi stok baik bersamaan tidak saling deadlock.
     *
     * @param  array<int, int|string>  $variantIds
     */
    private function lockVariants(array $variantIds): void
    {
        $ids = collect($variantIds)->map(fn ($id) => (int) $id)->unique()->sort()->values();

        ProductVariant::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
    }

    /** Nomor dokumen berurutan per jenis per bulan, cth. RSK-202610-0001. */
    private function nextNumber(string $type): string
    {
        $prefix = DamagedDocument::typeMeta($type, 'prefix').'-'.now()->format('Ym').'-';

        $last = DamagedDocument::withoutGlobalScope('warehouse_access')->where('number', 'like', "{$prefix}%")->lockForUpdate()->max('number');
        $sequence = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
