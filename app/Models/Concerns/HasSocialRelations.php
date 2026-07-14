<?php

namespace App\Models\Concerns;

use App\Models\EmployerEliteYAR;
use App\Models\User;

trait HasSocialRelations
{
    public function technicalEliteYARs()
    {
        return $this->belongsToMany(User::class, 'employer_elite_yar', 'employer_id', 'elite_yar_id')
            ->using(EmployerEliteYAR::class)
            ->withPivot(['status', 'reject_reason'])
            ->withTimestamps();
    }

    public function associatedEmployers()
    {
        return $this->belongsToMany(User::class, 'employer_elite_yar', 'elite_yar_id', 'employer_id')
            ->using(EmployerEliteYAR::class)
            ->withPivot(['status', 'reject_reason'])
            ->withTimestamps();
    }
}
