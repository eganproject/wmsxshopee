<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsActivity, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'phone',
        'password',
        'is_active',
        'all_warehouses',
        'created_by',
    ];

    /**
     * User baru default bisa mengakses semua gudang (sama dengan default kolom).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'all_warehouses' => true,
    ];

    /** @var list<int>|null Cache ID gudang yang boleh diakses. */
    protected ?array $warehouseIdsCache = null;

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'all_warehouses' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Gudang yang ditugaskan ke user (hanya berlaku kalau all_warehouses = false).
     */
    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class);
    }

    public function hasAllWarehouses(): bool
    {
        return $this->all_warehouses || $this->isSuperAdmin();
    }

    /**
     * ID gudang yang boleh diakses, atau null kalau semua gudang. Dibaca
     * langsung dari tabel pivot agar tidak terkena global scope gudang.
     *
     * @return list<int>|null
     */
    public function accessibleWarehouseIds(): ?array
    {
        if ($this->hasAllWarehouses()) {
            return null;
        }

        return $this->warehouseIdsCache ??= DB::table('user_warehouse')
            ->where('user_id', $this->getKey())
            ->pluck('warehouse_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function canAccessWarehouse(?int $warehouseId): bool
    {
        $ids = $this->accessibleWarehouseIds();

        return $ids === null || in_array($warehouseId, $ids, true);
    }

    /**
     * @param  list<int>  $warehouseIds
     */
    public function syncWarehouseAccess(bool $all, array $warehouseIds): void
    {
        $this->all_warehouses = $all;
        $this->save();
        $this->warehouses()->sync($all ? [] : $warehouseIds);
        $this->warehouseIdsCache = null;
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(config('permissions.super_admin_role'));
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn (string $word) => Str::upper(Str::substr($word, 0, 1)))
            ->implode('');
    }

    /**
     * User hanya boleh mengelola user lain yang hak aksesnya tidak melebihi
     * miliknya sendiri, sehingga tidak bisa "mengambil alih" akun yang lebih tinggi.
     */
    public function canManage(User $target): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($target->isSuperAdmin()) {
            return false;
        }

        // Akses gudang target juga tidak boleh melebihi milik sendiri.
        $own = $this->accessibleWarehouseIds();
        if ($own !== null) {
            $theirs = $target->accessibleWarehouseIds();
            if ($theirs === null || array_diff($theirs, $own)) {
                return false;
            }
        }

        return $target->getAllPermissions()->pluck('name')
            ->diff($this->getAllPermissions()->pluck('name'))
            ->isEmpty();
    }

    /**
     * Permission yang boleh diberikan user ini ke user / role lain.
     * Mencegah user memberikan hak akses melebihi miliknya sendiri.
     *
     * @return Collection<int, Permission>
     */
    public function grantablePermissions(): Collection
    {
        return $this->isSuperAdmin()
            ? Permission::all()
            : $this->getAllPermissions();
    }

    /**
     * Role yang boleh di-assign user ini ke user lain: hanya role yang
     * seluruh permission-nya juga dimiliki user ini. Role Super Admin
     * hanya bisa di-assign oleh Super Admin.
     *
     * @return Collection<int, Role>
     */
    public function assignableRoles(): Collection
    {
        $roles = Role::with('permissions')->orderBy('name')->get();

        if ($this->isSuperAdmin()) {
            return $roles;
        }

        $own = $this->getAllPermissions()->pluck('name');

        return $roles
            ->reject(fn (Role $role) => $role->name === config('permissions.super_admin_role'))
            ->filter(fn (Role $role) => $role->permissions->pluck('name')->diff($own)->isEmpty())
            ->values();
    }

    public function activityModule(): string
    {
        return 'users';
    }

    public function activityName(): string
    {
        return 'User';
    }

    public function activityLabel(): string
    {
        return $this->username ? "{$this->name} ({$this->username})" : $this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function activityRelations(): array
    {
        return [
            'Role' => $this->roles()->orderBy('name')->pluck('name')->all(),
            'Permission Langsung' => $this->permissions()->orderBy('name')->pluck('name')->all(),
            'Akses Gudang' => $this->all_warehouses
                ? ['Semua gudang']
                : Warehouse::withoutGlobalScope('warehouse_access')->whereIn('id', $this->warehouses()->allRelatedIds())->orderBy('code')->pluck('code')->all(),
        ];
    }
}
