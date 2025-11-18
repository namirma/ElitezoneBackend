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
     STATUS_PENDING = 'pending',
     STATUS_APPROVED = 'approved',
     STATUS_REJECTED = 'rejected';
}
