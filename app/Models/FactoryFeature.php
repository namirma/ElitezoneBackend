<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FactoryFeature extends Model
{
    use HasFactory;

    public const ID = 'id';

    public const FACTORY_ID = 'factory_id';

    public const CATEGORY = 'category';

    public const ICON_KEY = 'icon_key';

    public const LABEL = 'label';

    public const ORDER = 'order';

    public const IS_ACTIVE = 'is_active';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    /**
     * دو دسته‌ی کاملاً جدا — هرگز در یک کارت با هم مخلوط نمی‌شوند:
     *   product  → کارتِ محصول (ویژگیِ برجسته‌ی تأمین‌کننده/کارخانه، مثلِ تولیدِ تمیز)
     *   shipping → فوترِ «محاسبه کل هزینه حمل» صفحه‌ی سفارش (ویژگیِ لجستیکِ همان کارخانه)
     */
    public const CATEGORY_PRODUCT = 'product';

    public const CATEGORY_SHIPPING = 'shipping';

    public const CATEGORIES = [
        self::CATEGORY_PRODUCT,
        self::CATEGORY_SHIPPING,
    ];

    /**
     * کتابخانه‌ی ثابتِ آیکون — هر مقدار به یک کامپوننتِ SVG در فرانت نگاشت می‌شود
     * (src/assets/Icons/FactoryFeatures/). آپلودِ آزادِ آیکون عمداً پشتیبانی
     * نمی‌شود تا هارمونیِ بصریِ طراحی حفظ شود. هر آیکون فقط برای دسته‌ی خودش
     * مجاز است (اعتبارسنجیِ ترکیبِ category+icon_key در AdminFactoryFeatureController).
     */
    public const PRODUCT_ICON_KEYS = [
        'water-saving',
        'dust-reduction',
        'clean-production',
    ];

    public const SHIPPING_ICON_KEYS = [
        'live-shipping',
        'road-access',
    ];

    protected $fillable = [
        self::FACTORY_ID,
        self::CATEGORY,
        self::ICON_KEY,
        self::LABEL,
        self::ORDER,
        self::IS_ACTIVE,
    ];

    protected $casts = [
        self::IS_ACTIVE => 'boolean',
        self::ORDER => 'integer',
    ];

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class);
    }

    /** آیکون‌های مجازِ یک دسته — برای اعتبارسنجی و برای فرم ادمین. */
    public static function iconKeysFor(string $category): array
    {
        return $category === self::CATEGORY_SHIPPING ? self::SHIPPING_ICON_KEYS : self::PRODUCT_ICON_KEYS;
    }
}
