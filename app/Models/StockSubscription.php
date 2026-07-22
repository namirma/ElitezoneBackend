<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $product_variant_id
 * @property string|null $notified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ProductVariant $productVariant
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockSubscription newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockSubscription newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockSubscription query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockSubscription whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockSubscription whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockSubscription whereNotifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockSubscription whereProductVariantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockSubscription whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockSubscription whereUserId($value)
 *
 * @mixin \Eloquent
 */
class StockSubscription extends Model
{
    public const ID = 'id';

    public const USER_ID = 'user_id';

    public const PRODUCT_VARIANT_ID = 'product_variant_id';

    public const NOTIFIED_AT = 'notified_at';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::USER_ID,
        self::PRODUCT_VARIANT_ID,
        self::NOTIFIED_AT,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, self::USER_ID);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, self::PRODUCT_VARIANT_ID);
    }
}
