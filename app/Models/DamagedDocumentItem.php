<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DamagedDocumentItem extends Model
{
    protected $fillable = [
        'damaged_document_id',
        'role',
        'source',
        'product_variant_id',
        'from_location_id',
        'to_location_id',
        'qty',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(DamagedDocument::class, 'damaged_document_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'to_location_id');
    }

    public function isOutput(): bool
    {
        return $this->role === 'output';
    }

    public function fromGoodStock(): bool
    {
        return $this->role === 'input' && $this->source === 'good';
    }
}
