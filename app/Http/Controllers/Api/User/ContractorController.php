<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContractorProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\ContractorProfile;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class ContractorController extends Controller
{
    public function store(StoreContractorProfileRequest $request)
    {
        if (!ContractorProfile::where([ContractorProfile::USER_ID => Auth::id()])->exists())
            ProfileController::removeProfile();

        ContractorProfile::updateOrCreate(
            [
                ContractorProfile::USER_ID => Auth::id(),
            ],
            [
                ContractorProfile::USER_ID => Auth::id(),
                ContractorProfile::COMPANY_NAME => @$request->validated('company_name'),
                ContractorProfile::NOT_RELATED => @$request->validated('not_related'),
                ContractorProfile::LICENSE_NUMBER => @$request->validated('license_number'),
                ContractorProfile::SUPERVISION_BASE => @$request->validated('supervision_base'),
                ContractorProfile::DESIGN_BASE => @$request->validated('design_base'),
                ContractorProfile::ENGINEER_MEMBER => @$request->validated('engineer_member'),
                ContractorProfile::ACTIVITY_HISTORY => @$request->validated('activity_history'),
                ContractorProfile::ACTIVITY_PROVINCES => @$request->validated('activity_provinces'),
                ContractorProfile::PROJECT_DONE => @$request->validated('project_done'),
                ContractorProfile::PROJECT_IN_PROGRESS => @$request->validated('project_in_progress'),
                ContractorProfile::TRUSTED_CONTRACTOR => @$request->validated('trusted_contractor'),
            ]);

        return success(new UserResource(User::with('contractor')->find(Auth::id())));

    }
}
