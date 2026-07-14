<?php

namespace App\Models\Concerns;

use App\Models\EmployerEliteYar;
use App\Models\User;

trait HasSocialRelations
{
    public function technicalEliteYars()
    {
        return $this->belongsToMany(User::class, 'employer_consultant', 'employer_id', 'consultant_id')
            ->using(EmployerEliteYar::class)
            ->withPivot(['status', 'reject_reason'])
            ->withTimestamps();
    }

    public function associatedEmployers()
    {
        return $this->belongsToMany(User::class, 'employer_consultant', 'consultant_id', 'employer_id')
            ->using(EmployerEliteYar::class)
            ->withPivot(['status', 'reject_reason'])
            ->withTimestamps();
    }
}
