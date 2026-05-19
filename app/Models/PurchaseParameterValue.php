<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PurchaseParameterValue extends Model
{
    const ID = 'id';

    const GROUP_ID = 'group_id';

    const NAME = 'name';

    const SLUG = 'slug';

    const CODE = 'code';

    const DESCRIPTION = 'description';

    const ORDER = 'order';

    const IS_ACTIVE = 'is_active';

    const PRICE_FILTER_CATEGORY_ID = 'price_filter_category_id';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::GROUP_ID,
        self::NAME,
        self::SLUG,
        self::CODE,
        self::DESCRIPTION,
        self::ORDER,
        self::IS_ACTIVE,
        self::PRICE_FILTER_CATEGORY_ID,
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(PurchaseParameterGroup::class, 'group_id');
    }

    public function priceFilterCategory(): BelongsTo
    {
        return $this->belongsTo(PriceFilterCategory::class, 'price_filter_category_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_purchase_parameters');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }
}
