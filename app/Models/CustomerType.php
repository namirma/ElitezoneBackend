<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class CustomerType extends Model
{
    /**
     * کشِ عمومیِ `GET /customer-types` هیچ‌جا باطل نمی‌شد — هیچ فراخوانِ
     * `Cache::forget('customer_types')` در کلِ پروژه وجود نداشت.
     */
    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('customer_types'));
        static::deleted(fn () => Cache::forget('customer_types'));
    }

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
