<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $unit
 * @property numeric|null $amount
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Product> $products
 * @property-read int|null $products_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUnit newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUnit newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUnit query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUnit whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUnit whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUnit whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUnit whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUnit whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUnit whereUnit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUnit whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class OrderUnit extends Model
{
    use HasFactory;

    const ID = 'id';

    const NAME = 'name';

    const CODE = 'code';

    const UNIT = 'unit';

    const AMOUNT = 'amount';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::NAME,
        self::CODE,
        self::UNIT,
        self::AMOUNT,
    ];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_order_units')
            ->withPivot('max_quantity')
            ->withTimestamps();
    }

    /**
     * تمام OrderUnit‌ها کی‌شده با ID — یک کش مرکزی برای همه callerها.
     * TTL 24h؛ booted() آن را پس از هر save/delete invalidate می‌کند.
     */
    public static function allCached(): Collection
    {
        return Cache::remember('catalog:order_units', 86400, fn () => static::all()->keyBy(static::ID));
    }

    protected static function booted(): void
    {
        $flush = function () {
            foreach ([
                'catalog:order_units',
                'order_units_all',
                'order_units_map',
                'pricing:order_units_collection',
                'all_order_units',
            ] as $key) {
                Cache::forget($key);
            }
        };

        static::saved($flush);
        static::deleted($flush);
    }
}
