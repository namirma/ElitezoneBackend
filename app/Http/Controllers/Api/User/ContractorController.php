<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\StoreContractorProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\ContractorProfile;
use App\Models\User;
use App\Models\UserType; // اضافه شد
use Illuminate\Support\Facades\Auth;

class ContractorController extends Controller
{
    public function store(StoreContractorProfileRequest $request)
    {
        $user = Auth::user();
        $expertTypeId = UserType::where('slug', 'consultant')->value('id');
        if ($user->type_id == $expertTypeId && ! $user->hasRole('contractor')) {
            $user->addRole('contractor');
        }
        // if (!ContractorProfile::where([ContractorProfile::USER_ID => Auth::id()])->exists())
        //     ProfileController::removeProfile();

        ContractorProfile::updateOrCreate(
            [
                ContractorProfile::USER_ID => Auth::id(),
            ],
            [
                ContractorProfile::USER_ID => Auth::id(),
                ContractorProfile::COMPANY_NAME => $request->validated('company_name') ?? $this->company_name ?? null,
                ContractorProfile::NOT_RELATED => $request->validated('not_related') ?? $this->not_related ?? null,
                ContractorProfile::LICENSE_NUMBER => $request->validated('license_number') ?? $this->license_number ?? null,
                ContractorProfile::SUPERVISION_BASE => $request->validated('supervision_base') ?? $this->supervision_base ?? null,
                ContractorProfile::DESIGN_BASE => $request->validated('design_base') ?? $this->design_base ?? null,
                ContractorProfile::ENGINEER_MEMBER => $request->validated('engineer_member') ?? $this->engineer_member ?? false,
                ContractorProfile::ACTIVITY_HISTORY => $request->validated('activity_history') ?? $this->activity_history ?? null,
                ContractorProfile::ACTIVITY_PROVINCES => $request->validated('activity_provinces') ?? $this->activity_provinces ?? null,
                ContractorProfile::PROJECT_DONE => $request->validated('project_done') ?? $this->project_done ?? null,
                ContractorProfile::PROJECT_IN_PROGRESS => $request->validated('project_in_progress') ?? $this->project_in_progress ?? null,
                ContractorProfile::TRUSTED_CONTRACTOR => $request->validated('trusted_contractor') ?? $this->trusted_contractor ?? null,
            ]
        );

        return success(new UserResource(User::with('contractor')->find(Auth::id())));
    }
}
