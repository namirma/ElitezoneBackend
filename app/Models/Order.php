<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\PersianDate;
use function in_array;

class Order extends Model
{
    use HasFactory, PersianDate;

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

    // Status Constants
    public const
    STATUS_PENDING = 'pending',
    STATUS_RESERVED_EXTENDED = 'reserved_extended',
    STATUS_PROCESSING = 'processing',
    STATUS_PREPARING = 'preparing',
    STATUS_WEIGHBRIDGE_DONE = 'weighbridge_done',
    STATUS_WAYBILL_ISSUED = 'waybill_issued',
    STATUS_SHIPPED = 'shipped',
    STATUS_DELIVERED = 'delivered',
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
    ];

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

    public function getDashboardProgressAttribute(): array
    {
        $s = $this->attributes[self::STATUS];
        $stepsState = [
            1 => 'inactive',
            2 => 'inactive',
            3 => 'inactive',
            4 => 'inactive',
            5 => 'inactive',
        ];
        $stepsState[1] = 'completed';

        if (in_array($s, [self::STATUS_PENDING, self::STATUS_RESERVED_EXTENDED, self::STATUS_PROCESSING, self::STATUS_PREPARING])) {
            $stepsState[2] = 'current';
        } elseif (in_array($s, [self::STATUS_WEIGHBRIDGE_DONE, self::STATUS_WAYBILL_ISSUED, self::STATUS_SHIPPED, self::STATUS_DELIVERED])) {
            $stepsState[2] = 'completed';
        }

        if ($s === self::STATUS_WEIGHBRIDGE_DONE) {
            $stepsState[3] = 'current';
        } elseif (in_array($s, [self::STATUS_WAYBILL_ISSUED, self::STATUS_SHIPPED, self::STATUS_DELIVERED])) {
            $stepsState[3] = 'completed';
        }


        if (in_array($s, [self::STATUS_WAYBILL_ISSUED, self::STATUS_SHIPPED])) {
            $stepsState[4] = 'current';
        } elseif ($s === self::STATUS_DELIVERED) {

            $stepsState[4] = 'completed';
        }
        if ($s === self::STATUS_DELIVERED) {
            $stepsState[5] = 'completed';
        }
        if ($s === self::STATUS_CANCELLED || $s === self::STATUS_FAILED) {
        }
        return [
            'is_cancelled' => in_array($s, [self::STATUS_CANCELLED, self::STATUS_FAILED]),
            'steps' => [
                [
                    'step' => 1,
                    'title' => 'رزرو سفارش',
                    'state' => $stepsState[1]
                ],
                [
                    'step' => 2,
                    'title' => 'ثبت اولیه پس از پرداخت',
                    'state' => $stepsState[2]
                ],
                [
                    'step' => 3,
                    'title' => 'ثبت نهایی بعد از باسکول',
                    'state' => $stepsState[3]
                ],
                [
                    'step' => 4,
                    'title' => 'در حال حمل',
                    'state' => $stepsState[4]
                ],
                [
                    'step' => 5,
                    'title' => 'تکمیل شده',
                    'state' => $stepsState[5]
                ],
            ]
        ];
    }
}
