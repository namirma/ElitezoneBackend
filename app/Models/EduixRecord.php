<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EduixRecord extends Model
{
    use HasFactory;

    const ID = 'id';

    const USER_ID = 'user_id';

    const NATIONAL_CODE = 'national_code';

    const TRACE_CODE = 'trace_code';

    const NAME = 'name';

    const FAMILY = 'family';

    const FATHER_NAME = 'father_name';

    const ID_NUMBER = 'id_number';

    const BIRTH_DATE = 'birth_date';

    const UNIVERSITY = 'university';

    const FIELD = 'field';

    const COURSE_TYPE = 'course_type';

    const ENTRY_DATE = 'entry_date';

    const GRADUATE_DATE = 'graduate_date';

    const GPA = 'gpa';

    const STATUS = 'status';

    const ADMIN_STATUS = 'admin_status';

    const REJECTION_REASON = 'rejection_reason';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    const STATUS_PENDING = 'pending';

    const STATUS_APPROVED = 'approved';

    const STATUS_REJECTED = 'rejected';

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
