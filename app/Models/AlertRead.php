<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Baris notifikasi yang sudah dibaca user dalam satu grup (lihat AlertService). */
class AlertRead extends Model
{
    protected $fillable = ['user_id', 'group', 'item_keys'];

    protected function casts(): array
    {
        return ['item_keys' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
