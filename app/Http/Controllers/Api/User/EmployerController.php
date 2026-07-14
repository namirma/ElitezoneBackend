<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\StoreEmployerProfileRequest;
use App\Http\Resources\Profile\UserResource;
use App\Models\EmployerProfile;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class EmployerController extends Controller
{
    public function destroy(): JsonResponse
    {
        $profile = EmployerProfile::where(EmployerProfile::USER_ID, Auth::id())->first();

        if (! $profile) {
            return error(__('profile.employer_not_found'), [], 404);
        }

        $profile->delete();

        return success(
            new UserResource(User::with('employer')->find(Auth::id())),
            __('profile.employer_deleted')
        );
    }

    public function store(StoreEmployerProfileRequest $request)
    {
        $user = Auth::user();
        // Completing the employer profile must grant the `employer` Laratrust role,
        // otherwise the role-gated endpoints (GET /profile/eliteYARs, /invite,
        // DELETE /eliteYARs/{id}) return 403. setUserType already attaches it via
        // SyncUserRolesAction; this is the second safety net for users whose persona
        // was set without the role (legacy/edge data). Mirrors EngineerController:
        // only attach when the user is actually of the employer type.
        $employerTypeId = UserType::where('slug', 'employer')->value('id');
        if ($user->type_id == $employerTypeId && ! $user->hasRole('employer')) {
            $user->addRole('employer');
        }

        // Load the existing profile so partial updates don't overwrite unsubmitted fields
        // with null. $this->activity_provinces etc. are not controller properties.
        $existing = EmployerProfile::where(EmployerProfile::USER_ID, Auth::id())->first();

        EmployerProfile::updateOrCreate(
            [EmployerProfile::USER_ID => Auth::id()],
            [
                EmployerProfile::USER_ID             => Auth::id(),
                EmployerProfile::ACTIVITY_PROVINCES  => $request->validated('activity_provinces')  ?? $existing?->activity_provinces,
                EmployerProfile::PROJECT_DONE        => $request->validated('project_done')        ?? $existing?->project_done,
                EmployerProfile::PROJECT_IN_PROGRESS => $request->validated('project_in_progress') ?? $existing?->project_in_progress,
            ]
        );

        return success(new UserResource(User::with('employer')->find(Auth::id())));
    }
}
