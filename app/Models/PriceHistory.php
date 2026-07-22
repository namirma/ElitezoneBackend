<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $product_variant_id
 * @property int|null $product_price_id
 * @property int|null $factory_id
 * @property int|null $factory_location_id
 * @property numeric|null $general_price
 * @property numeric|null $special_price
 * @property numeric|null $golden_figure
 * @property int|null $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Factory|null $factory
 * @property-read ProductPrice|null $productPrice
 * @property-read ProductVariant $productVariant
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceHistory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceHistory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceHistory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceHistory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceHistory whereFactoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceHistory whereFactoryLocationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceHistory whereGeneralPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceHistory whereGoldenFigure($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceHistory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceHistory whereProductPriceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceHistory whereProductVariantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceHistory whereSpecialPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceHistory whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceHistory whereUserId($value)
 *
 * @mixin \Eloquent
 */
class PriceHistory extends Model
{
    use HasFactory;

    const ID = 'id';

    const PRODUCT_VARIANT_ID = 'product_variant_id';

    const PRODUCT_PRICE_ID = 'product_price_id';

    const FACTORY_ID = 'factory_id';

    const FACTORY_LOCATION_ID = 'factory_location_id';

    const GENERAL_PRICE = 'general_price';

    const SPECIAL_PRICE = 'special_price';

    const GOLDEN_FIGURE = 'golden_figure';

    const USER_ID = 'user_id';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::PRODUCT_VARIANT_ID,
        self::PRODUCT_PRICE_ID,
        self::FACTORY_ID,
        self::FACTORY_LOCATION_ID,
        self::GENERAL_PRICE,
        self::SPECIAL_PRICE,
        self::GOLDEN_FIGURE,
        self::USER_ID,
    ];

    protected $casts = [
        self::GENERAL_PRICE => 'decimal:2',
        self::SPECIAL_PRICE => 'decimal:2',
        self::GOLDEN_FIGURE => 'decimal:2',
        self::CREATED_AT => 'datetime',
    ];

    /* -------------------- Relationships -------------------- */

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, self::PRODUCT_VARIANT_ID);
    }

    public function productPrice(): BelongsTo
    {
        return $this->belongsTo(ProductPrice::class, self::PRODUCT_PRICE_ID);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, self::USER_ID);
    }

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, self::FACTORY_ID);
    }
}
