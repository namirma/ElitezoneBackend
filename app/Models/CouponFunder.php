<?php

namespace App\Models;

use App\Traits\PersianDate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * «چه کسی چند درصدِ تخفیفِ این کوپن را می‌دهد و آیا پذیرفته» —
 * `docs/specs/COUPON_FUNDING_2026-09-15.md` §۲.
 *
 * @property int $id
 * @property int $coupon_id
 * @property string $funder_type
 * @property int|null $funder_user_id
 * @property numeric $share_percent
 * @property string $status
 * @property Carbon|null $responded_at
 * @property-read Coupon $coupon
 * @property-read User|null $funder
 */
class CouponFunder extends Model
{
    use PersianDate;

    const TABLE = 'coupon_funders';

    const ID = 'id';

    const COUPON_ID = 'coupon_id';

    const FUNDER_TYPE = 'funder_type';

    const FUNDER_USER_ID = 'funder_user_id';

    const SHARE_PERCENT = 'share_percent';

    const STATUS = 'status';

    const RESPONDED_AT = 'responded_at';

    const TYPE_SUPPLIER = 'supplier';

    const TYPE_COMPANY = 'company';

    const TYPE_ELITE_YAR = 'elite_yar';

    const TYPES = [self::TYPE_SUPPLIER, self::TYPE_COMPANY, self::TYPE_ELITE_YAR];

    const STATUS_PENDING = 'pending';

    const STATUS_ACCEPTED = 'accepted';

    const STATUS_DECLINED = 'declined';

    const STATUSES = [self::STATUS_PENDING, self::STATUS_ACCEPTED, self::STATUS_DECLINED];

    protected $table = self::TABLE;

    protected $fillable = [
        self::COUPON_ID,
        self::FUNDER_TYPE,
        self::FUNDER_USER_ID,
        self::SHARE_PERCENT,
        self::STATUS,
        self::RESPONDED_AT,
    ];

    protected $casts = [
        self::SHARE_PERCENT => 'decimal:2',
        self::RESPONDED_AT => 'datetime',
    ];

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, self::COUPON_ID);
    }

    /** @return BelongsTo<User, $this> */
    public function funder(): BelongsTo
    {
        return $this->belongsTo(User::class, self::FUNDER_USER_ID);
    }

    public function isCompany(): bool
    {
        return $this->{self::FUNDER_TYPE} === self::TYPE_COMPANY;
    }

    public function isAccepted(): bool
    {
        return $this->{self::STATUS} === self::STATUS_ACCEPTED;
    }

    public function isPending(): bool
    {
        return $this->{self::STATUS} === self::STATUS_PENDING;
    }

    /** برچسبِ فارسیِ نوع — برای پیام‌ها و پیامک‌ها. */
    public static function typeLabel(string $type): string
    {
        return __('coupons.funder_types.'.$type);
    }

    /** نامِ قابلِ نمایشِ تأمین‌کننده: نامِ کاربر، یا «شرکت سازه». */
    public function displayName(): string
    {
        if ($this->isCompany()) {
            return self::typeLabel(self::TYPE_COMPANY);
        }

        // `full_name` روی کاربرِ بی‌پروفایل «کاربر ناشناس» می‌دهد؛ نوع + شماره
        // موبایل برای پیامِ خطا گویاتر است (قاعده‌ی صداقت: نامِ جعلی نه).
        $user = $this->funder;
        $name = $user && $user->profile ? trim((string) $user->full_name) : '';

        if ($name === '' || $name === 'کاربر ناشناس') {
            $name = self::typeLabel((string) $this->{self::FUNDER_TYPE}).($user ? " ({$user->mobile})" : '');
        }

        return $name;
    }
}
