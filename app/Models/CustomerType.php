<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property int $has_consultant
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Cart> $carts
 * @property-read int|null $carts_count
 * @property-read Collection<int, PriceAdjustment> $priceAdjustments
 * @property-read int|null $price_adjustments_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerType query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerType whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerType whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerType whereHasConsultant($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerType whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerType whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerType whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class CustomerType extends Model
{
    const ID = 'id';

    const NAME = 'name';

    const CODE = 'code';

    const HAS_ELITE_YAR = 'has_elite_yar';

    protected $fillable = [
        self::NAME,
        self::CODE,
        self::HAS_ELITE_YAR,
    ];

    public function priceAdjustments(): HasMany
    {
        return $this->hasMany(PriceAdjustment::class);
    }

    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }
}
