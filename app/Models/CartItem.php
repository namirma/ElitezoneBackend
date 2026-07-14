<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $cart_id
 * @property int $product_variant_id
 * @property int $factory_id
 * @property int $factory_location_id
 * @property int|null $address_id
 * @property int $shipping_method_id
 * @property array $quantities
 * @property string $weight Total logistics weight in KG
 * @property string $quantity Total quantity count
 * @property string|null $unit_name Snapshot of the unit name (e.g., Bundle, Branch)
 * @property string $price
 * @property string $final_price
 * @property string $vat_amount
 * @property string $total_with_vat
 * @property bool $with_vat
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Address|null $address
 * @property-read \App\Models\Cart $cart
 * @property-read \App\Models\Factory $factory
 * @property-read \App\Models\FactoryLocation $factoryLocation
 * @property-read \App\Models\ProductVariant $productVariant
 * @property-read \App\Models\ShippingMethod $shippingMethod
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem query()
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereAddressId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereCartId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereFactoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereFactoryLocationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereFinalPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem wherePrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereProductVariantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereQuantities($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereShippingMethodId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereTotalWithVat($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereUnitName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereVatAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereWeight($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereWithVat($value)
 * @mixin \Eloquent
 */
class CartItem extends Model
{
    use HasFactory;

    public const ID = 'id';

    public const CART_ID = 'cart_id';

    public const PRODUCT_VARIANT_ID = 'product_variant_id';

    public const FACTORY_ID = 'factory_id';

    public const FACTORY_LOCATION_ID = 'factory_location_id';

    /** Per-line delivery destination (enables multi-destination carts; null = order default). */
    public const ADDRESS_ID = 'address_id';

    public const SHIPPING_METHOD_ID = 'shipping_method_id';

    public const QUANTITIES = 'quantities';

    public const WEIGHT = 'weight';

    public const QUANTITY = 'quantity';

    public const UNIT_NAME = 'unit_name';

    public const PRICE = 'price';

    public const FINAL_PRICE = 'final_price';

    public const VAT_AMOUNT = 'vat_amount';

    public const TOTAL_WITH_VAT = 'total_with_vat';

    public const WITH_VAT = 'with_vat';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::CART_ID,
        self::PRODUCT_VARIANT_ID,
        self::FACTORY_ID,
        self::FACTORY_LOCATION_ID,
        self::ADDRESS_ID,
        self::SHIPPING_METHOD_ID,
        self::QUANTITIES,
        self::WEIGHT,
        self::QUANTITY,
        self::UNIT_NAME,

        self::PRICE,
        self::FINAL_PRICE,
        self::VAT_AMOUNT,
        self::TOTAL_WITH_VAT,
        self::WITH_VAT,
    ];

    protected $casts = [
        self::QUANTITIES => 'array',
        self::WITH_VAT => 'boolean',
        self::WEIGHT => 'decimal:3',
        self::QUANTITY => 'decimal:2',
        self::PRICE => 'decimal:2',
        self::FINAL_PRICE => 'decimal:2',
        self::VAT_AMOUNT => 'decimal:2',
        self::TOTAL_WITH_VAT => 'decimal:2',
    ];

    // ... (روابط تغییری نکردند) ...
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class);
    }

    public function factoryLocation(): BelongsTo
    {
        return $this->belongsTo(FactoryLocation::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }
}
