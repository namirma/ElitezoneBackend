<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $keyword
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProfileSearchHistory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProfileSearchHistory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProfileSearchHistory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProfileSearchHistory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProfileSearchHistory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProfileSearchHistory whereKeyword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProfileSearchHistory whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProfileSearchHistory whereUserId($value)
 *
 * @mixin \Eloquent
 */
class ProfileSearchHistory extends Model
{
    use HasFactory;

    const ID = 'id';

    const USER_ID = 'user_id';

    const KEYWORD = 'keyword';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::USER_ID,
        self::KEYWORD,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
