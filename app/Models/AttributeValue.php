<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AttributeValue extends Model
{
    public const ID = 'id';

    public const ATTRIBUTE_ID = 'attribute_id';

    public const VALUE = 'value';

    public const DISPLAY_VALUE = 'display_value';

    protected $fillable = [
        self::ATTRIBUTE_ID,
        self::VALUE,
        self::DISPLAY_VALUE,
    ];

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    public function productVariants(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductVariant::class,
            'product_variant_attributes',
            'attribute_value_id',
            'product_variant_id'
        );
    }
}
