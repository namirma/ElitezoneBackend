<?php

namespace App\Models;

use App\Models\Builders\OrderBuilder;
use App\Models\Concerns\HasOrderActions;
use App\Models\Concerns\HasOrderProgress;
use App\Traits\PersianDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int $placer_user_id
 * @property int|null $beneficiary_user_id
 * @property int|null $consultant_user_id الیت یار که کمیسیون CO را دریافت می‌کند
 * @property int|null $address_id
 * @property string $order_number
 * @property string $status
 * @property string $subtotal
 * @property string $discount_amount
 * @property string $shipping_cost
 * @property string $vat_amount
 * @property string $final_amount
 * @property string|null $payment_method
 * @property string $payment_status
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property \Illuminate\Support\Carbon|null $weighbridge_registered_at
 * @property string|null $initial_invoice_ref
 * @property string|null $final_invoice_ref
 * @property string|null $tax_invoice_ref
 * @property string|null $expert_fee_amount
 * @property string|null $expert_fee_payment_status
 * @property string|null $engineer_fee_amount
 * @property string|null $engineer_fee_payment_status
 * @property string|null $contractor_fee_amount
 * @property string|null $contractor_fee_payment_status
 * @property string|null $expert_fee_invoice_ref
 * @property string|null $cancel_reason
 * @property string|null $survey_status
 * @property \Illuminate\Support\Carbon|null $prepared_at
 * @property \Illuminate\Support\Carbon|null $shipped_at
 * @property string|null $payment_receipt_ref
 * @property string|null $espadana_order_ref
 * @property string|null $waybill_ref
 * @property \Illuminate\Support\Carbon|null $reservation_expires_at تاریخ انقضای رزرو (2 ساعته یا تمدید شده)
 * @property string|null $partial_payment_amount مبلغ پرداخت شده برای تمدید رزرو
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $official_price OP: قیمت رسمی محصول
 * @property string|null $weight_before_scale WBS: وزن قبل از باسکول
 * @property string $commission_rate %TC: درصد کمیسیون
 * @property string $owner_discount_rate %OW: درصد تخفیف خریدار
 * @property string $saze_share_rate %SSG: درصد سهم سازه
 * @property string $consultant_share_rate %CO: درصد سهم مشاور
 * @property bool $trans_commission +%TC snapshot: کمیسیون روی حمل؟ (از پروفایل سوپلایر در زمان پرداخت کپی می‌شود)
 * @property bool $trans_vat +VAT snapshot: مالیات روی حمل؟ (از پروفایل سوپلایر در زمان پرداخت کپی می‌شود)
 * @property string $vat_rate %VAT: درصد ارزش افزوده
 * @property string|null $payment_of_owner POO: پرداختی خریدار
 * @property string|null $payment_to_supplier PTS: پرداختی به سوپلایر
 * @property string|null $payment_to_consultant PTC: پرداختی به مشاور
 * @property string|null $payment_to_reserve PTR: مبلغ پرداخت رزرو
 * @property \Illuminate\Support\Carbon|null $validation_of_reserve VOR: تاریخ اعتبار رزرو
 * @property string|null $weight_after_scale WAS: وزن باسکول
 * @property \Illuminate\Support\Carbon|null $weighing_completed_at
 * @property string|null $consultant_moadian_ref Doc Q: شماره فاکتور مودیان صادر شده توسط الیت یار
 * @property \Illuminate\Support\Carbon|null $consultant_hold_released_at زمان آزادسازی هولد کمیسیون مشاور
 * @property string|null $supplier_moadian_ref Doc O: شماره فاکتور مودیان صادر شده توسط سوپلایر
 * @property \Illuminate\Support\Carbon|null $supplier_hold_released_at زمان آزادسازی هولد مالیات سوپلایر در WVAT
 * @property bool $supplier_debt_pending آیا سوپلایر بدهی باسکول دارد که هنوز تسویه نشده؟
 * @property string $supplier_debt_amount مبلغ بدهی سوپلایر بابت کسری باسکول (ریال)
 * @property string|null $system_moadian_ref Doc P: شماره فاکتور مودیان صادر شده توسط سازه (پلتفرم) برای خریدار
 * @property \Illuminate\Support\Carbon|null $system_hold_released_at زمان آزادسازی هولد مالیات پلتفرم (sazeVat) از WVAT
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Spatie\Activitylog\Models\Activity> $activities
 * @property-read int|null $activities_count
 * @property-read \App\Models\Address|null $address
 * @property-read \App\Models\User|null $beneficiary
 * @property-read \App\Models\User|null $consultant
 * @property-read array $consultant_progress
 * @property-read string|void $created_at_diff
 * @property-read string|void $created_at_p
 * @property-read array $dashboard_progress
 * @property-read string|void $expire_at_days
 * @property-read array $supplier_progress
 * @property-read string|void $updated_at_diff
 * @property-read string|void $updated_at_p
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderItem> $items
 * @property-read int|null $items_count
 * @property-read \App\Models\User $placer
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Shipment> $shipments
 * @property-read int|null $shipments_count
 * @property-read \App\Models\ShippingMethod|null $shippingMethod
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderSurvey> $surveys
 * @property-read int|null $surveys_count
 * @method static OrderBuilder|Order cancelled()
 * @method static OrderBuilder|Order current()
 * @method static OrderBuilder|Order delivered()
 * @method static OrderBuilder|Order expired()
 * @method static OrderBuilder|Order forSupplier(int $supplierId)
 * @method static OrderBuilder|Order forUser(int $userId)
 * @method static OrderBuilder|Order newModelQuery()
 * @method static OrderBuilder|Order newQuery()
 * @method static OrderBuilder|Order paid()
 * @method static OrderBuilder|Order query()
 * @method static OrderBuilder|Order whereAddressId($value)
 * @method static OrderBuilder|Order whereBeneficiaryUserId($value)
 * @method static OrderBuilder|Order whereCancelReason($value)
 * @method static OrderBuilder|Order whereCommissionRate($value)
 * @method static OrderBuilder|Order whereConsultantHoldReleasedAt($value)
 * @method static OrderBuilder|Order whereConsultantMoadianRef($value)
 * @method static OrderBuilder|Order whereConsultantShareRate($value)
 * @method static OrderBuilder|Order whereConsultantUserId($value)
 * @method static OrderBuilder|Order whereContractorFeeAmount($value)
 * @method static OrderBuilder|Order whereContractorFeePaymentStatus($value)
 * @method static OrderBuilder|Order whereCreatedAt($value)
 * @method static OrderBuilder|Order whereDiscountAmount($value)
 * @method static OrderBuilder|Order whereEngineerFeeAmount($value)
 * @method static OrderBuilder|Order whereEngineerFeePaymentStatus($value)
 * @method static OrderBuilder|Order whereEspadanaOrderRef($value)
 * @method static OrderBuilder|Order whereExpertFeeAmount($value)
 * @method static OrderBuilder|Order whereExpertFeeInvoiceRef($value)
 * @method static OrderBuilder|Order whereExpertFeePaymentStatus($value)
 * @method static OrderBuilder|Order whereFinalAmount($value)
 * @method static OrderBuilder|Order whereFinalInvoiceRef($value)
 * @method static OrderBuilder|Order whereId($value)
 * @method static OrderBuilder|Order whereInitialInvoiceRef($value)
 * @method static OrderBuilder|Order whereNotes($value)
 * @method static OrderBuilder|Order whereOfficialPrice($value)
 * @method static OrderBuilder|Order whereOrderNumber($value)
 * @method static OrderBuilder|Order whereOwnerDiscountRate($value)
 * @method static OrderBuilder|Order wherePaidAt($value)
 * @method static OrderBuilder|Order wherePartialPaymentAmount($value)
 * @method static OrderBuilder|Order wherePaymentMethod($value)
 * @method static OrderBuilder|Order wherePaymentOfOwner($value)
 * @method static OrderBuilder|Order wherePaymentReceiptRef($value)
 * @method static OrderBuilder|Order wherePaymentStatus($value)
 * @method static OrderBuilder|Order wherePaymentToConsultant($value)
 * @method static OrderBuilder|Order wherePaymentToReserve($value)
 * @method static OrderBuilder|Order wherePaymentToSupplier($value)
 * @method static OrderBuilder|Order wherePlacerUserId($value)
 * @method static OrderBuilder|Order wherePreparedAt($value)
 * @method static OrderBuilder|Order whereReservationExpiresAt($value)
 * @method static OrderBuilder|Order whereSazeShareRate($value)
 * @method static OrderBuilder|Order whereShippedAt($value)
 * @method static OrderBuilder|Order whereShippingCost($value)
 * @method static OrderBuilder|Order whereStatus($value)
 * @method static OrderBuilder|Order whereSubtotal($value)
 * @method static OrderBuilder|Order whereSupplierDebtAmount($value)
 * @method static OrderBuilder|Order whereSupplierDebtPending($value)
 * @method static OrderBuilder|Order whereSupplierHoldReleasedAt($value)
 * @method static OrderBuilder|Order whereSupplierMoadianRef($value)
 * @method static OrderBuilder|Order whereSurveyStatus($value)
 * @method static OrderBuilder|Order whereSystemHoldReleasedAt($value)
 * @method static OrderBuilder|Order whereSystemMoadianRef($value)
 * @method static OrderBuilder|Order whereTaxInvoiceRef($value)
 * @method static OrderBuilder|Order whereTransCommission($value)
 * @method static OrderBuilder|Order whereTransVat($value)
 * @method static OrderBuilder|Order whereUpdatedAt($value)
 * @method static OrderBuilder|Order whereValidationOfReserve($value)
 * @method static OrderBuilder|Order whereVatAmount($value)
 * @method static OrderBuilder|Order whereVatRate($value)
 * @method static OrderBuilder|Order whereWaybillRef($value)
 * @method static OrderBuilder|Order whereWeighbridgeRegisteredAt($value)
 * @method static OrderBuilder|Order whereWeighingCompletedAt($value)
 * @method static OrderBuilder|Order whereWeightAfterScale($value)
 * @method static OrderBuilder|Order whereWeightBeforeScale($value)
 * @mixin \Eloquent
 */
class Order extends Model
{
    use HasFactory,
        HasOrderActions,
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
    public const CONSULTANT_USER_ID = 'consultant_user_id';

    public const ADDRESS_ID = 'address_id';

    public const ORDER_NUMBER = 'order_number';

    public const STATUS = 'status';

    public const SUBTOTAL = 'subtotal';

    public const DISCOUNT_AMOUNT = 'discount_amount';

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

    public const CONSULTANT_SHARE_RATE = 'consultant_share_rate';

    public const VAT_RATE = 'vat_rate';

    public const PAYMENT_OF_OWNER = 'payment_of_owner';

    public const PAYMENT_TO_SUPPLIER = 'payment_to_supplier';

    public const PAYMENT_TO_CONSULTANT = 'payment_to_consultant';

    public const PAYMENT_TO_RESERVE = 'payment_to_reserve';

    public const VALIDATION_OF_RESERVE = 'validation_of_reserve';

    public const WEIGHT_AFTER_SCALE = 'weight_after_scale';

    public const WEIGHING_COMPLETED_AT = 'weighing_completed_at';

    /** +%TC snapshot: آیا کمیسیون روی هزینه حمل اعمال شده؟ */
    public const TRANS_COMMISSION = 'trans_commission';

    /** +VAT snapshot: آیا مالیات روی هزینه حمل اعمال شده؟ */
    public const TRANS_VAT = 'trans_vat';

    /** Doc Q: شماره فاکتور مودیان الیت یار */
    public const CONSULTANT_MOADIAN_REF = 'consultant_moadian_ref';

    /** زمان آزادسازی هولد کمیسیون مشاور */
    public const CONSULTANT_HOLD_RELEASED_AT = 'consultant_hold_released_at';

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
        self::CONSULTANT_USER_ID,
        self::ADDRESS_ID,
        self::ORDER_NUMBER,
        self::STATUS,
        self::SUBTOTAL,
        self::DISCOUNT_AMOUNT,
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
        self::CONSULTANT_SHARE_RATE => 'decimal:2',
        self::VAT_RATE => 'decimal:2',
        self::PAYMENT_OF_OWNER => 'decimal:2',
        self::PAYMENT_TO_SUPPLIER => 'decimal:2',
        self::PAYMENT_TO_CONSULTANT => 'decimal:2',
        self::PAYMENT_TO_RESERVE => 'decimal:2',
        self::VALIDATION_OF_RESERVE => 'datetime',
        self::WEIGHT_AFTER_SCALE => 'decimal:3',
        self::WEIGHING_COMPLETED_AT => 'datetime',
        self::TRANS_COMMISSION => 'boolean',
        self::TRANS_VAT => 'boolean',
        self::CONSULTANT_HOLD_RELEASED_AT => 'datetime',
        self::SUPPLIER_HOLD_RELEASED_AT => 'datetime',
        self::SUPPLIER_DEBT_PENDING => 'boolean',
        self::SUPPLIER_DEBT_AMOUNT => 'decimal:2',
        self::SYSTEM_HOLD_RELEASED_AT => 'datetime',
    ];

    // =========================================================================
    //  Resource Helpers
    // =========================================================================

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
            'consultant.profile',
            'consultant.badge',
            'address.city',
            'address.province',
            'shipments.originFactory',
            'shipments.originFactoryLocation',
            'shipments.destinationAddress.city',
            'shipments.destinationAddress.province',
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

    /** الیت یار که کمیسیون CO را دریافت می‌کند */
    public function consultant(): BelongsTo
    {
        return $this->belongsTo(User::class, self::CONSULTANT_USER_ID);
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
