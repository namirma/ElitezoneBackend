<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * یک ردیفِ دفترِ بدهی — append-only، دقیقاً مثل `WalletTransaction`.
 *
 * هیچ ردیفی پس از ثبت ویرایش نمی‌شود. بازپرداخت یک ردیفِ **تازه** با مبلغ منفی
 * است، نه تغییرِ ردیفِ قبلی؛ همان قاعده‌ای که در `releaseHold` هم رعایت شده و
 * دلیلش این است که مانده همیشه از جمعِ دفتر قابل بازسازی بماند.
 */
class DebtEntry extends Model
{
    use HasFactory;

    public const ID = 'id';

    public const USER_ID = 'user_id';

    public const ORDER_ID = 'order_id';

    public const SHIPMENT_ID = 'shipment_id';

    public const TYPE = 'type';

    public const AMOUNT = 'amount';

    public const DESCRIPTION = 'description';

    public const CREATED_BY = 'created_by';

    public const META = 'meta';

    /** اضافه‌بار باسکول — خریدار بیش از سفارش تحویل گرفته است. */
    public const TYPE_OVERWEIGHT = 'overweight';

    /** خسارت عودت به مبدأ (بند ۱۱). */
    public const TYPE_RETURN_DAMAGE = 'return_damage';

    /** کسری باسکول که تأمین‌کننده موجودی‌اش را نداشت (بند ۸.۴). */
    public const TYPE_WEIGHBRIDGE_DEFICIT = 'weighbridge_deficit';

    /** بازپرداخت — همیشه مبلغ منفی. */
    public const TYPE_REPAYMENT = 'repayment';

    /** اصلاح دستی بک‌آفیس. */
    public const TYPE_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        self::USER_ID,
        self::ORDER_ID,
        self::SHIPMENT_ID,
        self::TYPE,
        self::AMOUNT,
        self::DESCRIPTION,
        self::CREATED_BY,
        self::META,
    ];

    protected $casts = [
        self::AMOUNT => 'decimal:0',
        self::META => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, self::USER_ID);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, self::ORDER_ID);
    }
}
