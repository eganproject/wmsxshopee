<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu versi APK Optima Gudang yang bisa diunduh & dipasang dari dalam aplikasi. */
class MobileAppRelease extends Model
{
    use LogsActivity;

    /** Disk penyimpanan APK (privat; diunduh lewat link bertanda tangan). */
    public const DISK = 'local';

    protected $fillable = [
        'version_code',
        'version_name',
        'changelog',
        'is_mandatory',
        'file_path',
        'file_size',
        'sha256',
        'source',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'version_code' => 'integer',
            'file_size' => 'integer',
            'is_mandatory' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function latestRelease(): ?self
    {
        return static::query()->orderByDesc('version_code')->first();
    }

    public function fileName(): string
    {
        return "optima-gudang-{$this->version_name}.apk";
    }

    public function activityModule(): string
    {
        return 'mobile';
    }

    public function activityName(): string
    {
        return 'Versi aplikasi Android';
    }

    public function activityLabel(): string
    {
        return "{$this->version_name} (build {$this->version_code})";
    }

    /** @return list<string> */
    public function activityIgnored(): array
    {
        return ['file_path', 'sha256', 'file_size', 'created_by'];
    }
}
