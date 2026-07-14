<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property int|null $shipment_id
 * @property int|null $product_variant_id
 * @property int|null $supplier_user_id User ID of the supplier fulfilling this item
 * @property int|null $factory_id
 * @property int|null $factory_location_id
 * @property string $product_name
 * @property string|null $variant_sku
 * @property array $quantity_details
 * @property string $weight Total logistics weight in KG (Required for Weighbridge)
 * @property string $quantity Sales quantity (Count, Area, Volume, etc.)
 * @property string|null $unit_name Snapshot of the unit name (e.g., Bundle, M2, Branch)
 * @property string $unit_price
 * @property string $total_price
 * @property string $shipping_cost
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Order $order
 * @property-read \App\Models\Factory|null $originFactory
 * @property-read \App\Models\FactoryLocation|null $originFactoryLocation
 * @property-read \App\Models\ProductVariant|null $productVariant
 * @property-read \App\Models\Shipment|null $shipment
 * @property-read \App\Models\User|null $supplier
 * @method static \Database\Factories\OrderItemFactory factory($count = null, $state = [])
 * @method static Builder|OrderItem newModelQuery()
 * @method static Builder|OrderItem newQuery()
 * @method static Builder|OrderItem query()
 * @method static Builder|OrderItem sumQuantity()
 * @method static Builder|OrderItem whereCreatedAt($value)
 * @method static Builder|OrderItem whereFactoryId($value)
 * @method static Builder|OrderItem whereFactoryLocationId($value)
 * @method static Builder|OrderItem whereId($value)
 * @method static Builder|OrderItem whereOrderId($value)
 * @method static Builder|OrderItem whereProductName($value)
 * @method static Builder|OrderItem whereProductVariantId($value)
 * @method static Builder|OrderItem whereQuantity($value)
 * @method static Builder|OrderItem whereQuantityDetails($value)
 * @method static Builder|OrderItem whereShipmentId($value)
 * @method static Builder|OrderItem whereShippingCost($value)
 * @method static Builder|OrderItem whereSupplierUserId($value)
 * @method static Builder|OrderItem whereTotalPrice($value)
 * @method static Builder|OrderItem whereUnitName($value)
 * @method static Builder|OrderItem whereUnitPrice($value)
 * @method static Builder|OrderItem whereUpdatedAt($value)
 * @method static Builder|OrderItem whereVariantSku($value)
 * @method static Builder|OrderItem whereWeight($value)
 * @mixin \Eloquent
 */
class OrderItem extends Model
{
    use HasFactory;

    public const ID = 'id';

    public const ORDER_ID = 'order_id';

    public const SHIPMENT_ID = 'shipment_id';

    public const PRODUCT_VARIANT_ID = 'product_variant_id';

    public const SUPPLIER_USER_ID = 'supplier_user_id';

    public const FACTORY_ID = 'factory_id';

    public const FACTORY_LOCATION_ID = 'factory_location_id';

    public const PRODUCT_NAME = 'product_name';

    public const VARIANT_SKU = 'variant_sku';

    public const QUANTITY_DETAILS = 'quantity_details';

    public const WEIGHT = 'weight';

    public const QUANTITY = 'quantity';

    public const UNIT_NAME = 'unit_name';

    public const UNIT_PRICE = 'unit_price';

    public const TOTAL_PRICE = 'total_price';

    public const SHIPPING_COST = 'shipping_cost';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::ORDER_ID,
        self::SHIPMENT_ID,
        self::PRODUCT_VARIANT_ID,
        self::SUPPLIER_USER_ID,
        self::FACTORY_ID,
        self::FACTORY_LOCATION_ID,
        self::PRODUCT_NAME,
        self::VARIANT_SKU,
        self::QUANTITY_DETAILS,
        self::WEIGHT,
        self::QUANTITY,
        self::UNIT_NAME,
        self::UNIT_PRICE,
        self::TOTAL_PRICE,
        self::SHIPPING_COST,
    ];

    protected $casts = [
        self::QUANTITY_DETAILS => 'array',
        self::UNIT_PRICE => 'decimal:2',
        self::TOTAL_PRICE => 'decimal:2',
        self::SHIPPING_COST => 'decimal:2',
        self::WEIGHT => 'decimal:3',
        self::QUANTITY => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, self::SHIPMENT_ID);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, self::SUPPLIER_USER_ID);
    }

    // NOTE: do not name this relation factory() — it would collide with the
    // HasFactory trait's static factory() method and break OrderItem::factory().
    public function originFactory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, self::FACTORY_ID);
    }

    public function originFactoryLocation(): BelongsTo
    {
        return $this->belongsTo(FactoryLocation::class, self::FACTORY_LOCATION_ID);
    }

    /**
     * جمع‌بندی تعداد کل اقلام
     */
    public function scopeSumQuantity(Builder $query)
    {
        return $query->sum('quantity');
    }
}
