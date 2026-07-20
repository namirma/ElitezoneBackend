<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class EmployerEliteYAR extends Pivot
{
    protected $table = 'employer_elite_yar';

    public const EMPLOYER_ID = 'employer_id';

    public const ELITE_YAR_ID = 'elite_yar_id';

    public const STATUS = 'status';

    public const REJECT_REASON = 'reject_reason';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';
}
