<?php

namespace App\Models;

use App\Traits\PersianDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
