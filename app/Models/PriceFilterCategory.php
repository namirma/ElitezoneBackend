<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PriceFilterCategory extends Model
{
    const ID = 'id';

    const NAME = 'name';

    const CODE = 'code';

    const DESCRIPTION = 'description';

    const IS_ACTIVE = 'is_active';

    const SORT_ORDER = 'sort_order';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::NAME, self::CODE, self::DESCRIPTION, self::IS_ACTIVE, self::SORT_ORDER,
    ];

    public function filters(): BelongsToMany
    {
        return $this->belongsToMany(
            PriceFilter::class,
            'price_filter_category_items',
            'price_filter_category_id',
            'price_filter_id'
        )->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where(self::IS_ACTIVE, true);
    }
}
