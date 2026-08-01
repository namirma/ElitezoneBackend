<?php

namespace App\Models;

use App\Models\Builders\OrderBuilder;
use App\Models\Concerns\HasOrderProgress;
use App\Support\OrderDocumentCatalog;
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

    public const RESERVATION_EXPIRES_AT = 'reservation_expires_at';

    public const PARTIAL_PAYMENT_AMOUNT = 'partial_payment_amount';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    // =========================================================================
    //  Constants (Business Logic / Statuses)
    // =========================================================================
    public const STATUS_PENDING = 'pending';

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_RESERVED_EXTENDED = 'reserved_extended';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PREPARING = 'preparing';

    public const STATUS_WEIGHBRIDGE_DONE = 'weighbridge_done';

    public const STATUS_WAYBILL_ISSUED = 'waybill_issued';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PENDING_SURPLUS_PAYMENT = 'pending_surplus_payment';

    /**
     * وضعیت‌هایی که یک سفارش در آن‌ها قابل‌پرداخت است — منبعِ واحدِ حقیقت تا مسیرِ
     * پرداختِ کیف‌پول (OrderPaymentController) و مسیرِ درگاه (PayOrderAction) هرگز
     * از هم drift نکنند. RESERVED هم شامل است تا «تکمیلِ رزرو» از هر دو مسیر ممکن باشد.
     */
    public const PAYABLE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RESERVED,
        self::STATUS_RESERVED_EXTENDED,
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

    /** Doc P: شماره فاکتور مودیان سیستم (پلتفرم) برای خریدار */
    public const SYSTEM_MOADIAN_REF = 'system_moadian_ref';

    /** زمان آزادسازی هولد مالیات پلتفرم (sazeVat) از WVAT */
    public const SYSTEM_HOLD_RELEASED_AT = 'system_hold_released_at';

    // =========================================================================
    //  Configurations
    // =========================================================================
    protected $fillable = [
        self::PLACER_USER_ID,
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
    ];

    // =========================================================================
    //  Resource Helpers
    // =========================================================================

    /**
     * مبلغی که تصمیمِ «رزرو یا پرداخت یکجا» بر اساس آن گرفته می‌شود — یعنی
     * اندازهٔ واقعیِ سفارش، *پیش از* تخفیف کوپن.
     *
     * آستانهٔ رزرو (`financial.reservation_threshold`) یک سقفِ اندازهٔ سفارش است
     * (هم‌تراز با سقفِ هر تراکنشِ درگاه پاسارگاد در سند)، نه سقفِ مبلغِ قابل
     * پرداخت. مقایسه با `final_amount` — که PlaceOrderAction تخفیف کوپن را از آن
     * کم می‌کند — باعث می‌شد یک سفارشِ کمی بالای آستانه با یک کوپن به زیر آستانه
     * بیفتد و گزینهٔ رزرو خاموش از خریدار گرفته شود (اجبار به پرداخت کامل).
     *
     * هر جایی که با آستانه مقایسه می‌شود باید از این متد استفاده کند:
     * ProcessFullOrderPaymentAction و هر دو مسیرِ progress در HasOrderProgress.
     */
    public function reservationBasisAmount(): float
    {
        return (float) $this->final_amount
            + (float) ($this->{self::COUPON_DISCOUNT_AMOUNT} ?? 0);
    }

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
    public function partyRolesFor(?int $userId): array
    {
        if ($userId === null) {
            return [];
        }

        $roles = [];

        if ((int) $this->{self::PLACER_USER_ID} === $userId) {
            $roles[] = OrderDocumentCatalog::AUDIENCE_BUYER;
        }

        // ذی‌نفع و الیت‌یار **دو نفرِ متفاوت‌اند** و نباید یک مخاطب حساب شوند.
        //
        // این دو ستون قبلاً هر دو به `AUDIENCE_ELITE_YAR` نگاشت می‌شدند. در مسیرِ
        // «الیت‌یار سفارش می‌دهد و کارفرما را نام می‌برد» — که `StoreOrderRequest`
        // اجباری‌اش می‌کند — ذی‌نفع همان کارفرما/مشتری است. نتیجه دو طرفه غلط بود:
        //
        //   • کارفرما سند D (گزارش پرداخت به الیت‌یار) و سند Q (فاکتور مودیانِ
        //     شخصیِ الیت‌یار، به مبلغ `payment_to_elite_yar`) را دانلود می‌کرد —
        //     یعنی سند مالیاتیِ مشاورش به دستِ خودِ مشتری می‌رسید.
        //   • و روی همهٔ اسناد خریدارِ خودش ۴۰۳ می‌گرفت، از جمله سند P (فاکتور
        //     مودیانی که *به نامِ او* صادر شده) و سند K (بارنامه).
        //
        // هر گاردِ دیگری ذی‌نفع را خریدار می‌داند (`OrderPolicy::view/pay/cancel/
        // finalizeInvoice` — او حتی می‌تواند پرداخت کند)، پس تنها همین‌جا ناسازگار بود.
        //
        // getAttribute و نه دسترسی جادویی: larastan بدون فایل‌های ide-helper (که
        // gitignore شده‌اند) این ستون‌ها را نمی‌شناسد و level 5 خطا می‌دهد.
        // مقایسه پس از null-check است تا ستونِ NULL با userId صفر جفت نشود.
        $beneficiaryId = $this->getAttribute(self::BENEFICIARY_USER_ID);
        $eliteYarId = $this->getAttribute(self::ELITE_YAR_USER_ID);

        if ($beneficiaryId !== null && (int) $beneficiaryId === $userId) {
            $roles[] = OrderDocumentCatalog::AUDIENCE_BUYER;
        }

        if ($eliteYarId !== null && (int) $eliteYarId === $userId) {
            $roles[] = OrderDocumentCatalog::AUDIENCE_ELITE_YAR;
        }

        // وقتی آیتم‌ها از قبل eager-load شده‌اند (که OrderResource همیشه می‌کند) در
        // حافظه چک می‌کنیم؛ وگرنه هر ردیفِ لیستِ سفارش‌ها یک کوئریِ اضافه می‌زد.
        $isSupplier = $this->relationLoaded('items')
            ? $this->items->contains(
                fn (OrderItem $item) => (int) $item->{OrderItem::SUPPLIER_USER_ID} === $userId
            )
            : $this->items()->where(OrderItem::SUPPLIER_USER_ID, $userId)->exists();

        if ($isSupplier) {
            $roles[] = OrderDocumentCatalog::AUDIENCE_SUPPLIER;
        }

        return $roles;
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
