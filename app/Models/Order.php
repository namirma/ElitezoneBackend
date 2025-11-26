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
        $id = $this->attributes[self::ID];
        $paymentStatus = $this->attributes[self::PAYMENT_STATUS];

        // مقداردهی اولیه استیت‌ها
        $stepsData = [
            1 => ['state' => 'inactive', 'actions' => []],
            2 => ['state' => 'inactive', 'actions' => []],
            3 => ['state' => 'inactive', 'actions' => []],
            4 => ['state' => 'inactive', 'actions' => []],
            5 => ['state' => 'inactive', 'actions' => []],
        ];

        // --- محاسبه وضعیت دایره‌ها (State) ---
        $stepsData[1]['state'] = 'completed';

        if (in_array($s, [self::STATUS_PENDING, self::STATUS_RESERVED_EXTENDED, self::STATUS_PROCESSING, self::STATUS_PREPARING])) {
            $stepsData[2]['state'] = 'current';
        } elseif (in_array($s, [self::STATUS_WEIGHBRIDGE_DONE, self::STATUS_WAYBILL_ISSUED, self::STATUS_SHIPPED, self::STATUS_DELIVERED])) {
            $stepsData[2]['state'] = 'completed';
        }

        if ($s === self::STATUS_WEIGHBRIDGE_DONE) {
            $stepsData[3]['state'] = 'current';
        } elseif (in_array($s, [self::STATUS_WAYBILL_ISSUED, self::STATUS_SHIPPED, self::STATUS_DELIVERED])) {
            $stepsData[3]['state'] = 'completed';
        }

        if (in_array($s, [self::STATUS_WAYBILL_ISSUED, self::STATUS_SHIPPED])) {
            $stepsData[4]['state'] = 'current';
        } elseif ($s === self::STATUS_DELIVERED) {
            $stepsData[4]['state'] = 'completed';
        }

        if ($s === self::STATUS_DELIVERED) {
            $stepsData[5]['state'] = 'completed';
        }

        // --- محاسبه لینک‌ها و اکشن‌ها (Actions) ---

        // مرحله ۱: رزرو
        if ($paymentStatus === self::PAYMENT_PENDING && in_array($s, [self::STATUS_PENDING, self::STATUS_RESERVED_EXTENDED])) {
            $stepsData[1]['actions'][] = [
                'label' => 'پرداخت فاکتور',
                'link'  => "/checkout/payment/{$id}", // مستقیم به صفحه پرداخت
                'type'  => 'primary'
            ];
        } elseif ($paymentStatus === self::PAYMENT_PAID) {
            $stepsData[1]['actions'][] = [
                'label' => 'دریافت رسید',
                'link'  => "/profile/orders/{$id}/invoice/initial", // دانلود مستقیم
                'type'  => 'secondary'
            ];
        }

        // مرحله ۲: ثبت اولیه
        if (in_array($s, [self::STATUS_WEIGHBRIDGE_DONE, self::STATUS_WAYBILL_ISSUED, self::STATUS_SHIPPED, self::STATUS_DELIVERED])) {
             $stepsData[2]['actions'][] = [
                'label' => 'دانلود فاکتور اولیه',
                'link'  => "/profile/orders/{$id}/invoice/initial", // دانلود مستقیم
                'type'  => 'secondary'
            ];
        } else {
            $stepsData[2]['actions'][] = [
                'label' => 'مشاهده جزئیات',
                'link'  => "/profile/orders/{$id}", // مشاهده در سایت
                'type'  => 'secondary'
            ];
        }

        // مرحله ۳: ثبت نهایی (باسکول)
        if ($s === self::STATUS_WEIGHBRIDGE_DONE) {
            $stepsData[3]['actions'][] = [
                'label' => 'فاکتور نهایی (تسویه)',
                'link'  => "/checkout/payment/{$id}",
                'type'  => 'primary'
            ];
        } elseif (in_array($s, [self::STATUS_WAYBILL_ISSUED, self::STATUS_SHIPPED, self::STATUS_DELIVERED])) {
            $stepsData[3]['actions'][] = [
                'label' => 'دریافت فاکتور نهایی',
                'link'  => "/profile/orders/{$id}/invoice/final", // دانلود مستقیم
                'type'  => 'secondary'
            ];
        }

        // مرحله ۴: حمل
        if (in_array($s, [self::STATUS_WAYBILL_ISSUED, self::STATUS_SHIPPED])) {
            // بارنامه معمولاً عکس یا متنی در صفحه جزئیات است
            $stepsData[4]['actions'][] = [
                'label' => 'مشاهده بارنامه',
                'link'  => "/profile/orders/{$id}",
                'type'  => 'primary'
            ];
        } elseif ($s === self::STATUS_DELIVERED) {
             $stepsData[4]['actions'][] = [
                'label' => 'مشاهده جزئیات حمل',
                'link'  => "/profile/orders/{$id}",
                'type'  => 'secondary'
            ];
        }

        // مرحله ۵: تکمیل
        if ($s === self::STATUS_DELIVERED) {
            $stepsData[5]['actions'][] = [
                'label' => 'دریافت فاکتور رسمی',
                'link'  => "/profile/orders/{$id}/invoice/tax", // دانلود مستقیم
                'type'  => 'secondary'
            ];
        }

        return [
            'is_cancelled' => in_array($s, [self::STATUS_CANCELLED, self::STATUS_FAILED]),
            'steps' => [
                [
                    'step' => 1,
                    'title' => 'رزرو سفارش',
                    'state' => $stepsData[1]['state'],
                    'actions' => $stepsData[1]['actions']
                ],
                [
                    'step' => 2,
                    'title' => 'ثبت اولیه پس از پرداخت',
                    'state' => $stepsData[2]['state'],
                    'actions' => $stepsData[2]['actions']
                ],
                [
                    'step' => 3,
                    'title' => 'ثبت نهایی بعد از باسکول',
                    'state' => $stepsData[3]['state'],
                    'actions' => $stepsData[3]['actions']
                ],
                [
                    'step' => 4,
                    'title' => 'در حال حمل',
                    'state' => $stepsData[4]['state'],
                    'actions' => $stepsData[4]['actions']
                ],
                [
                    'step' => 5,
                    'title' => 'تکمیل شده',
                    'state' => $stepsData[5]['state'],
                    'actions' => $stepsData[5]['actions']
                ],
            ]
        ];
    }
}
