<?php

namespace App\Support;

use App\Models\User;

/**
 * Struktur menu sidebar: tautan tunggal atau grup yang bisa dilipat (gaya ERPNext).
 * Dipakai oleh sidebar (components/layouts/app) dan pencarian global.
 *
 * Halaman yang sengaja tidak punya menu sidebar tapi tetap bisa dicari diberi 'sidebar' => false
 * (Notifikasi sudah ada di ikon bel; Kartu Stok, Mutasi Stok & SKU per Gudang adalah tab Stok;
 * Cetak Label dibuka dari halaman Produk / Gudang; Aplikasi Android ada di Pengaturan).
 */
class Navigation
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function entries(): array
    {
        return [
            ['type' => 'link', 'label' => 'Dashboard', 'route' => 'dashboard', 'active' => 'dashboard', 'icon' => 'heroicon-o-squares-2x2', 'can' => null],
            ['type' => 'link', 'label' => 'Notifikasi', 'route' => 'notifications.index', 'active' => 'notifications.*', 'icon' => 'heroicon-o-bell', 'can' => null, 'sidebar' => false],
            [
                'type' => 'group', 'key' => 'penjualan', 'label' => 'Penjualan', 'icon' => 'heroicon-o-shopping-cart',
                'items' => [
                    // Barang Keluar Manual ada di halaman Pesanan: filter Jenis & pilihan jenis saat membuat baru.
                    ['label' => 'Pesanan', 'route' => 'sales.orders.index', 'active' => 'sales.orders.*', 'icon' => 'heroicon-o-shopping-cart', 'can' => 'sales.view'],
                    ['label' => 'QC Kemas', 'route' => 'sales.qc.index', 'active' => 'sales.qc.*', 'icon' => 'heroicon-o-viewfinder-circle', 'can' => 'sales.fulfill'],
                    ['label' => 'Serah Terima', 'route' => 'sales.handovers.index', 'active' => 'sales.handovers.*', 'icon' => 'heroicon-o-paper-airplane', 'can' => 'sales.fulfill'],
                    ['label' => 'Retur Penjualan', 'route' => 'sales.returns.index', 'active' => 'sales.returns.*', 'icon' => 'heroicon-o-arrow-uturn-left', 'can' => 'returns.view'],
                ],
            ],
            [
                'type' => 'group', 'key' => 'inventori', 'label' => 'Inventori', 'icon' => 'heroicon-o-archive-box',
                'items' => [
                    // Kartu Stok, Mutasi Stok & SKU per Gudang adalah tab di halaman Stok, jadi menu Stok tetap aktif di sana.
                    ['label' => 'Stok', 'route' => 'inventory.stocks.index', 'active' => ['inventory.stocks.*', 'inventory.stock-card.*', 'inventory.movements.*', 'inventory.sku-settings.*', 'inventory.opening-stock.*'], 'icon' => 'heroicon-o-archive-box', 'can' => 'inventory.view'],
                    ['label' => 'Kartu Stok', 'route' => 'inventory.stock-card.index', 'active' => 'inventory.stock-card.*', 'icon' => 'heroicon-o-document-chart-bar', 'can' => 'inventory.view', 'sidebar' => false],
                    ['label' => 'Mutasi Stok', 'route' => 'inventory.movements.index', 'active' => 'inventory.movements.*', 'icon' => 'heroicon-o-arrows-right-left', 'can' => 'inventory.view', 'sidebar' => false],
                    ['label' => 'SKU per Gudang', 'route' => 'inventory.sku-settings.index', 'active' => 'inventory.sku-settings.*', 'icon' => 'heroicon-o-adjustments-horizontal', 'can' => 'inventory.view', 'sidebar' => false, 'keywords' => 'stok minimum maksimum lokasi tetap bin'],
                    ['label' => 'Penerimaan Barang', 'route' => 'inventory.receipts.index', 'active' => 'inventory.receipts.*', 'icon' => 'heroicon-o-inbox-arrow-down', 'can' => 'receipts.view'],
                    ['label' => 'Transfer Gudang', 'route' => 'inventory.transfers.index', 'active' => 'inventory.transfers.*', 'icon' => 'heroicon-o-truck', 'can' => 'inventory.view'],
                    ['label' => 'Penyesuaian Stok', 'route' => 'inventory.adjustments.index', 'active' => 'inventory.adjustments.*', 'icon' => 'heroicon-o-adjustments-horizontal', 'can' => 'adjustments.view'],
                    ['label' => 'Stok Rusak', 'route' => 'inventory.damaged.index', 'active' => 'inventory.damaged.*', 'icon' => 'heroicon-o-exclamation-triangle', 'can' => 'damaged.view'],
                    ['label' => 'Stock Opname', 'route' => 'inventory.counts.index', 'active' => 'inventory.counts.*', 'icon' => 'heroicon-o-clipboard-document-check', 'can' => 'opname.view'],
                ],
            ],
            [
                'type' => 'group', 'key' => 'master', 'label' => 'Master Data', 'icon' => 'heroicon-o-cube',
                'items' => [
                    // Cetak Label dibuka lewat tombol di halaman Produk (label produk) dan Gudang & Lokasi (label lokasi).
                    ['label' => 'Produk', 'route' => 'master.products.index', 'active' => ['master.products.*', 'master.bundles.*', 'master.barcodes.*', 'master.units.*', 'labels.products*'], 'icon' => 'heroicon-o-cube', 'can' => 'products.view'],
                    ['label' => 'Gudang & Lokasi', 'route' => 'master.warehouses.index', 'active' => ['master.warehouses.*', 'master.zones.*', 'master.racks.*', 'master.locations.*', 'labels.locations*'], 'icon' => 'heroicon-o-building-office-2', 'can' => 'warehouses.view'],
                    ['label' => 'Supplier', 'route' => 'master.suppliers.index', 'active' => 'master.suppliers.*', 'icon' => 'heroicon-o-building-storefront', 'can' => 'suppliers.view'],
                    ['label' => 'Kurir', 'route' => 'master.couriers.index', 'active' => 'master.couriers.*', 'icon' => 'heroicon-o-truck', 'can' => 'couriers.view'],
                    ['label' => 'Cetak Label Produk', 'route' => 'labels.products', 'active' => 'labels.products*', 'icon' => 'heroicon-o-qr-code', 'can' => 'products.view', 'sidebar' => false, 'keywords' => 'barcode qr'],
                    ['label' => 'Cetak Label Lokasi', 'route' => 'labels.locations', 'active' => 'labels.locations*', 'icon' => 'heroicon-o-qr-code', 'can' => 'warehouses.view', 'sidebar' => false, 'keywords' => 'barcode qr bin rak'],
                ],
            ],
            // Semua laporan dikumpulkan di satu halaman (App\Support\ReportsMenu); tiap laporan tetap bisa dicari.
            ['type' => 'link', 'label' => 'Laporan', 'route' => 'reports.index', 'active' => 'reports.*', 'icon' => 'heroicon-o-chart-bar', 'can' => 'reports.view'],
            [
                'type' => 'group', 'key' => 'marketplace', 'label' => 'Marketplace', 'icon' => 'heroicon-o-building-storefront',
                'items' => [
                    ['label' => 'Toko Marketplace', 'route' => 'marketplace.shops', 'active' => ['marketplace.shops', 'marketplace.background-tasks', 'shopee.mappings.*', 'tiktok.mappings.*'], 'icon' => 'heroicon-o-building-storefront', 'can' => ['shopee.view', 'tiktok.view']],
                    ['label' => 'Produk Channel', 'route' => 'marketplace.channel-products', 'active' => 'marketplace.channel-products', 'icon' => 'heroicon-o-squares-2x2', 'can' => ['shopee.view', 'tiktok.view']],
                    ['label' => 'Status Produk', 'route' => 'marketplace.products', 'active' => 'marketplace.products', 'icon' => 'heroicon-o-check-badge', 'can' => ['shopee.view', 'tiktok.view']],
                    ['label' => 'Riwayat Sinkron', 'route' => 'marketplace.sync-logs', 'active' => 'marketplace.sync-logs', 'icon' => 'heroicon-o-arrow-path', 'can' => ['shopee.view', 'tiktok.view']],
                ],
            ],
            // Semua menu pengaturan dikumpulkan di halaman Pengaturan (App\Support\SettingsMenu).
            ['type' => 'link', 'label' => 'Pengaturan', 'route' => 'settings', 'active' => SettingsMenu::activePatterns(), 'icon' => 'heroicon-o-cog-6-tooth', 'can' => null],
        ];
    }

    /**
     * Menu yang tampil di sidebar untuk user ini: item tanpa hak akses dibuang, grup kosong dibuang.
     * Grup mendapat 'active' => pola route gabungan anak-anaknya.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function sidebar(?User $user): array
    {
        $allowed = fn (array $item) => ($item['sidebar'] ?? true) && self::allowed($user, $item);

        return collect(self::entries())
            ->map(function (array $entry) use ($allowed) {
                if ($entry['type'] === 'link') {
                    return $allowed($entry) ? $entry : null;
                }

                $entry['items'] = array_values(array_filter($entry['items'], $allowed));
                $entry['active'] = collect($entry['items'])->flatMap(fn ($item) => (array) $item['active'])->values()->all();

                return $entry['items'] === [] ? null : $entry;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Semua halaman yang boleh dibuka user, rata, untuk pencarian global.
     *
     * @return array<int, array{title: string, subtitle: string, route: string, keywords: string}>
     */
    public static function searchPages(?User $user): array
    {
        return collect(self::entries())
            ->flatMap(fn (array $entry) => $entry['type'] === 'link'
                ? [$entry + ['subtitle' => 'Menu']]
                : collect($entry['items'])->map(fn ($item) => $item + ['subtitle' => $entry['label']]))
            ->filter(fn (array $item) => self::allowed($user, $item))
            ->map(fn (array $item) => [
                'title' => $item['label'],
                'subtitle' => $item['subtitle'],
                'route' => $item['route'],
                'keywords' => $item['keywords'] ?? '',
            ])
            ->values()
            ->all();
    }

    private static function allowed(?User $user, array $item): bool
    {
        return ! $item['can'] || $user?->canAny((array) $item['can']);
    }
}
