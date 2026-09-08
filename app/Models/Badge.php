<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property string|null $icon
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Cart> $carts
 * @property-read int|null $carts_count
 * @property-read Collection<int, PriceAdjustment> $priceAdjustments
 * @property-read int|null $price_adjustments_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Badge newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Badge newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Badge query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Badge whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Badge whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Badge whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Badge whereIcon($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Badge whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Badge whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Badge whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Badge extends Model
{
    use HasFactory;

    /**
     * کشِ عمومیِ نشان‌ها هیچ‌جا باطل نمی‌شد — هیچ فراخوانِ
     * `Cache::forget('badges')` در کلِ پروژه وجود نداشت.
     */
    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('badges'));
        static::deleted(fn () => Cache::forget('badges'));
    }

    const ID = 'id';

    const NAME = 'name';

    const DESCRIPTION = 'description';

    const CODE = 'code';

    const ICON = 'icon';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::NAME,
        self::DESCRIPTION,
        self::CODE,
        self::ICON,
    ];

    /**
     * Relationship to the price adjustments.
     */
    public function priceAdjustments(): HasMany
    {
        return $this->hasMany(PriceAdjustment::class);
    }

    /**
     * Relationship to the carts.
     */
    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }
}
