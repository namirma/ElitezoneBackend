<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


class ProductPrice extends Model
{
    protected $fillable = [
        'product_variant_id', 'factory_id', 'factory_location_id',
        'general_price', 'special_price', 'golden_figure', 'last_updated_at'
    ];

    protected $casts = [
        'last_updated_at' => 'datetime',
    ];

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class);
    }

    public function factoryLocation(): BelongsTo
    {
        return $this->belongsTo(FactoryLocation::class);
    }
}
