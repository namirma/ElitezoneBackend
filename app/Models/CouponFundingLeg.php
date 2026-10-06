<?php

namespace App\Models;

use App\Constants\FinancialCodes;
use App\Traits\PersianDate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * «در سفارشِ X از کیف پولِ Y چقدر بابتِ کوپن کم شد» —
 * `docs/specs/COUPON_FUNDING_2026-09-15.md` §۲. یک ردیف به ازای هر لِگِ
 * `CouponFundingService::debitFunders()`؛ استرداد `reversed_at` می‌زند.
 *
 * @property int $id
 * @property int $order_id
 * @property int $coupon_id
 * @property int|null $coupon_funder_id
 * @property string $funder_type
 * @property int|null $funder_user_id
 * @property numeric $share_percent
 * @property numeric $amount
 * @property Carbon|null $reversed_at
 * @property-read Order $order
 * @property-read Coupon $coupon
 * @property-read User|null $funder
 */
class CouponFundingLeg extends Model
{
    use PersianDate;

    const TABLE = 'coupon_funding_legs';

    const ID = 'id';

    const ORDER_ID = 'order_id';

    const COUPON_ID = 'coupon_id';

    const COUPON_FUNDER_ID = 'coupon_funder_id';

    const FUNDER_TYPE = 'funder_type';

    const FUNDER_USER_ID = 'funder_user_id';

    const SHARE_PERCENT = 'share_percent';

    const AMOUNT = 'amount';

    const REVERSED_AT = 'reversed_at';

    /**
     * کیف پولی که این سهم را داد. برای سهمِ شرکت از ۱۴۰۵/۰۷/۱۴ `WCPN` («کیف پول کوپن‌های تخفیف»)؛
     * NULL = لِگِ پیش از آن (سهمِ شرکت از WSSG). استرداد و باسکول به همان کیف پول برمی‌گردند.
     */
    const WALLET_CODE = 'wallet_code';

    protected $table = self::TABLE;

    protected $fillable = [
        self::ORDER_ID,
        self::COUPON_ID,
        self::COUPON_FUNDER_ID,
        self::FUNDER_TYPE,
        self::FUNDER_USER_ID,
        self::SHARE_PERCENT,
        self::AMOUNT,
        self::REVERSED_AT,
        self::WALLET_CODE,
    ];

    /** سهمِ شرکت از کیف پولِ کوپن‌ها پرداخت شده؟ (در غیرِ این صورت لِگِ قدیمیِ WSSG) */
    public function isFromCouponWallet(): bool
    {
        return $this->{self::WALLET_CODE} === FinancialCodes::WALLET_COUPON;
    }

    protected $casts = [
        self::SHARE_PERCENT => 'decimal:2',
        self::AMOUNT => 'decimal:0',
        self::REVERSED_AT => 'datetime',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, self::ORDER_ID);
    }

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
}
