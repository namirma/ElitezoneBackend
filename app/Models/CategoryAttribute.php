<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $category_id
 * @property int $attribute_type_id
 * @property bool $is_required
 * @property bool $is_filterable
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\AttributeType $attributeType
 * @property-read \App\Models\ProductCategory $category
 * @method static \Illuminate\Database\Eloquent\Builder|CategoryAttribute newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|CategoryAttribute newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|CategoryAttribute query()
 * @method static \Illuminate\Database\Eloquent\Builder|CategoryAttribute whereAttributeTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CategoryAttribute whereCategoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CategoryAttribute whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CategoryAttribute whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CategoryAttribute whereIsFilterable($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CategoryAttribute whereIsRequired($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CategoryAttribute whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CategoryAttribute whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class CategoryAttribute extends Model
{
    use HasFactory;

    const ID = 'id';

    const CATEGORY_ID = 'category_id';

    const ATTRIBUTE_TYPE_ID = 'attribute_type_id';

    const IS_REQUIRED = 'is_required';

    const IS_FILTERABLE = 'is_filterable';

    const SORT_ORDER = 'sort_order';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    protected $table = 'category_attributes';

    public $timestamps = true;

    protected $fillable = [
        self::CATEGORY_ID,
        self::ATTRIBUTE_TYPE_ID,
        self::IS_REQUIRED,
        self::IS_FILTERABLE,
        self::SORT_ORDER,
    ];

    protected $casts = [
        self::IS_REQUIRED => 'boolean',
        self::IS_FILTERABLE => 'boolean',
        self::SORT_ORDER => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, self::CATEGORY_ID, ProductCategory::ID);
    }

    public function attributeType(): BelongsTo
    {
        return $this->belongsTo(AttributeType::class, self::ATTRIBUTE_TYPE_ID, AttributeType::ID);
    }
}
