<?php

namespace App\Models;

use App\Traits\PersianDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $code
 * @property string $type
 * @property numeric $amount
 * @property int|null $user_id
 * @property Carbon|null $expires_at
 * @property Carbon|null $used_at
 * @property string|null $reason
 * @property int|null $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $owner
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereUsedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereUserId($value)
 *
 * @mixin \Eloquent
 */
class Coupon extends Model
{
    use HasFactory;
    use PersianDate;

    /* -------------------------------------------------------------------------- */
    /* Constants (Column Names) */
    /* -------------------------------------------------------------------------- */
    const TABLE = 'coupons';

    const ID = 'id';

    const CODE = 'code';

    const TYPE = 'type';

    const AMOUNT = 'amount';

    const USER_ID = 'user_id';

    const EXPIRES_AT = 'expires_at';

    const USED_AT = 'used_at';

    /**
     * بودجه‌ی کلِ کمپین (ریال) — پاسخِ مدیریت به پرسشِ ۱۰ (۱۴۰۵/۰۷/۱۳، گزینه‌ی ب): «بک‌آفیس
     * برای هر کمپینِ کوپن یک بودجه‌ی کل تعیین می‌کند؛ تا بودجه تمام نشده، کوپن روی هر
     * سفارش قابلِ استفاده است.» NULL = کوپنِ معمولیِ **تک‌مصرفه** (used_at). بودجه فقط
     * سهمِ **شرکت** از تخفیف را می‌شمارد؛ سهمِ تأمین‌کننده/الیت‌یار از کیف پولِ خودشان است.
     */
    const BUDGET_TOTAL = 'budget_total';

    /** سهمِ شرکت از تخفیف‌های مصرف‌شده‌ی این کمپین تا امروز (ریال). */
    const BUDGET_USED = 'budget_used';

    /**
     * چندبارمصرف (کدِ مشترکِ کمپین). مدیریت، دورِ ششم، پرسشِ ۴: بودجه‌ی کمپین حذف شد و تنها سقف کیف پولِ
     * کوپن‌هاست؛ تا امروز «چندبارمصرف» فقط از وجودِ بودجه خوانده می‌شد.
     */
    const IS_MULTI_USE = 'is_multi_use';

    const REASON = 'reason';

    const IS_ACTIVE = 'is_active';

    const CREATED_BY_USER_ID = 'created_by_user_id';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    /* -------------------------------------------------------------------------- */
    /* Constants (Values) */
    /* -------------------------------------------------------------------------- */
    const TYPE_PERCENT = 'percent';

    const TYPE_FIXED = 'fixed';

    protected $table = self::TABLE;

    protected $fillable = [
        self::CODE,
        self::TYPE,
        self::AMOUNT,
        self::USER_ID,
        self::EXPIRES_AT,
        self::USED_AT,
        self::REASON,
        self::IS_ACTIVE,
        self::CREATED_BY_USER_ID,
        self::BUDGET_TOTAL,
        self::IS_MULTI_USE,
        // BUDGET_USED عمداً fillable نیست — فقط مسیرِ مصرف/آزادسازی می‌نویسدش.
    ];

    protected $casts = [
        self::AMOUNT => 'decimal:2',
        self::IS_ACTIVE => 'boolean',
        self::EXPIRES_AT => 'datetime',
        self::USED_AT => 'datetime',
        self::BUDGET_TOTAL => 'decimal:0',
        self::BUDGET_USED => 'decimal:0',
        self::IS_MULTI_USE => 'boolean',
    ];

    /**
     * کوپنِ کمپینی = چندمصرفه (هرگز `used_at` نمی‌گیرد). از دورِ ششم پرچمِ صریحِ `is_multi_use`؛ کوپنِ
     * بودجه‌دار هم چندمصرفه می‌ماند (ردیف‌های قدیمی و پنلِ فعلی که «بودجه» می‌فرستد).
     */
    public function isCampaign(): bool
    {
        return (bool) $this->{self::IS_MULTI_USE} || $this->{self::BUDGET_TOTAL} !== null;
    }

    /** سقفِ بودجه‌ی کمپین اعمال می‌شود؟ مدیریت، دورِ ششم، پرسشِ ۴: نه — تنها سقف کیف پولِ کوپن‌هاست. */
    public function hasBudgetCap(): bool
    {
        return (bool) config('features.campaign_budget') && $this->{self::BUDGET_TOTAL} !== null;
    }

    /** باقی‌مانده‌ی بودجه‌ی کمپین (ریال)؛ برای کوپنِ غیرکمپینی null. */
    public function budgetRemaining(): ?float
    {
        if (! $this->isCampaign()) {
            return null;
        }

        return max(0.0, (float) $this->{self::BUDGET_TOTAL} - (float) ($this->{self::BUDGET_USED} ?? 0));
    }

    /* -------------------------------------------------------------------------- */
    /* Relations */
    /* -------------------------------------------------------------------------- */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, self::USER_ID);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, self::CREATED_BY_USER_ID);
    }

    /**
     * ترتیبِ صریح لازم است: `CouponFundingService::split()` باقی‌مانده‌ی ریالی را به
     * ردیفِ **آخر** می‌دهد. بدونِ `orderBy`، MySQL 8 ردیف‌ها را از ایندکسِ یکتای
     * `(coupon_id, funder_type, funder_user_id)` می‌خواند (company پیش از supplier) و
     * MariaDB به ترتیبِ کلید — پس «چه کسی یک ریال بیشتر می‌دهد» به موتورِ دیتابیس
     * بستگی داشت و پیش‌نمایش و پرداخت روی دو سرور می‌توانستند متفاوت باشند.
     *
     * @return HasMany<CouponFunder, $this>
     */
    public function funders(): HasMany
    {
        return $this->hasMany(CouponFunder::class, CouponFunder::COUPON_ID)->orderBy(CouponFunder::ID);
    }

    /** @return HasMany<CouponFundingLeg, $this> */
    public function fundingLegs(): HasMany
    {
        return $this->hasMany(CouponFundingLeg::class, CouponFundingLeg::COUPON_ID);
    }

    /* -------------------------------------------------------------------------- */
    /* تأمینِ تخفیف — docs/specs/COUPON_FUNDING_2026-09-15.md */
    /* -------------------------------------------------------------------------- */

    /**
     * ردیف‌های تأمین، یا — برای کوپنی که هیچ ردیفی ندارد (fixtureِ تست، ردیفِ
     * ساخته‌شده بیرونِ مسیرهای رسمی) — یک ردیفِ مجازیِ «شرکت ۱۰۰٪ پذیرفته».
     * همان معنایی که مهاجرت برای کوپن‌های قدیمی نوشت، بدونِ نیاز به ردیف.
     *
     * @return Collection<int, CouponFunder>
     */
    public function fundingRows(): Collection
    {
        $rows = $this->relationLoaded('funders') ? $this->funders : $this->funders()->get();

        if ($rows->isNotEmpty()) {
            return $rows->values();
        }

        return collect([new CouponFunder([
            CouponFunder::COUPON_ID => $this->id,
            CouponFunder::FUNDER_TYPE => CouponFunder::TYPE_COMPANY,
            CouponFunder::FUNDER_USER_ID => null,
            CouponFunder::SHARE_PERCENT => 100,
            CouponFunder::STATUS => CouponFunder::STATUS_ACCEPTED,
        ])]);
    }

    /** سهمی از این کوپن به تأمین‌کننده یا الیت‌یار سپرده شده؟ (دورِ ششم: فقط شرکت کوپن صادر می‌کند) */
    public function hasPartnerFunding(): bool
    {
        return $this->fundingRows()->contains(fn (CouponFunder $f) => ! $f->isCompany());
    }

    /** کوپن فقط وقتی قابلِ استفاده است که **همه‌ی** تأمین‌کننده‌ها پذیرفته باشند. */
    public function isFundingReady(): bool
    {
        return $this->fundingRows()->every(fn (CouponFunder $f) => $f->isAccepted());
    }

    /** `ready` | `pending` | `declined` — برای پنل‌ها. */
    public function fundingStatus(): string
    {
        $rows = $this->fundingRows();

        if ($rows->contains(fn (CouponFunder $f) => $f->{CouponFunder::STATUS} === CouponFunder::STATUS_DECLINED)) {
            return CouponFunder::STATUS_DECLINED;
        }

        return $rows->every(fn (CouponFunder $f) => $f->isAccepted())
            ? 'ready'
            : CouponFunder::STATUS_PENDING;
    }

    /* -------------------------------------------------------------------------- */
    /* Helper Methods */
    /* -------------------------------------------------------------------------- */

    /**
     * بررسی اعتبار کوپن
     */
    public function isValid(): bool
    {
        // ۰. بررسی فعال بودن (غیرفعال‌شده توسط ادمین)
        if (! $this->{self::IS_ACTIVE}) {
            return false;
        }

        // ۱. بررسی استفاده شدن — کوپنِ کمپینی تا پایانِ بودجه چندبار مصرف می‌شود (پرسشِ ۱۰).
        if ($this->isCampaign()) {
            if ($this->budgetRemaining() <= 0.0) {
                return false;
            }
        } elseif (! is_null($this->{self::USED_AT})) {
            return false;
        }

        // ۲. بررسی تاریخ انقضا
        if ($this->{self::EXPIRES_AT} && $this->{self::EXPIRES_AT}->isPast()) {
            return false;
        }

        return true;
    }
}
