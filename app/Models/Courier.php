<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Courier extends Model
{
    protected $fillable = [
        'code',
        'name',
        'services',
        'tracking_url',
        'contact_name',
        'contact_phone',
        'pickup_cutoff',
        'shopee_logistics_channel_id',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'services' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** URL lacak untuk nomor resi tertentu; placeholder {resi} di tracking_url akan diganti. */
    public function trackingUrlFor(string $trackingNumber): ?string
    {
        return $this->tracking_url
            ? str_replace('{resi}', urlencode($trackingNumber), $this->tracking_url)
            : null;
    }
}
