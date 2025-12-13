<?php

namespace App\Actions\User;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Support\Arr;

class UpdateUserProfileAction
{
    public function execute(User $user, array $data): UserProfile
    {
        // ۱. آپدیت پروفایل عمومی
        $profileData = Arr::only($data, ['first_name', 'last_name', 'father_name', 'shaba_number']);

        $user->profile()->updateOrCreate(
            ['user_id' => $user->id],
            $profileData
        );

        // ۲. آپدیت پروفایل‌های تخصصی بر اساس نقش
        if ($user->hasRole('engineer') && isset($data['engineer_code'])) {
            $user->engineer()->updateOrCreate(
                ['user_id' => $user->id],
                ['code' => $data['engineer_code']]
            );
        }

        if ($user->hasRole('contractor') && isset($data['company_name'])) {
            $user->contractor()->updateOrCreate(
                ['user_id' => $user->id],
                ['company_name' => $data['company_name']]
            );
        }

        return $user->profile;
    }
}
