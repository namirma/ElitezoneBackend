<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property int $employer_id
 * @property int $consultant_id
 * @property string $status
 * @property string|null $reject_reason
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerConsultant newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerConsultant newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerConsultant query()
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerConsultant whereConsultantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerConsultant whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerConsultant whereEmployerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerConsultant whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerConsultant whereRejectReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerConsultant whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerConsultant whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class EmployerConsultant extends Pivot
{
    protected $table = 'employer_consultant';

    public const EMPLOYER_ID = 'employer_id';

    public const CONSULTANT_ID = 'consultant_id';

    public const STATUS = 'status';

    public const REJECT_REASON = 'reject_reason';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';
}
