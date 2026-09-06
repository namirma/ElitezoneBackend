<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $supplier_user_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $logo
 * @property string|null $api_key
 * @property string|null $api_url
 * @property int $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, FactoryLocation> $locations
 * @property-read int|null $locations_count
 * @property-read Collection<int, ProductPrice> $prices
 * @property-read int|null $prices_count
 * @property-read User|null $supplier
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory whereApiKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory whereApiUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory whereLogo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory whereSupplierUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Factory extends Model
{
    const ID = 'id';

    const NAME = 'name';

    const SLUG = 'slug';

    const DESCRIPTION = 'description';

    const LOGO = 'logo';

    const API_KEY = 'api_key';

    const API_URL = 'api_url';

    const IS_ACTIVE = 'is_active';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    const SUPPLIER_USER_ID = 'supplier_user_id';

    protected $fillable = [
        self::NAME,
        self::SLUG,
        self::DESCRIPTION,
        self::LOGO,
        self::API_KEY,
        self::API_URL,
        self::IS_ACTIVE,
        self::SUPPLIER_USER_ID,
    ];

    /**
     * هم‌الگو با `FactoryLocation::booted()` — موجودیِ مبادیِ این کارخانه با
     * Eloquent می‌رود تا رول‌آپِ واریانت دوباره ساخته شود، نه با cascadeِ خاموشِ
     * دیتابیس.
     *
     * امروز هر دو کنترلرِ حذف، کارخانهٔ دارای انبار را رد می‌کنند، پس عملاً
     * کارخانه‌ای که به اینجا می‌رسد ردیف موجودی ندارد. این هوک برای وقتی است که
     * آن گارد عوض شود — کلاسِ باگ بسته می‌شود، نه فقط نمونه‌اش.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $factory): void {
            FactoryStock::purgeForFactory((int) $factory->{self::ID});
        });
    }

    public function locations(): HasMany
    {
        return $this->hasMany(FactoryLocation::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function scopeActive($query)
    {
        return $query->where(self::IS_ACTIVE, true);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, self::SUPPLIER_USER_ID);
    }
}
