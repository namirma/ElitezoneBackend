<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method static where(array $array)
 */
class UserNotification extends Model
{
    use HasFactory;

    CONST
        ID = 'id',
        USER_ID = 'user_id',
        SENDER_ID = 'sender_id',
        SUBJECT = 'subject',
        BODY = 'body',
        READ_AT = 'read_at',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';


    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        self::USER_ID,
        self::SENDER_ID,
        self::SUBJECT.
        self::BODY,
        self::READ_AT,
        self::CREATED_AT,
        self::UPDATED_AT
    ];

    public function getCreatedAtAttribute()
    {
        $this->appends[] = "created_at_p";
        $this->appends[] = "created_at_diff";
        return $this->attributes["created_at"];
    }

    public function getUpdatedAtAttribute()
    {
        $this->appends[] = "updated_at_p";
        $this->appends[] = "updated_at_diff";
        return $this->attributes["updated_at"];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

}
