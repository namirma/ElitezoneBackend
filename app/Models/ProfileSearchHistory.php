<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
