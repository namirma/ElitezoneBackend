<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Wishlist extends Model
{
    use HasFactory;

    const
        ID = 'id',
        USER_ID = 'user_id',
        NAME = 'name',
        IS_DEFAULT = 'is_default';

    protected $fillable = [
        self::USER_ID,
        self::NAME,
        self::IS_DEFAULT,
    ];

    protected $casts = [
        self::IS_DEFAULT => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, self::USER_ID);
    }

    public function variants(): BelongsToMany
    {
        return $this->belongsToMany(ProductVariant::class, 'product_wishlist', 'wishlist_id', 'product_variant_id')
            ->withTimestamps();
    }
}
