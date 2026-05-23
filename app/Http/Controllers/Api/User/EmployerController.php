<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\StoreEmployerProfileRequest;
use App\Http\Resources\Profile\UserResource;
use App\Models\EmployerProfile;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class EmployerController extends Controller
{
    public function store(StoreEmployerProfileRequest $request)
    {
        // if (!EmployerProfile::where([EmployerProfile::USER_ID => Auth::id()])->exists())
        //     ProfileController::removeProfile();

        EmployerProfile::updateOrCreate(
            [
                EmployerProfile::USER_ID => Auth::id(),
            ],
            [
                EmployerProfile::USER_ID => Auth::id(),
                EmployerProfile::ACTIVITY_PROVINCES => $request->validated('activity_provinces') ?? $this->activity_provinces ?? null,
                EmployerProfile::PROJECT_DONE => $request->validated('project_done') ?? $this->project_done ?? null,
                EmployerProfile::PROJECT_IN_PROGRESS => $request->validated('project_in_progress') ?? $this->project_in_progress ?? null,
            ]
        );

        return success(new UserResource(User::with('employer')->find(Auth::id())));
    }

    public function updateConsultant(Request $request)
    {
        $request->validate(['has_consultant' => ['required', 'boolean']]);

        $profile = EmployerProfile::firstOrCreate([EmployerProfile::USER_ID => Auth::id()]);
        $profile->update([EmployerProfile::HAS_CONSULTANT => $request->boolean('has_consultant')]);

        return success(['employer' => $profile->fresh()]);
    }
}
