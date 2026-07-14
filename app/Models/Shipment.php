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
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Address|null $destinationAddress
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderItem> $items
 * @property-read int|null $items_count
 * @property-read \App\Models\Order $order
 * @property-read \App\Models\Factory|null $originFactory
 * @property-read \App\Models\FactoryLocation|null $originFactoryLocation
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
    ];

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
