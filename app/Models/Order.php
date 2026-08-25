<?php

namespace App\Models;

use App\Models\Builders\OrderBuilder;
use App\Models\Concerns\HasOrderProgress;
use App\Traits\PersianDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Order extends Model
{
    // HasOrderActions حذف شد: تنها متدش ($order->restoreStock()) هیچ فراخوانی
    // نداشت و InventoryService::restoreStock را بدون قفل، بدون بررسی وضعیت و
    // بدون گاردِ idempotency صدا می‌زد — یعنی هر بار صدا زدن، موجودی را دوباره
    // برمی‌گرداند. مسیرِ درست CancelOrderAction است که lockForUpdate دارد و
    // سفارشِ از قبل لغو‌شده را زودهنگام رد می‌کند.
    use HasFactory,
        HasOrderProgress,
        LogsActivity,
        PersianDate;

    // =========================================================================
    //  Constants (Database Columns)
    // =========================================================================
    public const ID = 'id';

    public const PLACER_USER_ID = 'placer_user_id';

    /**
     * صاحبِ کیف پولی که پول از آن کسر می‌شود (فاز ۶).
     *
     * تا پیش از این WO همیشه `placer_user_id` بود؛ در مسیرِ «الیت‌یار برای
     * کارفرما» این یعنی پرداخت از کیف پولِ الیت‌یار. حالا پرداخت‌کننده صریح است.
     * برای سفارش‌های قدیمی (و هر سفارشِ معمولی) برابرِ همان ثبت‌کننده است —
     * `payerUserId()` را بخوانید، نه این ستون را مستقیم.
     */
    public const PAYER_USER_ID = 'payer_user_id';

    public const BENEFICIARY_USER_ID = 'beneficiary_user_id';

    /** الیت یار که کمیسیون CO را دریافت می‌کند (جدا از گیرنده/beneficiary) */
    public const ELITE_YAR_USER_ID = 'elite_yar_user_id';

    public const ADDRESS_ID = 'address_id';

    public const ORDER_NUMBER = 'order_number';

    public const STATUS = 'status';

    public const SUBTOTAL = 'subtotal';

    public const DISCOUNT_AMOUNT = 'discount_amount';

    public const COUPON_ID = 'coupon_id';

    public const COUPON_DISCOUNT_AMOUNT = 'coupon_discount_amount';

    public const SHIPPING_COST = 'shipping_cost';

    public const VAT_AMOUNT = 'vat_amount';

    public const FINAL_AMOUNT = 'final_amount';

    public const PAYMENT_METHOD = 'payment_method';

    public const PAYMENT_STATUS = 'payment_status';

    public const NOTES = 'notes';

    public const PAID_AT = 'paid_at';

    public const WEIGHBRIDGE_REGISTERED_AT = 'weighbridge_registered_at';

    public const INITIAL_INVOICE_REF = 'initial_invoice_ref';

    public const FINAL_INVOICE_REF = 'final_invoice_ref';

    public const TAX_INVOICE_REF = 'tax_invoice_ref';

    public const EXPERT_FEE_AMOUNT = 'expert_fee_amount';

    public const EXPERT_FEE_PAYMENT_STATUS = 'expert_fee_payment_status';

    public const ENGINEER_FEE_AMOUNT = 'engineer_fee_amount';

    public const ENGINEER_FEE_PAYMENT_STATUS = 'engineer_fee_payment_status';

    public const CONTRACTOR_FEE_AMOUNT = 'contractor_fee_amount';

    public const CONTRACTOR_FEE_PAYMENT_STATUS = 'contractor_fee_payment_status';

    public const EXPERT_FEE_INVOICE_REF = 'expert_fee_invoice_ref';

    public const CANCEL_REASON = 'cancel_reason';

    public const SURVEY_STATUS = 'survey_status';

    public const PREPARED_AT = 'prepared_at';

    public const SHIPPED_AT = 'shipped_at';

    public const PAYMENT_RECEIPT_REF = 'payment_receipt_ref';

    public const ESPADANA_ORDER_REF = 'espadana_order_ref';

    public const WAYBILL_REF = 'waybill_ref';

    /**
     * ⚠️ بازنشسته — جایش را `RESERVE_DEADLINE` گرفته (بند ۳.۴).
     * در فاز پاک‌سازی (۱۴) حذف می‌شود؛ مهاجرت‌ها افزایشی‌اند.
     */
    public const RESERVATION_EXPIRES_AT = 'reservation_expires_at';

    /** مهلت جاری رزرو — تمدید این را جلو می‌برد. */
    public const RESERVE_DEADLINE = 'reserve_deadline';

    /**
     * مهلت لحظه‌ی رزرو؛ هرگز تغییر نمی‌کند.
     *
     * پنجره‌ی لغو خریدار دقیقاً **بین این و `RESERVE_DEADLINE`** است: تا مهلتِ
     * اولیه نمی‌تواند لغو کند (کالا کنار گذاشته شده)، ولی بازه‌ای که
     * تأمین‌کننده اضافه کرده چیزی است که او نخواسته بود.
     */
    public const RESERVE_DEADLINE_ORIGINAL = 'reserve_deadline_original';

    /** اسنپ‌شات مهلت ارفاق در لحظه‌ی رزرو (الحاقیه‌ی ۱، تغییر ۳). */
    public const RESERVE_GRACE_HOURS = 'reserve_grace_hours';

    public const PARTIAL_PAYMENT_AMOUNT = 'partial_payment_amount';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    // =========================================================================
    //  Constants (Business Logic / Statuses)
    // =========================================================================
    public const STATUS_RESERVED = 'reserved';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_AWAITING_WEIGHT_CORRECTION = 'awaiting_weight_correction';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_PARTIALLY_DELIVERED = 'partially_delivered';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_RETURNED_TO_ORIGIN = 'returned_to_origin';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * وضعیت‌هایی که یک سفارش در آن‌ها قابل‌پرداخت است — منبعِ واحدِ حقیقت تا مسیرِ
     * پرداختِ کیف‌پول (OrderPaymentController) و مسیرِ درگاه (PayOrderAction) هرگز
     * از هم drift نکنند. RESERVED هم شامل است تا «تکمیلِ رزرو» از هر دو مسیر ممکن باشد.
     */
    public const PAYABLE_STATUSES = [
        self::STATUS_RESERVED,
    ];

    public const PAYMENT_PENDING = 'pending';

    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_FAILED = 'failed';

    public const PAYMENT_REFUND_PENDING = 'refund_pending';

    public const PAYMENT_REFUNDED = 'refunded';

    public const FEE_PAYMENT_PENDING = 'pending';

    public const FEE_PAYMENT_PAID = 'paid';

    public const SURVEY_PENDING = 'pending';

    public const SURVEY_REQUESTED = 'requested';

    public const SURVEY_COMPLETED = 'completed';

    //  Financial Constants (Elite Zone Logic)
    // =========================================================================
    public const OFFICIAL_PRICE = 'official_price';

    public const WEIGHT_BEFORE_SCALE = 'weight_before_scale';

    public const COMMISSION_RATE = 'commission_rate';

    public const OWNER_DISCOUNT_RATE = 'owner_discount_rate';

    public const SAZE_SHARE_RATE = 'saze_share_rate';

    public const ELITE_YAR_SHARE_RATE = 'elite_yar_share_rate';

    public const VAT_RATE = 'vat_rate';

    public const PAYMENT_OF_OWNER = 'payment_of_owner';

    public const PAYMENT_TO_SUPPLIER = 'payment_to_supplier';

    public const PAYMENT_TO_ELITE_YAR = 'payment_to_elite_yar';

    public const PAYMENT_TO_RESERVE = 'payment_to_reserve';

    public const VALIDATION_OF_RESERVE = 'validation_of_reserve';

    public const WEIGHT_AFTER_SCALE = 'weight_after_scale';

    public const WEIGHING_COMPLETED_AT = 'weighing_completed_at';

    /** +%TC snapshot: آیا کمیسیون روی هزینه حمل اعمال شده؟ */
    public const TRANS_COMMISSION = 'trans_commission';

    /** +VAT snapshot: آیا مالیات روی هزینه حمل اعمال شده؟ */
    public const TRANS_VAT = 'trans_vat';

    /** Doc Q: شماره فاکتور مودیان الیت یار */
    public const ELITE_YAR_MOADIAN_REF = 'elite_yar_moadian_ref';

    /** زمان آزادسازی هولد کمیسیون الیت یار */
    public const ELITE_YAR_HOLD_RELEASED_AT = 'elite_yar_hold_released_at';

    /** Doc O: شماره فاکتور مودیان سوپلایر */
    public const SUPPLIER_MOADIAN_REF = 'supplier_moadian_ref';

    /** زمان آزادسازی هولد مالیات سوپلایر */
    public const SUPPLIER_HOLD_RELEASED_AT = 'supplier_hold_released_at';

    /** آیا سوپلایر بدهی باسکول دارد؟ */
    public const SUPPLIER_DEBT_PENDING = 'supplier_debt_pending';

    /** مبلغ بدهی سوپلایر (ریال) */
    public const SUPPLIER_DEBT_AMOUNT = 'supplier_debt_amount';

    /**
     * عودتی که به خریدار بدهکاریم ولی هنوز پرداخت نشده (فاز ۸).
     *
     * فقط وقتی پر می‌شود که تأمین‌کننده در لحظه‌ی باسکول پولش را نداشته باشد.
     * شرکت آن را جلو نمی‌اندازد؛ `CheckSupplierBalancesJob` با شارژِ بعدیِ
     * تأمین‌کننده هم طلب را وصول و هم این را پرداخت می‌کند.
     */
    public const BUYER_REFUND_PENDING_AMOUNT = 'buyer_refund_pending_amount';

    /**
     * مالیاتِ تأمین‌کننده که در لحظه‌ی باسکول قابل هولد نبود (بند ۸.۴).
     *
     * جدا از `SUPPLIER_DEBT_AMOUNT` نگه داشته می‌شود چون مقصدشان فرق دارد:
     * بدهی به کیف پول سازه وصول می‌شود، ولی مالیات باید در کیف پولِ خودِ
     * تأمین‌کننده هولد شود تا با فاکتور مودیان به خودش برگردد.
     */
    public const SUPPLIER_VAT_PENDING_AMOUNT = 'supplier_vat_pending_amount';

    /**
     * جریان تأیید دومرحله‌ای اضافه‌بار (بند ۳.۷ / ۸.۲ گام ۷الف).
     *
     * صفر یعنی درخواستی باز نیست. تا وقتی هر دو مهر نخورده‌اند **هیچ لِگ مالی
     * اجرا نشده** — به همین دلیل رد یا انقضا چیزی برای برگرداندن ندارد.
     */
    public const OVERWEIGHT_PENDING_AMOUNT = 'overweight_pending_amount';

    public const OVERWEIGHT_SUPPLIER_APPROVED_AT = 'overweight_supplier_approved_at';

    public const OVERWEIGHT_BUYER_APPROVED_AT = 'overweight_buyer_approved_at';

    public const OVERWEIGHT_DEADLINE = 'overweight_deadline';

    /** Doc P: شماره فاکتور مودیان سیستم (پلتفرم) برای خریدار */
    public const SYSTEM_MOADIAN_REF = 'system_moadian_ref';

    /** زمان آزادسازی هولد مالیات پلتفرم (sazeVat) از WVAT */
    public const SYSTEM_HOLD_RELEASED_AT = 'system_hold_released_at';

    // =========================================================================
    //  Configurations
    // =========================================================================
    protected $fillable = [
        self::PLACER_USER_ID,
        self::PAYER_USER_ID,
        self::BENEFICIARY_USER_ID,
        self::ELITE_YAR_USER_ID,
        self::ADDRESS_ID,
        self::ORDER_NUMBER,
        self::STATUS,
        self::SUBTOTAL,
        self::DISCOUNT_AMOUNT,
        self::COUPON_ID,
        self::COUPON_DISCOUNT_AMOUNT,
        self::SHIPPING_COST,
        self::VAT_AMOUNT,
        self::FINAL_AMOUNT,
        self::PAYMENT_METHOD,
        self::PAYMENT_STATUS,
        self::NOTES,
        self::PAID_AT,
        self::WEIGHBRIDGE_REGISTERED_AT,
        self::INITIAL_INVOICE_REF,
        self::FINAL_INVOICE_REF,
        self::TAX_INVOICE_REF,
        self::EXPERT_FEE_AMOUNT,
        self::EXPERT_FEE_PAYMENT_STATUS,
        self::ENGINEER_FEE_AMOUNT,
        self::ENGINEER_FEE_PAYMENT_STATUS,
        self::CONTRACTOR_FEE_AMOUNT,
        self::CONTRACTOR_FEE_PAYMENT_STATUS,
        self::EXPERT_FEE_INVOICE_REF,
        self::CANCEL_REASON,
        self::SURVEY_STATUS,
        self::PREPARED_AT,
        self::SHIPPED_AT,
        self::PAYMENT_RECEIPT_REF,
        self::ESPADANA_ORDER_REF,
        self::WAYBILL_REF,
        self::RESERVATION_EXPIRES_AT,
        self::PARTIAL_PAYMENT_AMOUNT,

        // WEIGHT_BEFORE_SCALE is set at order-creation time by PlaceOrderAction::create()
        // and must remain fillable. All other financial snapshot columns below are written
        // exclusively by Financial Actions via forceFill() to prevent mass-assignment.
        self::WEIGHT_BEFORE_SCALE,
    ];

    protected $casts = [
        self::PAID_AT => 'datetime',
        self::WEIGHBRIDGE_REGISTERED_AT => 'datetime',
        self::EXPERT_FEE_AMOUNT => 'decimal:2',
        self::ENGINEER_FEE_AMOUNT => 'decimal:2',
        self::CONTRACTOR_FEE_AMOUNT => 'decimal:2',
        self::PREPARED_AT => 'datetime',
        self::SHIPPED_AT => 'datetime',
        self::RESERVATION_EXPIRES_AT => 'datetime',
        self::RESERVE_DEADLINE => 'datetime',
        self::RESERVE_DEADLINE_ORIGINAL => 'datetime',
        self::RESERVE_GRACE_HOURS => 'integer',
        self::PARTIAL_PAYMENT_AMOUNT => 'decimal:2',
        self::FINAL_AMOUNT => 'decimal:2',

        self::OFFICIAL_PRICE => 'decimal:2',
        self::WEIGHT_BEFORE_SCALE => 'decimal:3',
        self::COMMISSION_RATE => 'decimal:2',
        self::OWNER_DISCOUNT_RATE => 'decimal:2',
        self::SAZE_SHARE_RATE => 'decimal:2',
        self::ELITE_YAR_SHARE_RATE => 'decimal:2',
        self::VAT_RATE => 'decimal:2',
        self::PAYMENT_OF_OWNER => 'decimal:2',
        self::PAYMENT_TO_SUPPLIER => 'decimal:2',
        self::PAYMENT_TO_ELITE_YAR => 'decimal:2',
        self::PAYMENT_TO_RESERVE => 'decimal:2',
        self::VALIDATION_OF_RESERVE => 'datetime',
        self::WEIGHT_AFTER_SCALE => 'decimal:3',
        self::WEIGHING_COMPLETED_AT => 'datetime',
        self::TRANS_COMMISSION => 'boolean',
        self::TRANS_VAT => 'boolean',
        self::ELITE_YAR_HOLD_RELEASED_AT => 'datetime',
        self::SUPPLIER_HOLD_RELEASED_AT => 'datetime',
        self::SUPPLIER_DEBT_PENDING => 'boolean',
        self::SUPPLIER_DEBT_AMOUNT => 'decimal:2',
        self::SYSTEM_HOLD_RELEASED_AT => 'datetime',
        self::OVERWEIGHT_PENDING_AMOUNT => 'decimal:2',
        self::OVERWEIGHT_SUPPLIER_APPROVED_AT => 'datetime',
        self::OVERWEIGHT_BUYER_APPROVED_AT => 'datetime',
        self::OVERWEIGHT_DEADLINE => 'datetime',
    ];

    /**
     * هر دو طرف اضافه‌بار را پذیرفته‌اند؟
     *
     * مشتق است و ستون جدا ندارد — ستونِ سومی که باید هم‌زمان با این دو نوشته
     * شود یعنی سه‌جا برای drift کردن. `ProcessWeighbridgeResultAction` از همین
     * می‌فهمد که گاردِ تلورانس را رد کند و تعدیل را واقعاً اجرا کند.
     */
    public function isOverweightApproved(): bool
    {
        return $this->{self::OVERWEIGHT_SUPPLIER_APPROVED_AT} !== null
            && $this->{self::OVERWEIGHT_BUYER_APPROVED_AT} !== null;
    }

    /** درخواست تأیید اضافه‌بارِ بازی روی این سفارش هست؟ */
    public function hasOpenOverweightRequest(): bool
    {
        return (float) $this->{self::OVERWEIGHT_PENDING_AMOUNT} > 0.0
            && ! $this->isOverweightApproved();
    }

    // =========================================================================
    //  Resource Helpers
    // =========================================================================

    /**
     * این کاربر در این سفارش چه نقش‌هایی دارد — خریدار / تأمین‌کننده / الیت‌یار.
     *
     * تنها مرجعِ این سؤال. هم مجوزِ دانلودِ سند (`OrderInvoiceController`) و هم
     * انتخابِ سندِ دکمهٔ دانلود (`OrderResource`) از همین می‌خوانند، تا «چه کسی
     * می‌تواند ببیند» و «به چه چیزی لینک می‌دهیم» نتوانند با هم اختلاف پیدا کنند —
     * دقیقاً همان اختلافی که دکمهٔ دانلودِ سوپلایر را به یک لینکِ ۴۰۳ تبدیل کرده بود.
     *
     * یک کاربر می‌تواند هم‌زمان چند نقش داشته باشد (الیت‌یاری که برای خودش می‌خرد).
     *
     * @return list<string>
     */
    /**
     * آیا خریدار همین حالا می‌تواند این رزرو را لغو کند؟ — بند ۳.۴.
     *
     * پنجره دقیقاً بازه‌ای است که **تأمین‌کننده اضافه کرده**:
     * `(reserve_deadline_original, reserve_deadline]`.
     *
     * منطقش: تا مهلتِ اولیه کالا به درخواستِ خودِ خریدار کنار گذاشته شده و
     * تأمین‌کننده هزینه‌ی قفلِ موجودی را می‌دهد، پس خروجِ زودهنگام حقِ او نیست.
     * ولی بازه‌ای که تأمین‌کننده بعداً اضافه کرده چیزی است که خریدار نخواسته
     * بود — در آن بازه حقِ خروج دارد.
     */
    public function isInBuyerCancelWindow(): bool
    {
        if ($this->{self::STATUS} !== self::STATUS_RESERVED) {
            return false;
        }

        $original = $this->{self::RESERVE_DEADLINE_ORIGINAL};
        $current = $this->{self::RESERVE_DEADLINE};

        if ($original === null || $current === null) {
            return false;
        }

        // تمدیدی رخ نداده → پنجره‌ای وجود ندارد.
        return $current->greaterThan($original) && now()->greaterThan($original);
    }

    /**
     * مهلتِ رزرو **و** بازه‌ی ارفاقش هر دو تمام شده‌اند؟
     *
     * تنها مرجعِ این سؤال. پیش از این، منطقِ ارفاق فقط داخل `ExpireReserves`
     * بود و `CancelExpiredOrders` بدون آن روی همان ردیف‌ها کار می‌کرد — پس
     * رزرو دقیقاً سرِ `reserve_deadline` لغو می‌شد و **ارفاق هرگز اعمال
     * نمی‌شد**. با یک‌جا شدنِ قاعده، هر لغوکننده‌ای همان جواب را می‌گیرد.
     *
     * مهلتِ خالی یعنی «رزروی در کار نیست» → منقضی حساب نمی‌شود.
     */
    public function isReserveWindowExpired(): bool
    {
        $deadline = $this->{self::RESERVE_DEADLINE};

        if ($deadline === null) {
            return false;
        }

        $grace = max(0, (int) ($this->{self::RESERVE_GRACE_HOURS} ?? 0));

        return $deadline->copy()->addHours($grace)->isPast();
    }

    /**
     * کیف پولی که پولِ این سفارش از آن می‌آید و استرداد به آن برمی‌گردد.
     *
     * `??` و نه `?:` — و برای سفارش‌های پیش از فاز ۶ که ستون خالی است، به
     * ثبت‌کننده برمی‌گردد. **هر جا WO لازم دارید این را صدا بزنید**، نه
     * `placer_user_id` را: هر دو برای سفارش‌های معمولی یکی هستند، ولی در مسیرِ
     * «الیت‌یار برای کارفرما» متفاوت‌اند و تفاوتشان یعنی پول از کیف پولِ چه
     * کسی برداشته می‌شود.
     */
    public function payerUserId(): int
    {
        return (int) ($this->getAttribute(self::PAYER_USER_ID) ?? $this->{self::PLACER_USER_ID});
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, self::PAYER_USER_ID);
    }

    /**
     * Relations required by OrderResource::toArray().
     *
     * Use this whenever constructing an OrderResource or OrderResource::collection()
     * so the Resource never triggers lazy-loads itself:
     *
     *   $order->fresh(Order::resourceRelations())
     *   $order->load(Order::resourceRelations())
     *   ->with(Order::resourceRelations())
     */
    public static function resourceRelations(): array
    {
        return [
            'items.productVariant',
            'placer.profile',
            'placer.type',
            'beneficiary.profile',
            'eliteYAR.profile',
            'eliteYAR.badge',
            'address.city',
            'address.province',
            'shipments.originFactory',
            'shipments.originFactoryLocation',
            'shipments.destinationAddress.city',
            'shipments.destinationAddress.province',
            // بدون این، OrderResource::coupon_code همیشه silently غایب بود چون
            // whenLoaded('coupon') هرگز true نمی‌شد (نه فقط در چک‌اوت — همه کنترلرهای
            // سفارش که این متد را صدا می‌زنند).
            'coupon',
        ];
    }

    // =========================================================================
    //  Relationships
    // =========================================================================
    public function placer(): BelongsTo
    {
        return $this->belongsTo(User::class, self::PLACER_USER_ID);
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, self::BENEFICIARY_USER_ID);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, self::COUPON_ID);
    }

    /** الیت یار که کمیسیون CO را دریافت می‌کند */
    public function eliteYAR(): BelongsTo
    {
        return $this->belongsTo(User::class, self::ELITE_YAR_USER_ID);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class, self::ADDRESS_ID);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, OrderItem::ORDER_ID);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class, Shipment::ORDER_ID);
    }

    public function surveys(): HasMany
    {
        return $this->hasMany(OrderSurvey::class, OrderSurvey::ORDER_ID);
    }

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class, 'shipping_method_id');
    }

    // =========================================================================
    //  Activity Log
    // =========================================================================
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'payment_status', 'final_amount'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(function (string $eventName) {
                $action = match ($eventName) {
                    'created' => 'ثبت',
                    'updated' => 'ویرایش',
                    'deleted' => 'حذف',
                    default => $eventName,
                };

                return "سفارش شماره {$this->order_number} {$action} شد.";
            });
    }

    public function newEloquentBuilder($query): OrderBuilder
    {
        return new OrderBuilder($query);
    }
}
