<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $product_id
 * @property int|null $user_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $session_id
 * @property Carbon $viewed_at
 * @property-read Product $product
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductView newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductView newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductView query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductView whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductView whereIpAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductView whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductView whereSessionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductView whereUserAgent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductView whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductView whereViewedAt($value)
 *
 * @mixin \Eloquent
 */
class ProductView extends Model
{
    use HasFactory;

    protected $table = 'product_views';

    public $timestamps = false;

    /* -------------------------------------------------------------------------- */
    /* Constants (Column Names) */
    /* -------------------------------------------------------------------------- */
    const ID = 'id';

    const PRODUCT_ID = 'product_id';

    const PRODUCT_VARIANT_ID = 'product_variant_id';

    const USER_ID = 'user_id';

    const IP_ADDRESS = 'ip_address';

    const USER_AGENT = 'user_agent';

    const SESSION_ID = 'session_id';

    const VIEWED_AT = 'viewed_at';

    /* -------------------------------------------------------------------------- */
    /* Configuration */
    /* -------------------------------------------------------------------------- */
    protected $fillable = [
        self::PRODUCT_ID,
        self::PRODUCT_VARIANT_ID,
        self::USER_ID,
        self::IP_ADDRESS,
        self::USER_AGENT,
        self::SESSION_ID,
        self::VIEWED_AT,
    ];

    protected $casts = [
        self::PRODUCT_ID => 'integer',
        self::PRODUCT_VARIANT_ID => 'integer',
        self::USER_ID => 'integer',
        self::VIEWED_AT => 'datetime',
    ];

    /* -------------------------------------------------------------------------- */
    /* Relationships */
    /* -------------------------------------------------------------------------- */

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, self::PRODUCT_ID);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, self::USER_ID);
    }
}
