<?php

namespace App\Models;

use App\Traits\PersianDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @method static updateOrCreate(array $array, array $array1)
 * @method static where(array $array)
 * @method static withoutChildren()
 */
class SupplierProfile extends Model
{
    use HasFactory, PersianDate;

    const ID = 'id';

    const USER_ID = 'user_id';

    const ACTIVITY_DESCRIPTION = 'activity_description';

    const BRANDS = 'brands';

    const BRANDS_NOT_RELATED = 'brands_not_related';

    const ACTIVITY_HISTORY = 'activity_history';

    const ACTIVITY_PROVINCES = 'activity_provinces';

    const PRODUCTION_AMOUNT = 'production_amount';

    const STANDARDS = 'standards';

    const STANDARDS_NOT_RELATED = 'standards_not_related';

    const WEBSITE = 'website';

    const WEBSITE_NOT_RELATED = 'website_not_related';

    const COMPANY = 'company';

    /** +%TC: کمیسیون روی هزینه حمل اعمال می‌شود؟ (پیش‌فرض: بله) */
    const TRANS_COMMISSION = 'trans_commission';

    /** +VAT: مالیات بر ارزش افزوده روی هزینه حمل اعمال می‌شود؟ (پیش‌فرض: بله) */
    const TRANS_VAT = 'trans_vat';

    /** وضعیت احراز سوپلایر — نقش supplier فقط پس از تأیید ادمین متصل می‌شود. */
    const STATUS = 'status';

    const STATUS_PENDING = 'pending';

    const STATUS_APPROVED = 'approved';

    const STATUS_REJECTED = 'rejected';

    /**
     * پایان اعتبار قیمت‌های این تأمین‌کننده — datetime مطلق (بند ۲.۲ / ۳.۲).
     *
     * تا این لحظه قیمت‌ها تغییر نمی‌کنند. با گذشتنش و نبودِ قیمت جدید،
     * محصولات از فروش خارج می‌شوند. `null` هم یعنی «هنوز اعلام نشده» و
     * همان اثر را دارد.
     */
    const RESERVE_TIME = 'reserve_time';

    /** تمدید خودکار برای رزروِ دیرهنگام، به دقیقه (بند ۲.۲). */
    const RESERVE_AUTO_EXTEND_MINUTES = 'reserve_auto_extend_minutes';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        self::USER_ID,
        self::ACTIVITY_DESCRIPTION,
        self::BRANDS,
        self::BRANDS_NOT_RELATED,
        self::ACTIVITY_HISTORY,
        self::ACTIVITY_PROVINCES,
        self::PRODUCTION_AMOUNT,
        self::STANDARDS,
        self::STANDARDS_NOT_RELATED,
        self::WEBSITE,
        self::WEBSITE_NOT_RELATED,
        self::COMPANY,
        // این دو **برخلافِ** ردیف زیر عمداً fillable هستند: خودِ تأمین‌کننده
        // تنظیمشان می‌کند (بند ۴.۳)، نه ادمین. گاردهای «باید در آینده باشد» و
        // «کاهش ممنوع» در `UpdateSupplierReserveTimeRequest` اعمال می‌شوند.
        self::RESERVE_TIME,
        self::RESERVE_AUTO_EXTEND_MINUTES,
        // TRANS_COMMISSION/TRANS_VAT/STATUS عمداً fillable نیستند — فقط ادمین
        // (updateFormula/approve/reject، همه با forceFill) آن‌ها را تغییر می‌دهد؛
        // mass-assignment از سمت کاربر نباید بتواند فرمول تسویه یا وضعیت احراز را دستکاری کند.
    ];

    /**
     * آیا قیمت‌های این تأمین‌کننده هم‌اکنون معتبرند؟
     *
     * `null` («هنوز اعلام نشده») و گذشته («منقضی») هر دو یعنی نه — تنها منبعِ
     * این تصمیم همین متد است تا لیستِ عمومی، افزودن به سبد و اعتبارِ قیمتِ سبد
     * نتوانند از هم واگرا شوند.
     */
    public function hasValidPriceWindow(): bool
    {
        $reserveTime = $this->{self::RESERVE_TIME};

        return $reserveTime !== null && $reserveTime->isFuture();
    }

    public function isApproved(): bool
    {
        return $this->{self::STATUS} === self::STATUS_APPROVED;
    }

    /** @var array<string, string> */
    protected $casts = [
        self::TRANS_COMMISSION => 'boolean',
        self::TRANS_VAT => 'boolean',
        self::RESERVE_TIME => 'datetime',
        self::RESERVE_AUTO_EXTEND_MINUTES => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function activities(): BelongsToMany
    {
        return $this->belongsToMany(SupplierActivityCategory::class, 'supplier_activities', 'supplier_id', 'activity_category_id');
    }
}
