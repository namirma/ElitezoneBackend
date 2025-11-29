<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class EmployerConsultant extends Pivot
{
    protected $table = 'employer_consultant';

    public const
     EMPLOYER_ID = 'employer_id',
     CONSULTANT_ID = 'consultant_id',
     STATUS = "status",
     REJECT_REASON = "reject_reason",
     STATUS_PENDING = 'pending',
     STATUS_APPROVED = 'approved',
     STATUS_REJECTED = 'rejected';
}
