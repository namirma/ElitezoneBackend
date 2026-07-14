<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\StoreEngineerProfileRequest;
use App\Http\Resources\Profile\UserResource;
use App\Models\EngineerProfile;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Support\Facades\Auth;

class EngineerController extends Controller
{
    public function store(StoreEngineerProfileRequest $request)
    {
        $user = Auth::user();
        // The seeded slug for the technical-eliteYAR type is 'expert' (UserTypeSeeder),
        // not 'consultant' — the old value made $expertTypeId null and never attached the role.
        $expertTypeId = UserType::where('slug', 'expert')->value('id');
        if ($user->type_id == $expertTypeId && ! $user->hasRole('engineer')) {
            $user->addRole('engineer');
        }
        // Load the existing profile so partial updates don't overwrite unsubmitted fields
        // with null. $this->engineer_member etc. were non-existent controller properties
        // that always evaluated to null (same data-loss bug fixed in EmployerController).
        $existing = EngineerProfile::where(EngineerProfile::USER_ID, Auth::id())->first();

        EngineerProfile::updateOrCreate(
            [
                EngineerProfile::USER_ID => Auth::id(),
            ],
            [
                EngineerProfile::ENGINEER_MEMBER => $request->validated('engineer_member') ?? $existing?->engineer_member ?? false,
                // is_saze90_user is NOT written here — it is backend-determined by
                // SyncUserBadgeAction from the saze90 lookup (read-only for the frontend).
                EngineerProfile::LICENSE_MEMBER => $request->validated('license_number') ?? $existing?->license_number,
                EngineerProfile::SUPERVISION_BASE => $request->validated('supervision_base') ?? $existing?->supervision_base,
                EngineerProfile::DESIGN_BASE => $request->validated('design_base') ?? $existing?->design_base,
                EngineerProfile::MEMBER_NUMBER => $request->validated('member_number') ?? $existing?->member_number ?? false,
                EngineerProfile::FIELDS_OF_STUDY => $request->validated('fields_of_study') ?? $existing?->fields_of_study,
                EngineerProfile::STUDENT => $request->validated('student') ?? $existing?->student ?? false,
                EngineerProfile::UNIVERSITY_NAME => $request->validated('university_name') ?? $existing?->university_name,
                EngineerProfile::PROVINCE_ID => $request->validated('province_id') ?? $existing?->province_id,
                EngineerProfile::ACTIVITY_PROVINCE_ID => $request->validated('activity_province_id') ?? $existing?->activity_province_id,
                EngineerProfile::CITY_ID => $request->validated('city_id') ?? $existing?->city_id,
                EngineerProfile::ACTIVITY_CITY_ID => $request->validated('activity_city_id') ?? $existing?->activity_city_id,
            ]
        );

        return success(new UserResource(User::with('engineer')->find(Auth::id())));
    }
}
