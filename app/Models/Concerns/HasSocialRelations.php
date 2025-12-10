<?php

namespace App\Models\Concerns;

use App\Models\EmployerConsultant;
use App\Models\User;

trait HasSocialRelations
{
    public function technicalConsultants()
    {
        return $this->belongsToMany(User::class, 'employer_consultant', 'employer_id', 'consultant_id')
            ->using(EmployerConsultant::class)
            ->withPivot(['status', 'reject_reason'])
            ->withTimestamps();
    }

    public function associatedEmployers()
    {
        return $this->belongsToMany(User::class, 'employer_consultant', 'consultant_id', 'employer_id')
            ->using(EmployerConsultant::class)
            ->withPivot(['status', 'reject_reason'])
            ->withTimestamps();
    }
}
