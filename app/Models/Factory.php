<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function features(): HasMany
    {
        return $this->hasMany(FactoryFeature::class);
    }

    /**
     * دو رابطه‌ی جدا برای دو دسته‌ی کاملاً مجزا — عمداً combین نمی‌شوند تا
     * کارتِ محصول و فوترِ حمل هرگز ویژگیِ همدیگر را نبینند.
     */
    public function activeProductFeatures(): HasMany
    {
        return $this->hasMany(FactoryFeature::class)
            ->where(FactoryFeature::CATEGORY, FactoryFeature::CATEGORY_PRODUCT)
            ->where(FactoryFeature::IS_ACTIVE, true)
            ->orderBy(FactoryFeature::ORDER);
    }

    public function activeShippingFeatures(): HasMany
    {
        return $this->hasMany(FactoryFeature::class)
            ->where(FactoryFeature::CATEGORY, FactoryFeature::CATEGORY_SHIPPING)
            ->where(FactoryFeature::IS_ACTIVE, true)
            ->orderBy(FactoryFeature::ORDER);
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
