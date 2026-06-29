<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Cache;

class PriceFilter extends Model
{
    use HasFactory;

    public const ID = 'id';

    public const PRICE_FILTER_TYPE_ID = 'price_filter_type_id';

    public const NAME = 'name';

    public const CODE = 'code';

    public const AMOUNT = 'amount';

    public const IS_PERCENTAGE = 'is_percentage';

    public const IS_ACTIVE = 'is_active';

    public const SORT_ORDER = 'sort_order';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('pricing:categories_with_filters'));
        static::deleted(fn () => Cache::forget('pricing:categories_with_filters'));
    }

    protected $fillable = [
        self::PRICE_FILTER_TYPE_ID,
        self::NAME,
        self::CODE,
        self::AMOUNT,
        self::IS_PERCENTAGE,
        self::IS_ACTIVE,
        self::SORT_ORDER,
    ];

    protected $casts = [
        self::IS_PERCENTAGE => 'boolean',
        self::IS_ACTIVE => 'boolean',
    ];

    public function filterType(): BelongsTo
    {
        return $this->belongsTo(PriceFilterType::class, self::PRICE_FILTER_TYPE_ID);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            PriceFilterCategory::class,
            'price_filter_category_items',
            self::ID,
            'price_filter_category_id'
        )
            ->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where(self::IS_ACTIVE, true);
    }

    public function getFilterValue($baseAmount)
    {
        if ($this->is_percentage) {
            return $baseAmount * ($this->amount / 100);
        }

        return $this->amount;
    }
}
