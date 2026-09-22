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

    // ── درخواستِ احرازِ صلاحیت (§۴-۱ `SUPPLIER_CONSOLIDATION_2026-09-19.md`) ──
    // شخصِ حقوقی/کارخانه‌ی ایرانی. همه fillable‌اند چون خودِ متقاضی می‌نویسد؛
    // اعتبارسنجی در `StoreSupplierProfileRequest`.
    const ENTITY_TYPE = 'entity_type';

    const ENTITY_COMPANY = 'company';

    const ENTITY_INDIVIDUAL = 'individual';

    const NATIONAL_ID = 'national_id';

    const REGISTRATION_NUMBER = 'registration_number';

    const ECONOMIC_CODE = 'economic_code';

    const REPRESENTATIVE_NAME = 'representative_name';

    const REPRESENTATIVE_NATIONAL_CODE = 'representative_national_code';

    const LANDLINE = 'landline';

    const PROVINCE_ID = 'province_id';

    const CITY_ID = 'city_id';

    const FACTORY_ADDRESS = 'factory_address';

    const POSTAL_CODE = 'postal_code';

    const IBAN = 'iban';

    /** آخرین باری که متقاضی درخواستش را ارسال کرد (draft/needs_correction/rejected → pending). */
    const SUBMITTED_AT = 'submitted_at';

    /** آخرین تصمیمِ ادمین (approve/reject/request-correction). */
    const REVIEWED_AT = 'reviewed_at';

    /** +%TC: کمیسیون روی هزینه حمل اعمال می‌شود؟ (پیش‌فرض: بله) */
    const TRANS_COMMISSION = 'trans_commission';

    /** +VAT: مالیات بر ارزش افزوده روی هزینه حمل اعمال می‌شود؟ (پیش‌فرض: بله) */
    const TRANS_VAT = 'trans_vat';

    /** وضعیت احراز سوپلایر — نقش supplier فقط پس از تأیید ادمین متصل می‌شود. */
    const STATUS = 'status';

    /** انتخابِ نقش انجام شده ولی درخواست هنوز ارسال نشده — در صفِ ادمین نیست. */
    const STATUS_DRAFT = 'draft';

    const STATUS_PENDING = 'pending';

    /** ادمین اصلاح خواسته؛ دلیل در `rejection_reason`. ویرایش و ارسالِ دوباره → pending. */
    const STATUS_NEEDS_CORRECTION = 'needs_correction';

    const STATUS_APPROVED = 'approved';

    const STATUS_REJECTED = 'rejected';

    /**
     * پایان اعتبار قیمت‌های این تأمین‌کننده — datetime مطلق (بند ۲.۲ / ۳.۲).
     *
     * تا این لحظه قیمت‌ها تغییر نمی‌کنند. با گذشتنش و نبودِ قیمت جدید،
     * محصولات از فروش خارج می‌شوند. `null` هم یعنی «هنوز اعلام نشده» و
     * همان اثر را دارد.
     */
    /** دلیلِ ردِ ادمین. عمداً fillable نیست — فقط از مسیرِ ادمین نوشته می‌شود. */
    const REJECTION_REASON = 'rejection_reason';

    const RESERVE_TIME = 'reserve_time';

    /** تمدید خودکار برای رزروِ دیرهنگام، به دقیقه (بند ۲.۲). */
    const RESERVE_AUTO_EXTEND_MINUTES = 'reserve_auto_extend_minutes';

    /**
     * override تأمین‌کننده روی مهلتِ ارفاق (الحاقیه‌ی ۱، تغییر ۳).
     *
     * `null` یعنی «پیروی از تنظیم بک‌آفیس» — و صفر یک انتخابِ معتبرِ دیگر است
     * («هیچ ارفاقی نده»)، پس این دو نباید یکی شمرده شوند.
     */
    const RESERVE_GRACE_HOURS = 'reserve_grace_hours';

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
        self::ENTITY_TYPE,
        self::NATIONAL_ID,
        self::REGISTRATION_NUMBER,
        self::ECONOMIC_CODE,
        self::REPRESENTATIVE_NAME,
        self::REPRESENTATIVE_NATIONAL_CODE,
        self::LANDLINE,
        self::PROVINCE_ID,
        self::CITY_ID,
        self::FACTORY_ADDRESS,
        self::POSTAL_CODE,
        self::IBAN,
        // این دو **برخلافِ** ردیف زیر عمداً fillable هستند: خودِ تأمین‌کننده
        // تنظیمشان می‌کند (بند ۴.۳)، نه ادمین. گاردهای «باید در آینده باشد» و
        // «کاهش ممنوع» در `UpdateSupplierReserveTimeRequest` اعمال می‌شوند.
        self::RESERVE_TIME,
        self::RESERVE_AUTO_EXTEND_MINUTES,
        self::RESERVE_GRACE_HOURS,
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

    /**
     * آیا ذخیره‌ی فرم یعنی «ارسالِ درخواست»؟ فقط از این سه وضعیت به صف می‌رود؛
     * پروفایلِ approved با ویرایش تأییدش را از دست نمی‌دهد و pending از قبل در صف است.
     */
    public function isSubmittable(): bool
    {
        return in_array($this->{self::STATUS}, [
            self::STATUS_DRAFT,
            self::STATUS_REJECTED,
            self::STATUS_NEEDS_CORRECTION,
        ], true);
    }

    public function isCompany(): bool
    {
        return $this->{self::ENTITY_TYPE} !== self::ENTITY_INDIVIDUAL;
    }

    /**
     * فیلدهایی که بعد از تأیید بی‌سروصدا عوض نمی‌شوند (قانون دو، علی ۱۴۰۵/۰۶/۳۱):
     * هویتِ حقوقی، نماینده، شبا و نشانیِ کارخانه. تا آن تاریخ `POST profile/supplier`
     * روی پروفایلِ approved این‌ها را مستقیم می‌نوشت — زنده سنجیده شد: شناسه‌ی ملی
     * عوض شد و تأیید ماند. مسیرِ تغییرِ مشروع: ادمین با «نیاز به اصلاح» فرم را باز
     * می‌کند (نقش می‌ماند)، تأمین‌کننده اصلاح و ارسال می‌کند، ادمین دوباره تأیید می‌کند.
     *
     * @var list<string>
     */
    public const IDENTITY_FIELDS = [
        self::COMPANY,
        self::ENTITY_TYPE,
        self::NATIONAL_ID,
        self::REGISTRATION_NUMBER,
        self::ECONOMIC_CODE,
        self::REPRESENTATIVE_NAME,
        self::REPRESENTATIVE_NATIONAL_CODE,
        self::PROVINCE_ID,
        self::CITY_ID,
        self::FACTORY_ADDRESS,
        self::POSTAL_CODE,
        self::IBAN,
    ];

    /**
     * کدام فیلدهای هویتی در این ورودی با مقدارِ ذخیره‌شده فرق دارند.
     * فقط کلیدهایی که واقعاً فرستاده شده‌اند سنجیده می‌شوند؛ null و رشته‌ی خالی یکی‌اند.
     *
     * @param  array<string, mixed>  $incoming
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function identityChanges(array $incoming): array
    {
        $changed = [];
        foreach (self::IDENTITY_FIELDS as $field) {
            if (! array_key_exists($field, $incoming)) {
                continue;
            }
            $new = $incoming[$field] === null ? '' : trim((string) $incoming[$field]);
            $old = $this->{$field} === null ? '' : trim((string) $this->{$field});
            if ($new !== $old) {
                $changed[$field] = ['from' => $this->{$field}, 'to' => $incoming[$field]];
            }
        }

        return $changed;
    }

    /** @var array<string, string> */
    protected $casts = [
        self::TRANS_COMMISSION => 'boolean',
        self::TRANS_VAT => 'boolean',
        self::RESERVE_TIME => 'datetime',
        self::SUBMITTED_AT => 'datetime',
        self::REVIEWED_AT => 'datetime',
        self::RESERVE_AUTO_EXTEND_MINUTES => 'integer',
        self::RESERVE_GRACE_HOURS => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function documents()
    {
        return $this->hasMany(SupplierDocument::class, SupplierDocument::SUPPLIER_PROFILE_ID);
    }

    public function activities(): BelongsToMany
    {
        return $this->belongsToMany(SupplierActivityCategory::class, 'supplier_activities', 'supplier_id', 'activity_category_id');
    }
}
