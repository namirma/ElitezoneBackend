<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Search extends Model
{
    use HasFactory;

    const
        ID = 'id',
        QUERY = 'query',
        RESULTS_COUNT = 'results_count',
        USER_ID = 'user_id',
        IP_ADDRESS = 'ip_address',
        USER_AGENT = 'user_agent',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';

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
