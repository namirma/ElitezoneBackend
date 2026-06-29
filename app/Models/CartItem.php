<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
