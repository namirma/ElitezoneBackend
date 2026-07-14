<?php

namespace App\Models\Concerns;

use App\Models\EmployerEliteYar;
use App\Models\User;

trait HasSocialRelations
{
    public function technicalEliteYars()
    {
        return $this->belongsToMany(User::class, 'employer_elite_yar', 'employer_id', 'elite_yar_id')
            ->using(EmployerEliteYar::class)
            ->withPivot(['status', 'reject_reason'])
            ->withTimestamps();
    }

    public function associatedEmployers()
    {
        return $this->belongsToMany(User::class, 'employer_elite_yar', 'elite_yar_id', 'employer_id')
            ->using(EmployerEliteYar::class)
            ->withPivot(['status', 'reject_reason'])
            ->withTimestamps();
    }
}
