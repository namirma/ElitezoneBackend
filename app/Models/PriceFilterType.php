<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class PriceFilterType extends Model
{
    use HasFactory;

    /**
     * کشِ عمومیِ `GET /price-filters/types` هیچ‌جا باطل نمی‌شد — هیچ فراخوانِ
     * `Cache::forget('price_filter_types')` در کلِ پروژه وجود نداشت. ادمین مقدار
     * را عوض می‌کرد و endpoint تا یک ساعت مقدارِ قدیمی را برمی‌گرداند.
     */
    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('price_filter_types'));
        static::deleted(fn () => Cache::forget('price_filter_types'));
    }

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
