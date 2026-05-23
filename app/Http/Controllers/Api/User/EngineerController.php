<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\StoreEngineerProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\EngineerProfile;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Support\Facades\Auth;

class EngineerController extends Controller
{
    public function store(StoreEngineerProfileRequest $request)
    {
        $user = Auth::user();
        $expertTypeId = UserType::where('slug', 'consultant')->value('id');
        if ($user->type_id == $expertTypeId && ! $user->hasRole('engineer')) {
            $user->addRole('engineer');
        }
        // if (!EngineerProfile::where([EngineerProfile::USER_ID => Auth::id()])->exists())
        //     ProfileController::removeProfile();

        EngineerProfile::updateOrCreate(
            [
                EngineerProfile::USER_ID => Auth::id(),
            ],
            [
                EngineerProfile::ENGINEER_MEMBER => $request->validated('engineer_member') ?? $this->engineer_member ?? false,
                EngineerProfile::LICENSE_MEMBER => $request->validated('license_number') ?? $this->license_number ?? null,
                EngineerProfile::SUPERVISION_BASE => $request->validated('supervision_base') ?? $this->supervision_base ?? null,
                EngineerProfile::DESIGN_BASE => $request->validated('design_base') ?? $this->design_base ?? null,
                EngineerProfile::MEMBER_NUMBER => $request->validated('member_number') ?? $this->member_number ?? false,
                EngineerProfile::FIELDS_OF_STUDY => $request->validated('fields_of_study') ?? $this->fields_of_study ?? null,
                EngineerProfile::STUDENT => $request->validated('student') ?? $this->student ?? false,
                EngineerProfile::UNIVERSITY_NAME => $request->validated('university_name') ?? $this->university_name ?? null,
                EngineerProfile::PROVINCE_ID => $request->validated('province_id') ?? $this->province_id ?? null,
                EngineerProfile::ACTIVITY_PROVINCE_ID => $request->validated('activity_province_id') ?? $this->activity_province_id ?? null,
                EngineerProfile::CITY_ID => $request->validated('city_id') ?? $this->city_id ?? null,
                EngineerProfile::ACTIVITY_CITY_ID => $request->validated('activity_city_id') ?? $this->activity_city_id ?? null,
            ]
        );

        return success(new UserResource(User::with('engineer')->find(Auth::id())));
    }
}
