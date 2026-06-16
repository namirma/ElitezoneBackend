<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\StoreEmployerProfileRequest;
use App\Http\Resources\Profile\UserResource;
use App\Models\EmployerProfile;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Support\Facades\Auth;

class EmployerController extends Controller
{
    public function store(StoreEmployerProfileRequest $request)
    {
        $user = Auth::user();
        // Completing the employer profile must grant the `employer` Laratrust role,
        // otherwise the role-gated endpoints (GET /profile/consultants, /invite,
        // DELETE /consultants/{id}) return 403. setUserType already attaches it via
        // SyncUserRolesAction; this is the second safety net for users whose persona
        // was set without the role (legacy/edge data). Mirrors EngineerController:
        // only attach when the user is actually of the employer type.
        $employerTypeId = UserType::where('slug', 'employer')->value('id');
        if ($user->type_id == $employerTypeId && ! $user->hasRole('employer')) {
            $user->addRole('employer');
        }

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
}
