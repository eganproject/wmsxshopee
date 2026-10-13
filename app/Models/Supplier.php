<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = [
        'code',
        'name',
        'contact_name',
        'phone',
        'email',
        'address',
        'city',
        'npwp',
        'payment_term_days',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'payment_term_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function paymentTermLabel(): ?string
    {
        return match (true) {
            $this->payment_term_days === null => null,
            $this->payment_term_days === 0 => 'Tunai',
            default => "{$this->payment_term_days} hari",
        };
    }
}
