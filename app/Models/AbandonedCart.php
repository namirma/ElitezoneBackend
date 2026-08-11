<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * اسنپ‌شاتِ سبدی که مهلتش تمام شد و حذف گردید (بند ۳.۱).
 *
 * `items` عمداً denormalized است و به `product_variants` کلید خارجی ندارد —
 * این یک رویدادِ گذشته است و باید همان چیزی را نشان دهد که کاربر آن لحظه در
 * سبد داشت، حتی اگر واریانت بعداً حذف یا قیمتش عوض شود.
 */
class AbandonedCart extends Model
{
    use HasFactory;

    public const ID = 'id';

    public const USER_ID = 'user_id';

    public const SUPPLIER_USER_ID = 'supplier_user_id';

    public const ITEMS = 'items';

    public const TOTAL_AMOUNT = 'total_amount';

    public const COUPON_CODE = 'coupon_code';

    public const CART_CREATED_AT = 'cart_created_at';

    public const EXPIRED_AT = 'expired_at';

    protected $fillable = [
        self::USER_ID,
        self::SUPPLIER_USER_ID,
        self::ITEMS,
        self::TOTAL_AMOUNT,
        self::COUPON_CODE,
        self::CART_CREATED_AT,
        self::EXPIRED_AT,
    ];

    protected $casts = [
        self::ITEMS => 'array',
        self::TOTAL_AMOUNT => 'decimal:2',
        self::CART_CREATED_AT => 'datetime',
        self::EXPIRED_AT => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, self::USER_ID);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, self::SUPPLIER_USER_ID);
    }
}
