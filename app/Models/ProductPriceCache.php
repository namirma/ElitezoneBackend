<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property int $product_variant_id
 * @property string $role_code
 * @property string $final_price
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Product $product
 * @property-read \App\Models\ProductVariant $variant
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPriceCache newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPriceCache newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPriceCache query()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPriceCache whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPriceCache whereFinalPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPriceCache whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPriceCache whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPriceCache whereProductVariantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPriceCache whereRoleCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPriceCache whereUpdatedAt($value)
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
