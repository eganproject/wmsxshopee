<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Mengelompokkan permission per modul berdasarkan config/permissions.php,
 * untuk ditampilkan sebagai matriks checkbox di form.
 */
class PermissionGroups
{
    /**
     * @param  Collection<int, string>|null  $only  batasi ke permission tertentu
     * @return Collection<int, array{key: string, label: string, icon: string, permissions: Collection}>
     */
    public static function all(?Collection $only = null): Collection
    {
        return collect(config('permissions.modules'))
            ->map(fn (array $module, string $key) => [
                'key' => $key,
                'label' => $module['label'],
                'icon' => $module['icon'],
                'permissions' => collect($module['actions'])
                    ->map(fn (string $label, string $action) => [
                        'name' => "{$key}.{$action}",
                        'label' => $label,
                    ])
                    ->filter(fn (array $permission) => $only === null || $only->contains($permission['name']))
                    ->values(),
            ])
            ->filter(fn (array $group) => $group['permissions']->isNotEmpty())
            ->values();
    }

    public static function label(string $permission): string
    {
        [$module, $action] = array_pad(explode('.', $permission, 2), 2, null);

        return config("permissions.modules.{$module}.actions.{$action}", $permission);
    }
}
