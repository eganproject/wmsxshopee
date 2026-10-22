<?php

namespace App\Support;

use App\Models\User;

/**
 * Daftar menu pengaturan aplikasi yang dikumpulkan di halaman Pengaturan.
 * Dipakai oleh halaman Pengaturan, sidebar (penanda menu aktif) dan pencarian global.
 */
class SettingsMenu
{
    /**
     * @return array<int, array{heading: string, items: array<int, array<string, mixed>>}>
     */
    public static function groups(): array
    {
        return [
            [
                'heading' => 'Akun',
                'items' => [
                    ['label' => 'Profil Saya', 'description' => 'Nama, email, foto dan password akun Anda.', 'route' => 'profile.edit', 'active' => 'profile.*', 'icon' => 'heroicon-o-user-circle', 'can' => null, 'keywords' => 'password akun'],
                ],
            ],
            [
                'heading' => 'Pengguna & Akses',
                'items' => [
                    ['label' => 'Manajemen User', 'description' => 'Kelola akun pengguna, role dan gudang yang boleh diakses.', 'route' => 'admin.users.index', 'active' => 'admin.users.*', 'icon' => 'heroicon-o-users', 'can' => 'users.view', 'keywords' => 'pengguna akun'],
                    ['label' => 'Role & Permission', 'description' => 'Atur hak akses setiap role terhadap menu dan aksi.', 'route' => 'admin.roles.index', 'active' => 'admin.roles.*', 'icon' => 'heroicon-o-shield-check', 'can' => 'roles.view', 'keywords' => 'hak akses izin'],
                ],
            ],
            [
                'heading' => 'Integrasi',
                'items' => [
                    ['label' => 'Pengaturan Marketplace', 'description' => 'Kredensial app Shopee Open Platform dan TikTok Shop Partner Center.', 'route' => 'marketplace.settings', 'active' => 'marketplace.settings', 'icon' => 'heroicon-o-key', 'can' => ['shopee.settings', 'tiktok.settings'], 'keywords' => 'shopee tiktok api kredensial'],
                ],
            ],
            [
                'heading' => 'Gudang',
                'items' => [
                    ['label' => 'Lokasi Tetap SKU', 'description' => 'Bin langganan tiap SKU per gudang serta stok minimum/maksimum isi ulang.', 'route' => 'inventory.sku-settings.index', 'active' => 'inventory.sku-settings.*', 'icon' => 'heroicon-o-map-pin', 'can' => 'inventory.view', 'keywords' => 'bin rak picking'],
                ],
            ],
            [
                'heading' => 'Sistem',
                'items' => [
                    ['label' => 'Log Aktivitas', 'description' => 'Riwayat perubahan data dan aktivitas pengguna.', 'route' => 'admin.activity.index', 'active' => 'admin.activity.*', 'icon' => 'heroicon-o-clock', 'can' => 'activity.view', 'keywords' => 'audit riwayat'],
                    ['label' => 'Backup Database', 'description' => 'Buat dan unduh cadangan database.', 'route' => 'admin.backups.index', 'active' => 'admin.backups.*', 'icon' => 'heroicon-o-circle-stack', 'can' => 'backups.view', 'keywords' => 'cadangan restore'],
                ],
            ],
        ];
    }

    /**
     * Grup yang berisi menu yang boleh dibuka user (grup kosong dibuang).
     */
    public static function visibleGroups(?User $user): array
    {
        return collect(self::groups())
            ->map(function (array $group) use ($user) {
                $group['items'] = array_values(array_filter($group['items'], fn ($item) => ! $item['can'] || $user?->canAny((array) $item['can'])));

                return $group;
            })
            ->filter(fn (array $group) => $group['items'] !== [])
            ->values()
            ->all();
    }

    /**
     * Pola route yang membuat menu Pengaturan di sidebar tampil aktif.
     */
    public static function activePatterns(): array
    {
        return collect(self::groups())
            ->flatMap(fn ($group) => collect($group['items'])->flatMap(fn ($item) => (array) $item['active']))
            ->prepend('settings')
            ->values()
            ->all();
    }
}
