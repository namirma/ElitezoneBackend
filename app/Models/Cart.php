<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string|null $session_id
 * @property int|null $customer_type_id
 * @property int|null $badge_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Badge|null $badge
 * @property-read CustomerType|null $customerType
 * @property-read Collection<int, CartItem> $items
 * @property-read int|null $items_count
 * @property-read CartPriceFilterCategory|null $pivot
 * @property-read Collection<int, PriceFilterCategory> $priceFilterCategories
 * @property-read int|null $price_filter_categories_count
 * @property-read Collection<int, PurchaseParameterValue> $purchaseParameters
 * @property-read int|null $purchase_parameters_count
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart whereBadgeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart whereCustomerTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart whereSessionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart whereUserId($value)
 *
 * @mixin \Eloquent
 */
class Cart extends Model
{
    use HasFactory;

    const ID = 'id';

    const USER_ID = 'user_id';

    const SESSION_ID = 'session_id';

    const CUSTOMER_TYPE_ID = 'customer_type_id';

    const BADGE_ID = 'badge_id';

    /** پایان مهلت نگهداری سبد (Park). null یعنی سبد خالی یا مهمان. */
    const PARKED_UNTIL = 'parked_until';

    /** زمان ارسال پیامک هشدار — گاردِ ارسالِ دوباره. */
    const PARK_WARNED_AT = 'park_warned_at';

    /** تأمین‌کننده‌ی قفل‌شده‌ی سبد (بند ۳.۱ — هر سبد فقط یک تأمین‌کننده). */
    const SUPPLIER_USER_ID = 'supplier_user_id';

    /** مقصد واحدِ سبد (بند ۳.۱ — هر سفارش یک مقصد). */
    /**
     * کارفرمایی که این سبد برای اوست و پولش را او می‌دهد (فاز ۶).
     *
     * `null` یعنی سبدِ معمولی — خودِ صاحبِ سبد می‌پردازد.
     */
    const BENEFICIARY_USER_ID = 'beneficiary_user_id';

    /** آخرین باری که لینک پرداخت پیامک شد — فقط برای نمایش و محدودکردنِ ارسالِ دوباره. */
    const PAYMENT_LINK_SENT_AT = 'payment_link_sent_at';

    /**
     * لحظه‌ی لغوِ لینک توسط الیت‌یار. فقط برای صفحه‌ی کارفرما معنا دارد («لغو
     * شد» در برابرِ «لینکی نبود»)؛ صدورِ لینکِ بعدی پاکش می‌کند.
     */
    const PAYMENT_LINK_CANCELLED_AT = 'payment_link_cancelled_at';

    const ADDRESS_ID = 'address_id';

    /** فقط کد؛ مبلغ در لحظه‌ی رزرو/پرداخت محاسبه می‌شود (بند ۳.۱۳). */
    const COUPON_CODE = 'coupon_code';

    const SHIPPING_METHOD_ID = 'shipping_method_id';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::USER_ID,
        self::SESSION_ID,
        self::CUSTOMER_TYPE_ID,
        self::BADGE_ID,
        self::ADDRESS_ID,
        self::COUPON_CODE,
        self::SHIPPING_METHOD_ID,
    ];

    /**
     * `parked_until`، `park_warned_at` و `supplier_user_id` عمداً در `$fillable`
     * نیستند: هر سه را فقط `CartParkService` می‌نویسد. مهلتِ سبد چیزی نیست که
     * یک درخواستِ کاربر بتواند mass-assign کند — وگرنه یک فیلدِ اضافه در بدنه‌ی
     * «افزودن به سبد» مهلت را برای همیشه جلو می‌برد.
     */
    protected $casts = [
        self::PARKED_UNTIL => 'datetime',
        self::PARK_WARNED_AT => 'datetime',
        self::PAYMENT_LINK_SENT_AT => 'datetime',
        self::PAYMENT_LINK_CANCELLED_AT => 'datetime',
    ];

    /**
     * کارفرمای مقصدِ لینک پرداخت. مثل `supplier_user_id` عمداً fillable نیست —
     * فقط `CartPaymentLinkService` آن را می‌نویسد، وگرنه یک فیلدِ اضافه در بدنه‌ی
     * «افزودن به سبد» می‌توانست پرداخت را به کیف پولِ شخصِ دیگری بیندازد.
     *
     * @return BelongsTo<User, $this>
     */
    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, self::BENEFICIARY_USER_ID);
    }

    /** آیا این سبد برای کارفرمای دیگری بسته شده و پرداختش با اوست؟ */
    public function isForBeneficiary(): bool
    {
        return $this->{self::BENEFICIARY_USER_ID} !== null;
    }

    /**
     * تنها راهِ انتخابِ سبدِ یک کاربر — قدیمی‌ترین ردیف (کوچک‌ترین id).
     *
     * `carts.user_id` از مایگریشن 2026_07_25_110000 یکتاست، پس عملاً همیشه یک
     * ردیف بیشتر نیست؛ ترتیبِ صریح برای این است که هر مسیرِ خواننده (نمایش سبد،
     * ثبت سفارش) قطعاً *همان* ردیف را ببیند و واگراییِ قبلی
     * (firstOrCreate در برابر latest('updated_at')) دوباره برنگردد.
     *
     * @return Builder<static>
     */
    public static function queryForUser(int $userId)
    {
        return static::query()
            ->where(self::USER_ID, $userId)
            ->orderBy(self::ID);
    }

    /**
     * سبدِ کاربر را برمی‌گرداند و در نبودش می‌سازد.
     *
     * ایندکسِ یکتای user_id باعث می‌شود دو درخواستِ همزمان به‌جای ساختِ دو سبد،
     * یکی‌شان UniqueConstraintViolation بگیرد؛ در آن حالت سبدی که رقیب ساخته
     * خوانده می‌شود.
     */
    public static function resolveForUser(int $userId): self
    {
        $cart = self::queryForUser($userId)->first();

        if ($cart) {
            return $cart;
        }

        try {
            return self::create([self::USER_ID => $userId]);
        } catch (UniqueConstraintViolationException) {
            return self::queryForUser($userId)->firstOrFail();
        }
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function customerType(): BelongsTo
    {
        return $this->belongsTo(CustomerType::class);
    }

    public function badge(): BelongsTo
    {
        return $this->belongsTo(Badge::class);
    }

    public function priceFilterCategories(): BelongsToMany
    {
        return $this->belongsToMany(
            PriceFilterCategory::class,
            'cart_price_filter_categories',
            'cart_id',
            'price_filter_category_id'
        )
            ->using(CartPriceFilterCategory::class)
            // فقط ستون‌های واقعی pivot
            ->withPivot(['cart_id', 'price_filter_category_id'])
            ->withTimestamps();
    }

    public function purchaseParameters(): BelongsToMany
    {
        return $this->belongsToMany(
            PurchaseParameterValue::class,
            'cart_purchase_parameter_value',
            'cart_id',
            'purchase_parameter_value_id'
        )->withTimestamps();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, self::SUPPLIER_USER_ID);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class, self::ADDRESS_ID);
    }

    /** ثانیه‌های باقی‌مانده تا انقضا؛ null یعنی مهلتی تنظیم نشده. */
    public function parkRemainingSeconds(): ?int
    {
        if ($this->{self::PARKED_UNTIL} === null) {
            return null;
        }

        return max(0, now()->diffInSeconds($this->{self::PARKED_UNTIL}, false));
    }
}
