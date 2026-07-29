<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FactoryLocation extends Model
{
    use HasFactory;

    public const ID = 'id';

    public const FACTORY_ID = 'factory_id';

    public const NAME = 'name';

    public const TYPE = 'type';

    public const CITY = 'city';

    public const ADDRESS = 'address';

    public const IS_ACTIVE = 'is_active';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::FACTORY_ID,
        self::NAME,
        self::TYPE,
        self::CITY,
        self::ADDRESS,
        self::IS_ACTIVE,
    ];

    protected $casts = [
        self::IS_ACTIVE => 'boolean',
    ];

    /**
     * حذفِ انبار باید موجودیِ همان مبدأ را هم ببرد — **از راه Eloquent**، نه با
     * cascadeِ دیتابیس، وگرنه رول‌آپِ `product_variants.stock_quantity` به‌روز
     * نمی‌شود. جزئیات در `FactoryStock::purgeForLocation()`.
     *
     * اینجا و نه در کنترلر، چون سه مسیرِ حذف وجود دارد (پنل تأمین‌کننده، پنل
     * ادمین، و cascadeِ حذفِ کارخانه) و گاردِ کنترلری فقط دوتای اول را می‌گرفت.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $location): void {
            FactoryStock::purgeForLocation((int) $location->{self::ID});
        });
    }

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function scopeActive($query)
    {
        return $query->where(self::IS_ACTIVE, true);
    }
}
