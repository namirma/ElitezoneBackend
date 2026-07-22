<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $title
 * @property string|null $description
 * @property string|null $code
 * @property string $type
 * @property numeric|null $value
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property int $minimum_quantity
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Product> $products
 * @property-read int|null $products_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer current()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer whereEndDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer whereMinimumQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer whereStartDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer whereValue($value)
 *
 * @mixin \Eloquent
 */
class Offer extends Model
{
    public const ID = 'id';

    public const TITLE = 'title';

    public const DESCRIPTION = 'description';

    public const CODE = 'code';

    public const TYPE = 'type';

    public const VALUE = 'value';

    public const START_DATE = 'start_date';

    public const END_DATE = 'end_date';

    public const MINIMUM_QUANTITY = 'minimum_quantity';

    public const IS_ACTIVE = 'is_active';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::TITLE,
        self::DESCRIPTION,
        self::CODE,
        self::TYPE,
        self::VALUE,
        self::START_DATE,
        self::END_DATE,
        self::MINIMUM_QUANTITY,
        self::IS_ACTIVE,
    ];

    protected $casts = [
        self::START_DATE => 'datetime',
        self::END_DATE => 'datetime',
        self::IS_ACTIVE => 'boolean',
    ];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_offers')
            ->withPivot('associated_at')
            ->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where(self::IS_ACTIVE, true);
    }

    public function scopeCurrent($query)
    {
        return $query->active()
            ->where(function ($q) {
                $q->whereNull(self::START_DATE)
                    ->orWhere(self::START_DATE, '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull(self::END_DATE)
                    ->orWhere(self::END_DATE, '>=', now());
            });
    }
}
