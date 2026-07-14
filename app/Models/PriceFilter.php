<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Cache;

/**
 * @property int $id
 * @property int $price_filter_type_id
 * @property string $name
 * @property string $code
 * @property string $amount
 * @property bool $is_percentage
 * @property bool $is_active
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PriceFilterCategory> $categories
 * @property-read int|null $categories_count
 * @property-read \App\Models\PriceFilterType $filterType
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilter active()
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilter newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilter newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilter query()
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilter whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilter whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilter whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilter whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilter whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilter whereIsPercentage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilter whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilter wherePriceFilterTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilter whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilter whereUpdatedAt($value)
 * @mixin \Eloquent
 */
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
