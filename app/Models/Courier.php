<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;

class Courier extends Model
{
    use LogsActivity;

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

    public function activityModule(): string
    {
        return 'couriers';
    }

    public function activityName(): string
    {
        return 'Kurir';
    }
}
