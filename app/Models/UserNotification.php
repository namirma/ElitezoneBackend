<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method static where(array $array)
 * @method static create(array $array)
 * @property int $id
 * @property int|null $user_id
 * @property int|null $sender_id
 * @property string|null $subject
 * @property string|null $body
 * @property string|null $read_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNotification newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNotification newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNotification query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNotification whereBody($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNotification whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNotification whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNotification whereReadAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNotification whereSenderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNotification whereSubject($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNotification whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNotification whereUserId($value)
 * @mixin \Eloquent
 */
class UserNotification extends Model
{
    use HasFactory;

    const ID = 'id';

    const USER_ID = 'user_id';

    const SENDER_ID = 'sender_id';

    const BATCH_ID = 'batch_id';

    const SUBJECT = 'subject';

    const BODY = 'body';

    const READ_AT = 'read_at';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        self::USER_ID,
        self::SENDER_ID,
        self::BATCH_ID,
        self::SUBJECT,
        self::BODY,
        self::READ_AT,
    ];

    public function getCreatedAtAttribute()
    {
        $this->appends[] = 'created_at_p';
        $this->appends[] = 'created_at_diff';

        return $this->attributes['created_at'];
    }

    public function getUpdatedAtAttribute()
    {
        $this->appends[] = 'updated_at_p';
        $this->appends[] = 'updated_at_diff';

        return $this->attributes['updated_at'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
