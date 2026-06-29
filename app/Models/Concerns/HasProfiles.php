<?php

namespace App\Models\Concerns;

use App\Models\Address;
use App\Models\Badge;
use App\Models\ContractorProfile;
use App\Models\EduixRecord;
use App\Models\EmployerProfile;
use App\Models\EngineerProfile;
use App\Models\JobExperience;
use App\Models\SupplierProfile;
use App\Models\UserBankAccount;
use App\Models\UserProfile;
use App\Models\UserType;

trait HasProfiles
{
    public function profile()
    {
        return $this->hasOne(UserProfile::class);
    }

    public function engineer()
    {
        return $this->hasOne(EngineerProfile::class);
    }

    public function contractor()
    {
        return $this->hasOne(ContractorProfile::class);
    }

    public function supplier()
    {
        return $this->hasOne(SupplierProfile::class);
    }

    public function employer()
    {
        return $this->hasOne(EmployerProfile::class);
    }

    public function type()
    {
        return $this->belongsTo(UserType::class, 'type_id');
    }

    public function badge()
    {
        return $this->belongsTo(Badge::class, 'badge_id');
    }

    public function addresses()
    {
        return $this->hasMany(Address::class, 'user_id');
    }

    public function bankAccounts()
    {
        return $this->hasMany(UserBankAccount::class, 'user_id');
    }

    public function eduixRecords()
    {
        return $this->hasMany(EduixRecord::class, 'user_id');
    }

    public function jobExperiences()
    {
        return $this->hasMany(JobExperience::class, 'user_id');
    }
}
