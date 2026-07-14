<?php

namespace App\Models;

use App\Traits\PersianDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $admin_id
 * @property string $note
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $admin
 * @property-read string|void $created_at_diff
 * @property-read string|void $created_at_p
 * @property-read string|void $expire_at_days
 * @property-read string|void $updated_at_diff
 * @property-read string|void $updated_at_p
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNote newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNote newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNote query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNote whereAdminId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNote whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNote whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNote whereNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNote whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNote whereUserId($value)
 * @mixin \Eloquent
 */
class UserNote extends Model
{
    use HasFactory, PersianDate;

    public const ID = 'id';

    public const USER_ID = 'user_id';

    public const ADMIN_ID = 'admin_id';

    public const NOTE = 'note';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::USER_ID,
        self::ADMIN_ID,
        self::NOTE,
    ];

    /** The user this note is about. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, self::USER_ID);
    }

    /** The admin who authored this note. */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, self::ADMIN_ID);
    }
}
