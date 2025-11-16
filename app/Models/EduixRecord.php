<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EduixRecord extends Model
{
    use HasFactory;

    const
        ID            = 'id',
        USER_ID       = 'user_id',
        NATIONAL_CODE = 'national_code',
        TRACE_CODE    = 'trace_code',
        NAME          = 'name',
        FAMILY        = 'family',
        FATHER_NAME   = 'father_name',
        ID_NUMBER     = 'id_number',
        BIRTH_DATE    = 'birth_date',
        UNIVERSITY    = 'university',
        FIELD         = 'field',
        COURSE_TYPE   = 'course_type',
        ENTRY_DATE    = 'entry_date',
        GRADUATE_DATE = 'graduate_date',
        GPA           = 'gpa',
        STATUS        = 'status',
        ADMIN_STATUS  = 'admin_status',
        REJECTION_REASON = 'rejection_reason',
        CREATED_AT    = 'created_at',
        UPDATED_AT    = 'updated_at',
        STATUS_PENDING = 'pending',
        STATUS_APPROVED = 'approved',
        STATUS_REJECTED = 'rejected';

    protected $fillable = [
        self::USER_ID,
        self::NATIONAL_CODE,
        self::TRACE_CODE,
        self::NAME,
        self::FAMILY,
        self::FATHER_NAME,
        self::ID_NUMBER,
        self::BIRTH_DATE,
        self::UNIVERSITY,
        self::FIELD,
        self::COURSE_TYPE,
        self::ENTRY_DATE,
        self::GRADUATE_DATE,
        self::GPA,
        self::STATUS,
        self::ADMIN_STATUS,
        self::REJECTION_REASON,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, self::USER_ID);
    }
}
