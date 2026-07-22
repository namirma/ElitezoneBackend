<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $national_code
 * @property string $trace_code
 * @property string $name
 * @property string $family
 * @property string|null $father_name
 * @property string|null $id_number
 * @property string|null $birth_date
 * @property string|null $university
 * @property string|null $field
 * @property string|null $course_type
 * @property string|null $entry_date
 * @property string|null $graduate_date
 * @property string|null $gpa
 * @property string|null $status
 * @property string $admin_status
 * @property string|null $rejection_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereAdminStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereBirthDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereCourseType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereEntryDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereFamily($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereFatherName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereField($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereGpa($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereGraduateDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereIdNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereNationalCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereRejectionReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereTraceCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereUniversity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereUserId($value)
 *
 * @mixin \Eloquent
 */
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
