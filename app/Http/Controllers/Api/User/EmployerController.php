<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployerProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\EmployerProfile;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class EmployerController extends Controller
{
    public function store(StoreEmployerProfileRequest $request)
    {
        if (!EmployerProfile::where([EmployerProfile::USER_ID => Auth::id()])->exists())
            ProfileController::removeProfile();

        EmployerProfile::updateOrCreate(
            [
                EmployerProfile::USER_ID => Auth::id(),
            ],
            [
                EmployerProfile::USER_ID => Auth::id(),
                EmployerProfile::ACTIVITY_PROVINCES => @$request->validated('activity_provinces'),
                EmployerProfile::PROJECT_DONE => @$request->validated('project_done'),
                EmployerProfile::PROJECT_IN_PROGRESS => @$request->validated('project_in_progress'),
            ]);

        return success(new UserResource(User::with('employer')->find(Auth::id())));
    }

}
