<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SKU yang tidak perlu di-scan saat QC kemas (hadiah, bonus, bahan kemasan):
 * baris pesanannya otomatis dianggap cocok.
 */
class QcExemptVariant extends Model
{
    use LogsActivity;

    protected $fillable = [
        'product_variant_id',
        'reason',
        'created_by',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activityModule(): string
    {
        return 'sales';
    }

    public function activityName(): string
    {
        return 'SKU tanpa scan QC';
    }

    public function activityLabel(): string
    {
        return (string) ($this->variant?->sku ?? '#'.$this->product_variant_id);
    }
}
