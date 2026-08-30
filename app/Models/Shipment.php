<?php

namespace App\Models;

use App\Enums\Shipping\ShippingCostBasis;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One delivery leg of an order: items from a single origin factory location to a single
 * destination address, with its own freight cost and lifecycle status.
 *
 * @see ShippingCostCalculator
 */
class Shipment extends Model
{
    use HasFactory;

    public const ID = 'id';

    public const ORDER_ID = 'order_id';

    public const FACTORY_ID = 'factory_id';

    public const FACTORY_LOCATION_ID = 'factory_location_id';

    public const ADDRESS_ID = 'address_id';

    public const DESTINATION_PROVINCE_ID = 'destination_province_id';

    public const DESTINATION_CITY_ID = 'destination_city_id';

    public const WEIGHT = 'weight';

    public const BILLED_COUNT = 'billed_count';

    public const COST_BASIS = 'cost_basis';

    public const SHIPPING_COST = 'shipping_cost';

    public const STATUS = 'status';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PREPARING = 'preparing';

    public const STATUS_SHIPPED = 'shipped';

    /** راننده رسیده و مهلت تأیید خریدار شروع شده (فاز ۱۰). */
    public const STATUS_ARRIVED = 'arrived';

    public const STATUS_DELIVERED = 'delivered';

    /** خریدار تا پایان مهلت تأیید نکرد؛ بار به مبدأ برگشت (فاز ۱۰/۱۱). */
    public const STATUS_RETURNED = 'returned';

    /**
     * ترتیب واقعیِ چرخه — بند ۴.۵ سند.
     *
     * `preparing` عمداً اینجا نیست: در فاز ۰ از وضعیت‌های سفارش حذف شد و روی
     * محموله هم هیچ نویسنده‌ای ندارد. ثابتش برای سازگاری مانده است.
     */
    public const LIFECYCLE = [
        self::STATUS_PENDING,
        self::STATUS_SHIPPED,
        self::STATUS_ARRIVED,
        self::STATUS_DELIVERED,
        self::STATUS_RETURNED,
    ];

    // ── بارنامه و راننده (فاز ۷، بند ۳.۷) ────────────────────────────────────
    public const WAYBILL_REF = 'waybill_ref';

    public const DRIVER_NAME = 'driver_name';

    public const DRIVER_NATIONAL_CODE = 'driver_national_code';

    public const DRIVER_MOBILE = 'driver_mobile';

    public const VEHICLE_PLATE = 'vehicle_plate';

    public const VEHICLE_TYPE = 'vehicle_type';

    /** وزنِ باسکولِ همین لِگ — مجموعشان تسویه‌ی سفارش را تغذیه می‌کند. */
    public const ACTUAL_WEIGHT = 'actual_weight';

    /**
     * وزن از کجا آمد — `api` | `ocr` | `manual` (بند ۳.۷ / ۴.۵).
     *
     * ‏`null` یعنی نامعلوم (بارنامه‌های پیش از این ستون)، نه ادعای منبع.
     */
    public const WEIGHT_SOURCE = 'weight_source';

    /** راننده‌ی این محموله از دفترچه‌ی حمل‌کننده (بند ۴.۶). */
    public const DRIVER_ID = 'driver_id';

    /** لحظه‌ی ثبتِ بارنامه. `null` یعنی این محموله هنوز بار نزده است. */
    public const REGISTERED_AT = 'registered_at';

    /**
     * «این آخرین محموله بود» — ماشه‌ی تسویه‌ی نهایی (بند ۸.۲).
     *
     * بدون آن، کم‌تحویلی هرگز تسویه نمی‌شد: مجموع وزن‌ها به وزنِ سفارش
     * نمی‌رسد و ماشه‌ی خودکار هیچ‌وقت فعال نمی‌شود.
     */
    public const IS_FINAL = 'is_final';

    // ── چرخه‌ی تحویل (فاز ۱۰، بند ۳.۱۰) ──────────────────────────────────────

    /** لحظه‌ی رسیدن راننده — ماشه‌ی شروع مهلت تأیید. */
    public const ARRIVED_AT = 'arrived_at';

    /** `driver_link` یا `backoffice`؛ بند ۳.۱۰ تأیید تلفنی را هم مجاز می‌داند. */
    public const ARRIVAL_SOURCE = 'arrival_source';

    public const ARRIVAL_DRIVER_LINK = 'driver_link';

    public const ARRIVAL_BACKOFFICE = 'backoffice';

    /** مهلت تأیید خریدار بر حسب ساعت (پیش‌فرض ۵، per-shipment). */
    public const DELIVERY_DEADLINE_HOURS = 'delivery_deadline_hours';

    /** لحظه‌ی محاسبه‌شده‌ی پایان مهلت — اسنپ‌شات، نه بازخوانی از تنظیم. */
    public const DELIVERY_DEADLINE = 'delivery_deadline';

    public const DELIVERED_AT = 'delivered_at';

    /** `null` یعنی تأیید از لینک امضاشده آمده، نه از پنل. */
    public const DELIVERY_CONFIRMED_BY = 'delivery_confirmed_by';

    public const RETURNED_AT = 'returned_at';

    protected $fillable = [
        self::ORDER_ID,
        self::FACTORY_ID,
        self::FACTORY_LOCATION_ID,
        self::ADDRESS_ID,
        self::DESTINATION_PROVINCE_ID,
        self::DESTINATION_CITY_ID,
        self::WEIGHT,
        self::BILLED_COUNT,
        self::COST_BASIS,
        self::SHIPPING_COST,
        self::STATUS,
    ];

    protected $casts = [
        self::WEIGHT => 'decimal:3',
        self::BILLED_COUNT => 'decimal:3',
        self::COST_BASIS => ShippingCostBasis::class,
        self::SHIPPING_COST => 'decimal:0',
        self::ACTUAL_WEIGHT => 'decimal:3',
        self::REGISTERED_AT => 'datetime',
        self::IS_FINAL => 'boolean',
        self::ARRIVED_AT => 'datetime',
        self::DELIVERY_DEADLINE => 'datetime',
        self::DELIVERED_AT => 'datetime',
        self::RETURNED_AT => 'datetime',
    ];

    /**
     * ستون‌های بارنامه عمداً fillable نیستند — فقط `RegisterShipmentAction`
     * آن‌ها را می‌نویسد. یک محموله‌ی ثبت‌شده سندِ حملِ رسمی است و نباید از
     * مسیرِ mass-assignment قابلِ بازنویسی باشد.
     */
    public function isRegistered(): bool
    {
        return $this->{self::REGISTERED_AT} !== null;
    }

    public function hasArrived(): bool
    {
        return $this->{self::ARRIVED_AT} !== null;
    }

    public function isDelivered(): bool
    {
        return $this->{self::DELIVERED_AT} !== null;
    }

    /**
     * محموله‌ای که هنوز نه تحویل شده و نه برگشته — یعنی «بلاتکلیف».
     *
     * `partially_delivered` دقیقاً همین است: بعضی تأیید شده و بعضی هنوز
     * بلاتکلیف (بند ۳.۱۰). برگشته‌ها بلاتکلیف نیستند، تکلیفشان روشن است.
     */
    public function isOutstanding(): bool
    {
        return $this->{self::DELIVERED_AT} === null && $this->{self::RETURNED_AT} === null;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, self::ORDER_ID);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, OrderItem::SHIPMENT_ID);
    }

    public function originFactory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, self::FACTORY_ID);
    }

    public function originFactoryLocation(): BelongsTo
    {
        return $this->belongsTo(FactoryLocation::class, self::FACTORY_LOCATION_ID);
    }

    public function destinationAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, self::ADDRESS_ID);
    }
}
