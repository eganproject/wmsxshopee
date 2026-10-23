<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kombinasi filter daftar yang disimpan satu user (hanya terlihat oleh user itu). */
class SavedFilter extends Model
{
    /** Batas jumlah pencarian tersimpan per user per halaman. */
    public const MAX_PER_PAGE = 20;

    protected $fillable = ['user_id', 'page', 'name', 'query'];

    protected function casts(): array
    {
        return ['query' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
