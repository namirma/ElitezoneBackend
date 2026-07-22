<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property int $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, PriceFilter> $filters
 * @property-read int|null $filters_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterCategory active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterCategory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterCategory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterCategory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterCategory whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterCategory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterCategory whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterCategory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterCategory whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterCategory whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterCategory whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterCategory whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
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

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('pricing:categories_with_filters'));
        static::deleted(fn () => Cache::forget('pricing:categories_with_filters'));
    }

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
