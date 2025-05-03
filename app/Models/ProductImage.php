<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    use HasFactory;

    public const
        ID = 'id',
        PRODUCT_ID = 'product_id',
        IMAGE_PATH = 'image_path',
        IS_PRIMARY = 'is_primary',
        SORT_ORDER = 'sort_order',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::PRODUCT_ID,
        self::IMAGE_PATH,
        self::IS_PRIMARY,
        self::SORT_ORDER,
    ];

    protected $casts = [
        self::IS_PRIMARY => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
