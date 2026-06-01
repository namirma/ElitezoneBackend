<?php

namespace App\Models;

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
        self::SHIPPING_COST,
        self::STATUS,
    ];

    protected $casts = [
        self::WEIGHT => 'decimal:3',
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
