<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Faq extends Model
{
    use HasFactory;
    use SoftDeletes;

    const TABLE = 'faqs';

    const ID = 'id';

    const FAQ_CATEGORY_ID = 'faq_category_id';

    const QUESTION = 'question';

    const ANSWER = 'answer';

    const CATEGORY = 'category';

    const SORT_ORDER = 'sort_order';

    const IS_ACTIVE = 'is_active';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    /** کش لیست فعال برای نمایش عمومی (ContentController) */
    const CACHE_KEY = 'content:faqs:active';

    protected $table = self::TABLE;

    protected $fillable = [
        self::FAQ_CATEGORY_ID,
        self::QUESTION,
        self::ANSWER,
        self::CATEGORY,
        self::SORT_ORDER,
        self::IS_ACTIVE,
    ];

    protected $casts = [
        self::FAQ_CATEGORY_ID => 'integer',
        self::SORT_ORDER => 'integer',
        self::IS_ACTIVE => 'boolean',
    ];

    /**
     * دسته‌ی سوال. ⚠️ ستون قدیمیِ رشته‌ایِ `category` هنوز برای سازگاری عقب‌رو
     * (فیلترِ `GET /faq?category=`) وجود دارد؛ برای رکوردهای جدید از این رابطه
     * استفاده کنید، نه آن رشته.
     */
    public function faqCategory(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FaqCategory::class, self::FAQ_CATEGORY_ID);
    }

    protected static function booted(): void
    {
        // سوال که عوض شود، درختِ کش‌شده‌ی دسته‌ها هم باید باطل شود
        $flush = function () {
            \Illuminate\Support\Facades\Cache::forget(self::CACHE_KEY);
            \Illuminate\Support\Facades\Cache::forget(FaqCategory::CACHE_KEY);
        };

        static::saved($flush);
        static::deleted($flush);
        static::restored($flush);
    }
}
