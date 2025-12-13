<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Attribute extends Model
{
    use HasFactory;

    const
        ID                  = 'id',
        ATTRIBUTE_TYPE_ID   = 'attribute_type_id',
        NAME                = 'name',
        DISPLAY_NAME        = 'display_name',
        CREATED_AT          = 'created_at',
        UPDATED_AT          = 'updated_at';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int,string>
     */
    protected $fillable = [
        self::ATTRIBUTE_TYPE_ID,
        self::NAME,
        self::DISPLAY_NAME,
    ];

    /**
     * Relates this attribute to its AttributeType.
     */
    public function attributeType(): BelongsTo
    {
        return $this->belongsTo(AttributeType::class, self::ATTRIBUTE_TYPE_ID);
    }

    /**
     * The product variants that have this attribute.
     */
    public function productVariants(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductVariant::class,
            'product_variant_attributes',
            'attribute_id',
            'product_variant_id'
        );
    }
}
