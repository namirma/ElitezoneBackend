<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property int|null $user_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $session_id
 * @property \Illuminate\Support\Carbon $viewed_at
 * @property-read \App\Models\Product $product
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder|ProductView newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductView newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductView query()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductView whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductView whereIpAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductView whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductView whereSessionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductView whereUserAgent($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductView whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductView whereViewedAt($value)
 * @mixin \Eloquent
 */
class ProductView extends Model
{
    use HasFactory;

    protected $table = 'product_views';

    public $timestamps = false;

    /* -------------------------------------------------------------------------- */
    /* Constants (Column Names)                                                   */
    /* -------------------------------------------------------------------------- */
    const ID = 'id';

    const PRODUCT_ID = 'product_id';

    const USER_ID = 'user_id';

    const IP_ADDRESS = 'ip_address';

    const USER_AGENT = 'user_agent';

    const SESSION_ID = 'session_id';

    const VIEWED_AT = 'viewed_at';

    /* -------------------------------------------------------------------------- */
    /* Configuration                                                              */
    /* -------------------------------------------------------------------------- */
    protected $fillable = [
        self::PRODUCT_ID,
        self::USER_ID,
        self::IP_ADDRESS,
        self::USER_AGENT,
        self::SESSION_ID,
        self::VIEWED_AT,
    ];

    protected $casts = [
        self::PRODUCT_ID => 'integer',
        self::USER_ID => 'integer',
        self::VIEWED_AT => 'datetime',
    ];

    /* -------------------------------------------------------------------------- */
    /* Relationships                                                              */
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
