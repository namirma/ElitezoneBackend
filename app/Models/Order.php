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
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Order extends Model
{
    use HasFactory,
        PersianDate,
        LogsActivity,
        HasOrderProgress,
        HasOrderActions;

    // =========================================================================
    //  Constants (Database Columns)
    // =========================================================================
    public const
        ID = 'id',
        PLACER_USER_ID = 'placer_user_id',
        BENEFICIARY_USER_ID = 'beneficiary_user_id',
        ADDRESS_ID = 'address_id',
        ORDER_NUMBER = 'order_number',
        STATUS = 'status',
        SUBTOTAL = 'subtotal',
        DISCOUNT_AMOUNT = 'discount_amount',
        SHIPPING_COST = 'shipping_cost',
        VAT_AMOUNT = 'vat_amount',
        FINAL_AMOUNT = 'final_amount',
        PAYMENT_METHOD = 'payment_method',
        PAYMENT_STATUS = 'payment_status',
        NOTES = 'notes',
        PAID_AT = 'paid_at',
        WEIGHBRIDGE_REGISTERED_AT = 'weighbridge_registered_at',
        INITIAL_INVOICE_REF = 'initial_invoice_ref',
        FINAL_INVOICE_REF = 'final_invoice_ref',
        TAX_INVOICE_REF = 'tax_invoice_ref',
        EXPERT_FEE_AMOUNT = 'expert_fee_amount',
        EXPERT_FEE_PAYMENT_STATUS = 'expert_fee_payment_status',
        ENGINEER_FEE_AMOUNT = 'engineer_fee_amount',
        ENGINEER_FEE_PAYMENT_STATUS = 'engineer_fee_payment_status',
        CONTRACTOR_FEE_AMOUNT = 'contractor_fee_amount',
        CONTRACTOR_FEE_PAYMENT_STATUS = 'contractor_fee_payment_status',
        EXPERT_FEE_INVOICE_REF = 'expert_fee_invoice_ref',
        CANCEL_REASON = 'cancel_reason',
        SURVEY_STATUS = 'survey_status',
        PREPARED_AT = 'prepared_at',
        SHIPPED_AT = 'shipped_at',
        PAYMENT_RECEIPT_REF = 'payment_receipt_ref',
        ESPADANA_ORDER_REF = 'espadana_order_ref',
        WAYBILL_REF = 'waybill_ref',
        RESERVATION_EXPIRES_AT = 'reservation_expires_at',
        PARTIAL_PAYMENT_AMOUNT = 'partial_payment_amount',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';

    // =========================================================================
    //  Constants (Business Logic / Statuses)
    // =========================================================================
    public const
        STATUS_PENDING = 'pending',
        STATUS_RESERVED = 'reserved',
        STATUS_RESERVED_EXTENDED = 'reserved_extended',
        STATUS_PROCESSING = 'processing',
        STATUS_PREPARING = 'preparing',
        STATUS_WEIGHBRIDGE_DONE = 'weighbridge_done',
        STATUS_WAYBILL_ISSUED = 'waybill_issued',
        STATUS_SHIPPED = 'shipped',
        STATUS_DELIVERED = 'delivered',
        STATUS_COMPLETED = 'completed',
        STATUS_CANCELLED = 'cancelled',
        STATUS_FAILED = 'failed',

        PAYMENT_PENDING = 'pending',
        PAYMENT_PAID = 'paid',
        PAYMENT_FAILED = 'failed',
        PAYMENT_REFUND_PENDING = 'refund_pending',
        PAYMENT_REFUNDED = 'refunded',

        FEE_PAYMENT_PENDING = 'pending',
        FEE_PAYMENT_PAID = 'paid',

        SURVEY_PENDING = 'pending',
        SURVEY_REQUESTED = 'requested',
        SURVEY_COMPLETED = 'completed';


    //  Financial Constants (Elite Zone Logic)
    // =========================================================================
    public const
        OFFICIAL_PRICE = 'official_price',
        WEIGHT_BEFORE_SCALE = 'weight_before_scale',
        COMMISSION_RATE = 'commission_rate',
        OWNER_DISCOUNT_RATE = 'owner_discount_rate',
        SAZE_SHARE_RATE = 'saze_share_rate',
        CONSULTANT_SHARE_RATE = 'consultant_share_rate',
        VAT_RATE = 'vat_rate',
        PAYMENT_OF_OWNER = 'payment_of_owner',
        PAYMENT_TO_SUPPLIER = 'payment_to_supplier',
        PAYMENT_TO_CONSULTANT = 'payment_to_consultant',
        PAYMENT_TO_RESERVE = 'payment_to_reserve',
        VALIDATION_OF_RESERVE = 'validation_of_reserve';

    // =========================================================================
    //  Configurations
    // =========================================================================
    protected $fillable = [
        self::PLACER_USER_ID,
        self::BENEFICIARY_USER_ID,
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

        self::OFFICIAL_PRICE,
        self::WEIGHT_BEFORE_SCALE,
        self::COMMISSION_RATE,
        self::OWNER_DISCOUNT_RATE,
        self::SAZE_SHARE_RATE,
        self::CONSULTANT_SHARE_RATE,
        self::VAT_RATE,
        self::PAYMENT_OF_OWNER,
        self::PAYMENT_TO_SUPPLIER,
        self::PAYMENT_TO_CONSULTANT,
        self::PAYMENT_TO_RESERVE,
        self::VALIDATION_OF_RESERVE,
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
        self::VAT_RATE => 'decimal:2',
        self::PAYMENT_OF_OWNER => 'decimal:2',
        self::PAYMENT_TO_SUPPLIER => 'decimal:2',
        self::PAYMENT_TO_RESERVE => 'decimal:2',
        self::VALIDATION_OF_RESERVE => 'datetime',
    ];

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

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class, self::ADDRESS_ID);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, OrderItem::ORDER_ID);
    }

    public function surveys(): HasMany
    {
        return $this->hasMany(OrderSurvey::class, OrderSurvey::ORDER_ID);
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
                    default   => $eventName,
                };
                return "سفارش شماره {$this->order_number} {$action} شد.";
            });
    }

    public function newEloquentBuilder($query): OrderBuilder
    {
        return new OrderBuilder($query);
    }
}
