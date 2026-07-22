<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $query
 * @property int $results_count
 * @property int|null $user_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Search newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Search newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Search query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Search whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Search whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Search whereIpAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Search whereQuery($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Search whereResultsCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Search whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Search whereUserAgent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Search whereUserId($value)
 *
 * @mixin \Eloquent
 */
class Search extends Model
{
    use HasFactory;

    const ID = 'id';

    const QUERY = 'query';

    const RESULTS_COUNT = 'results_count';

    const USER_ID = 'user_id';

    const IP_ADDRESS = 'ip_address';

    const USER_AGENT = 'user_agent';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::QUERY,
        self::RESULTS_COUNT,
        self::USER_ID,
        self::IP_ADDRESS,
        self::USER_AGENT,
    ];

    protected $casts = [
        self::RESULTS_COUNT => 'integer',
        self::CREATED_AT => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, self::USER_ID);
    }
}
