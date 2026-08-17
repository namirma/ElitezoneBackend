<?php

namespace App\Models;

use App\Enums\Shipping\ShippingCostBasis;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One delivery leg of an order: items from a single origin factory location to a single
 * destination address, with its own freight cost and lifecycle status.
 *
 * @see ShippingCostCalculator
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $factory_id
 * @property int|null $factory_location_id
 * @property int|null $address_id
 * @property int|null $destination_province_id
 * @property int|null $destination_city_id
 * @property numeric $weight
 * @property numeric $billed_count
 * @property ShippingCostBasis $cost_basis
 * @property numeric $shipping_cost
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Address|null $destinationAddress
 * @property-read Collection<int, OrderItem> $items
 * @property-read int|null $items_count
 * @property-read Order $order
 * @property-read Factory|null $originFactory
 * @property-read FactoryLocation|null $originFactoryLocation
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereAddressId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereBilledCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereCostBasis($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereDestinationCityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereDestinationProvinceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereFactoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereFactoryLocationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereShippingCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereWeight($value)
 *
 * @mixin \Eloquent
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

    public const STATUS_DELIVERED = 'delivered';

    // ── بارنامه و راننده (فاز ۷، بند ۳.۷) ────────────────────────────────────
    public const WAYBILL_REF = 'waybill_ref';

    public const DRIVER_NAME = 'driver_name';

    public const DRIVER_NATIONAL_CODE = 'driver_national_code';

    public const DRIVER_MOBILE = 'driver_mobile';

    public const VEHICLE_PLATE = 'vehicle_plate';

    public const VEHICLE_TYPE = 'vehicle_type';

    /** وزنِ باسکولِ همین لِگ — مجموعشان تسویه‌ی سفارش را تغذیه می‌کند. */
    public const ACTUAL_WEIGHT = 'actual_weight';

    /** لحظه‌ی ثبتِ بارنامه. `null` یعنی این محموله هنوز بار نزده است. */
    public const REGISTERED_AT = 'registered_at';

    /**
     * «این آخرین محموله بود» — ماشه‌ی تسویه‌ی نهایی (بند ۸.۲).
     *
     * بدون آن، کم‌تحویلی هرگز تسویه نمی‌شد: مجموع وزن‌ها به وزنِ سفارش
     * نمی‌رسد و ماشه‌ی خودکار هیچ‌وقت فعال نمی‌شود.
     */
    public const IS_FINAL = 'is_final';

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
        self::SHIPPING_COST => 'decimal:2',
        self::ACTUAL_WEIGHT => 'decimal:3',
        self::REGISTERED_AT => 'datetime',
        self::IS_FINAL => 'boolean',
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
