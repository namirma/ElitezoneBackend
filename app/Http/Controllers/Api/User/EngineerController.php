<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEngineerProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\EngineerProfile;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class EngineerController extends Controller
{
    public function store(StoreEngineerProfileRequest $request)
    {
        if (!EngineerProfile::where([EngineerProfile::USER_ID => Auth::id()])->exists())
            ProfileController::removeProfile();

        EngineerProfile::updateOrCreate(
            [
                EngineerProfile::USER_ID => Auth::id(),
            ],
            [
                EngineerProfile::USER_ID => Auth::id(),
                EngineerProfile::ENGINEER_MEMBER => @$request->validated('engineer_member'),
                EngineerProfile::LICENSE_MEMBER => @$request->validated('license_number'),
                EngineerProfile::SUPERVISION_BASE => @$request->validated('supervision_base'),
                EngineerProfile::DESIGN_BASE => @$request->validated('design_base'),
                EngineerProfile::MEMBER_NUMBER => @$request->validated('member_number'),
                EngineerProfile::FIELDS_OF_STUDY => @$request->validated('fields_of_study'),
                EngineerProfile::STUDENT => @$request->validated('student'),
                EngineerProfile::UNIVERSITY_NAME => @$request->validated('university_name'),
                EngineerProfile::PROVINCE_ID => @$request->validated('province_id'),
                EngineerProfile::CITY_ID => @$request->validated('city_id'),
            ]);

        return success(new UserResource(User::with('engineer')->find(Auth::id())));
    }
}
