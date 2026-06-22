<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

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
