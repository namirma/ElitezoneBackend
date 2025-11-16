<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobExperience extends Model
{
    use HasFactory;
    
    public const
        ID = 'id',
        USER_ID = 'user_id',
        TITLE = 'title',
        ORGANIZATION_NAME = 'organization_name',
        FILE_PATH = 'file_path',
        START_DATE = 'start_date',
        END_DATE = 'end_date',
        STATUS = 'status',
        REJECTION_REASON = 'rejection_reason',
        STATUS_PENDING = 'pending',
        STATUS_APPROVED = 'approved',
        STATUS_REJECTED = 'rejected';

    protected $fillable = [
        self::USER_ID,
        self::TITLE,
        self::ORGANIZATION_NAME,
        self::FILE_PATH,
        self::START_DATE,
        self::END_DATE,
        self::STATUS,
        self::REJECTION_REASON,
    ];

    protected $casts = [
        self::START_DATE => 'date',
        self::END_DATE => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, self::USER_ID);
    }
}
