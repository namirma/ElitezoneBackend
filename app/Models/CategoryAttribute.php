<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
