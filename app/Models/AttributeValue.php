<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $attribute_id
 * @property string $value
 * @property string $display_value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Attribute $attribute
 * @property-read Collection<int, ProductVariant> $productVariants
 * @property-read int|null $product_variants_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeValue newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeValue newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeValue query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeValue whereAttributeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeValue whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeValue whereDisplayValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeValue whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeValue whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeValue whereValue($value)
 *
 * @mixin \Eloquent
 */
class AttributeValue extends Model
{
    public const ID = 'id';

    public const ATTRIBUTE_ID = 'attribute_id';

    public const VALUE = 'value';

    public const DISPLAY_VALUE = 'display_value';

    protected $fillable = [
        self::ATTRIBUTE_ID,
        self::VALUE,
        self::DISPLAY_VALUE,
    ];

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    public function productVariants(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductVariant::class,
            'product_variant_attributes',
            'attribute_value_id',
            'product_variant_id'
        );
    }
}
