<?php

namespace App\Support;

use App\Models\Bundle;
use App\Models\GoodsReceipt;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Pencarian global (Ctrl+K): produk/SKU, pesanan, resi, retur, penerimaan,
 * mutasi, opname, lokasi bin & supplier sekaligus. Tiap grup hanya dicari
 * bila user punya hak lihatnya; dokumen gudang otomatis dibatasi ke gudang
 * yang boleh diakses lewat global scope ScopedToWarehouse.
 */
class GlobalSearch
{
    /** Hasil maksimal per grup. */
    public const PER_GROUP = 5;

    /** Panjang kata kunci minimal. */
    public const MIN_LENGTH = 2;

    private string $like;

    public function __construct(private User $user, private string $term)
    {
        $this->term = trim($term);
        // Tanpa escape: % / _ di kata kunci hanya memperluas hasil, dan tetap
        // berlaku sama di MySQL maupun SQLite.
        $this->like = '%'.$this->term.'%';
    }

    /**
     * @return list<array{key: string, label: string, items: list<array{title: string, subtitle: ?string, badge: ?string, url: string}>}>
     */
    public function results(): array
    {
        if (mb_strlen($this->term) < self::MIN_LENGTH) {
            return [];
        }

        $groups = [
            ['products', 'Produk & SKU', 'products.view', fn () => $this->variants()],
            ['bundles', 'Produk Paket', 'products.view', fn () => $this->bundles()],
            ['orders', 'Pesanan', 'sales.view', fn () => $this->orders()],
            ['returns', 'Retur Penjualan', 'returns.view', fn () => $this->returns()],
            ['receipts', 'Penerimaan Barang', 'receipts.view', fn () => $this->receipts()],
            ['movements', 'Mutasi Stok', 'inventory.view', fn () => $this->movements()],
            ['counts', 'Stock Opname', 'opname.view', fn () => $this->counts()],
            ['locations', 'Lokasi Bin', 'warehouses.view', fn () => $this->locations()],
            ['suppliers', 'Supplier', 'suppliers.view', fn () => $this->suppliers()],
        ];

        $results = [];

        foreach ($groups as [$key, $label, $permission, $search]) {
            if (! $this->user->can($permission)) {
                continue;
            }

            $items = $search()->values()->all();

            if ($items !== []) {
                $results[] = ['key' => $key, 'label' => $label, 'items' => $items];
            }
        }

        return $results;
    }

    /**
     * Kecocokan persis (mis. hasil scan barcode / resi) ditaruh paling atas.
     *
     * @param  list<string>  $columns
     */
    private function exactFirst(Builder $query, array $columns): Builder
    {
        $cases = collect($columns)->map(fn ($column) => "{$query->getModel()->qualifyColumn($column)} = ?")->join(' OR ');

        return $query->orderByRaw("CASE WHEN {$cases} THEN 0 ELSE 1 END", array_fill(0, count($columns), $this->term));
    }

    private function variants(): Collection
    {
        $query = ProductVariant::query()
            ->with('product:id,name')
            ->where(fn (Builder $query) => $query
                ->where('sku', 'like', $this->like)
                ->orWhere('barcode', 'like', $this->like)
                ->orWhere('name', 'like', $this->like)
                ->orWhereHas('product', fn (Builder $query) => $query
                    ->where('name', 'like', $this->like)
                    ->orWhere('sku', 'like', $this->like)));

        $canEdit = $this->user->can('products.edit');

        return $this->exactFirst($query, ['sku', 'barcode'])
            ->orderBy('sku')
            ->limit(self::PER_GROUP)
            ->get()
            ->map(fn (ProductVariant $variant) => [
                'title' => $variant->sku,
                'subtitle' => $variant->product?->name.($variant->name ? ' / '.$variant->name : '').($variant->barcode ? " · {$variant->barcode}" : ''),
                'badge' => $variant->is_active ? null : 'Nonaktif',
                'url' => $canEdit && $variant->product_id
                    ? route('master.products.edit', $variant->product_id)
                    : route('master.products.index', ['search' => $variant->sku]),
            ]);
    }

    private function bundles(): Collection
    {
        $query = Bundle::query()->where(fn (Builder $query) => $query
            ->where('sku', 'like', $this->like)
            ->orWhere('barcode', 'like', $this->like)
            ->orWhere('name', 'like', $this->like));

        $canEdit = $this->user->can('products.edit');

        return $this->exactFirst($query, ['sku', 'barcode'])
            ->orderBy('sku')
            ->limit(self::PER_GROUP)
            ->get()
            ->map(fn (Bundle $bundle) => [
                'title' => $bundle->sku,
                'subtitle' => $bundle->name,
                'badge' => $bundle->is_active ? 'Paket' : 'Nonaktif',
                'url' => $canEdit ? route('master.bundles.edit', $bundle) : route('master.bundles.index', ['search' => $bundle->sku]),
            ]);
    }

    private function orders(): Collection
    {
        $query = SalesOrder::query()
            ->with('warehouse:id,code')
            ->where(fn (Builder $query) => $query
                ->where('number', 'like', $this->like)
                ->orWhere('external_number', 'like', $this->like)
                ->orWhere('tracking_number', 'like', $this->like)
                ->orWhere('customer_name', 'like', $this->like)
                ->orWhere('customer_phone', 'like', $this->like));

        return $this->exactFirst($query, ['number', 'external_number', 'tracking_number'])
            ->latest('order_date')
            ->latest('id')
            ->limit(self::PER_GROUP)
            ->get()
            ->map(fn (SalesOrder $order) => [
                'title' => $order->number.($order->external_number ? " · {$order->external_number}" : ''),
                'subtitle' => collect([
                    $order->customer_name,
                    $order->tracking_number ? "Resi {$order->tracking_number}" : null,
                    $order->channel_name,
                    $order->warehouse?->code,
                    $order->order_date?->format('d/m/Y'),
                ])->filter()->join(' · '),
                'badge' => $order->statusLabel(),
                'url' => route('sales.orders.show', $order),
            ]);
    }

    private function returns(): Collection
    {
        $query = SalesReturn::query()
            ->with('order:id,number,external_number')
            ->where(fn (Builder $query) => $query
                ->where('number', 'like', $this->like)
                ->orWhere('tracking_number', 'like', $this->like)
                ->orWhere('sender_name', 'like', $this->like)
                ->orWhereHas('order', fn (Builder $query) => $query
                    ->where('number', 'like', $this->like)
                    ->orWhere('external_number', 'like', $this->like)
                    ->orWhere('tracking_number', 'like', $this->like)));

        return $this->exactFirst($query, ['number', 'tracking_number'])
            ->latest('return_date')
            ->latest('id')
            ->limit(self::PER_GROUP)
            ->get()
            ->map(fn (SalesReturn $return) => [
                'title' => $return->number,
                'subtitle' => collect([
                    $return->order ? "Pesanan {$return->order->number}" : $return->sourceLabel(),
                    $return->tracking_number ? "Resi {$return->tracking_number}" : null,
                    $return->sender_name,
                    $return->return_date?->format('d/m/Y'),
                ])->filter()->join(' · '),
                'badge' => $return->statusLabel(),
                'url' => route('sales.returns.show', $return),
            ]);
    }

    private function receipts(): Collection
    {
        $query = GoodsReceipt::query()
            ->with(['supplier:id,name', 'warehouse:id,code'])
            ->where(fn (Builder $query) => $query
                ->where('number', 'like', $this->like)
                ->orWhere('supplier_reference', 'like', $this->like)
                ->orWhereHas('supplier', fn (Builder $query) => $query->where('name', 'like', $this->like)));

        return $this->exactFirst($query, ['number', 'supplier_reference'])
            ->latest('receipt_date')
            ->latest('id')
            ->limit(self::PER_GROUP)
            ->get()
            ->map(fn (GoodsReceipt $receipt) => [
                'title' => $receipt->number,
                'subtitle' => collect([
                    $receipt->supplier?->name,
                    $receipt->supplier_reference ? "Ref. {$receipt->supplier_reference}" : null,
                    $receipt->warehouse?->code,
                    $receipt->receipt_date?->format('d/m/Y'),
                ])->filter()->join(' · '),
                'badge' => $receipt->statusLabel(),
                'url' => route('inventory.receipts.show', $receipt),
            ]);
    }

    private function movements(): Collection
    {
        $query = StockMovement::query()
            ->with('warehouse:id,code')
            ->where(fn (Builder $query) => $query
                ->where('number', 'like', $this->like)
                ->orWhere('reference', 'like', $this->like));

        return $this->exactFirst($query, ['number', 'reference'])
            ->latest('movement_date')
            ->latest('id')
            ->limit(self::PER_GROUP)
            ->get()
            ->map(fn (StockMovement $movement) => [
                'title' => $movement->number,
                'subtitle' => collect([
                    $movement->reference ? "Ref. {$movement->reference}" : null,
                    $movement->warehouse?->code,
                    $movement->movement_date?->format('d/m/Y'),
                ])->filter()->join(' · '),
                'badge' => $movement->typeLabel(),
                'url' => route('inventory.movements.show', $movement),
            ]);
    }

    private function counts(): Collection
    {
        $query = StockCount::query()
            ->with(['warehouse:id,code', 'zone:id,code,name'])
            ->where('number', 'like', $this->like);

        return $this->exactFirst($query, ['number'])
            ->latest('count_date')
            ->latest('id')
            ->limit(self::PER_GROUP)
            ->get()
            ->map(fn (StockCount $count) => [
                'title' => $count->number,
                'subtitle' => collect([$count->scopeLabel(), $count->warehouse?->code, $count->count_date?->format('d/m/Y')])->filter()->join(' · '),
                'badge' => $count->statusLabel(),
                'url' => route('inventory.counts.show', $count),
            ]);
    }

    private function locations(): Collection
    {
        $query = WarehouseLocation::query()
            ->with('warehouse:id,code,name')
            ->where('code', 'like', $this->like);

        $canEdit = $this->user->can('warehouses.edit');

        return $this->exactFirst($query, ['code'])
            ->orderBy('code')
            ->limit(self::PER_GROUP)
            ->get()
            ->map(fn (WarehouseLocation $location) => [
                'title' => $location->code,
                'subtitle' => $location->warehouse?->name,
                'badge' => $location->is_active ? null : 'Nonaktif',
                'url' => $canEdit
                    ? route('master.locations.edit', $location)
                    : route('master.locations.index', ['search' => $location->code]),
            ]);
    }

    private function suppliers(): Collection
    {
        $query = Supplier::query()->where(fn (Builder $query) => $query
            ->where('code', 'like', $this->like)
            ->orWhere('name', 'like', $this->like)
            ->orWhere('contact_name', 'like', $this->like)
            ->orWhere('phone', 'like', $this->like));

        $canEdit = $this->user->can('suppliers.edit');

        return $this->exactFirst($query, ['code'])
            ->orderBy('name')
            ->limit(self::PER_GROUP)
            ->get()
            ->map(fn (Supplier $supplier) => [
                'title' => $supplier->name,
                'subtitle' => collect([$supplier->code, $supplier->contact_name, $supplier->phone])->filter()->join(' · '),
                'badge' => $supplier->is_active ? null : 'Nonaktif',
                'url' => $canEdit ? route('master.suppliers.edit', $supplier) : route('master.suppliers.index', ['search' => $supplier->code]),
            ]);
    }
}
