<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $product_id
 * @property int $product_variant_id
 * @property string $role_code
 * @property numeric $final_price
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product $product
 * @property-read ProductVariant $variant
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPriceCache newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPriceCache newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPriceCache query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPriceCache whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPriceCache whereFinalPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPriceCache whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPriceCache whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPriceCache whereProductVariantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPriceCache whereRoleCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPriceCache whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class ProductPriceCache extends Model
{
    use HasFactory;

    public const ID = 'id';

    public const PRODUCT_ID = 'product_id';

    public const PRODUCT_VARIANT_ID = 'product_variant_id';

    public const ROLE_CODE = 'role_code';

    public const FINAL_PRICE = 'final_price';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::PRODUCT_ID,
        self::PRODUCT_VARIANT_ID,
        self::ROLE_CODE,
        self::FINAL_PRICE,
    ];

    protected $casts = [
        self::FINAL_PRICE => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, self::PRODUCT_ID);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, self::PRODUCT_VARIANT_ID);
    }
}
