<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $apply_on
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, PriceFilter> $filters
 * @property-read int|null $filters_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterType active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterType forGeneral()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterType forSpecial()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterType query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterType whereApplyOn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterType whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterType whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterType whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterType whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterType whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterType whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterType whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class PriceFilterType extends Model
{
    use HasFactory;

    const ID = 'id';

    const NAME = 'name';

    const CODE = 'code';

    const APPLY_ON = 'apply_on';

    const IS_ACTIVE = 'is_active';

    const SORT_ORDER = 'sort_order';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::NAME,
        self::CODE,
        self::APPLY_ON,
        self::IS_ACTIVE,
        self::SORT_ORDER,
    ];

    protected $casts = [
        self::IS_ACTIVE => 'boolean',
    ];

    public function filters(): HasMany
    {
        return $this->hasMany(PriceFilter::class);
    }

    public function scopeActive($query)
    {
        return $query->where(self::IS_ACTIVE, true);
    }

    public function scopeForGeneral($query)
    {
        return $query->where(function ($q) {
            $q->where(self::APPLY_ON, 'general')
                ->orWhere(self::APPLY_ON, 'both');
        });
    }

    public function scopeForSpecial($query)
    {
        return $query->where(function ($q) {
            $q->where(self::APPLY_ON, 'special')
                ->orWhere(self::APPLY_ON, 'both');
        });
    }
}
