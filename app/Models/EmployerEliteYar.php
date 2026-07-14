<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class EmployerEliteYar extends Pivot
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
