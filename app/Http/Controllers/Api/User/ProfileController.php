<?php

namespace App\Http\Controllers\Api\User;

use App\Actions\Profile\SetUserTypeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\StoreUserProfileRequest;
use App\Http\Requests\Profile\StoreUserTypeRequest;
use App\Http\Requests\Profile\UpdateLegalProfileRequest;
use App\Http\Requests\Profile\UpdateRealProfileRequest;
use App\Http\Resources\Profile\UserResource;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\PodiumServices\CompanyInquiryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

class ProfileController extends Controller
{
    public function getProfile(): JsonResponse
    {
        $user = Auth::user();
        if (! $user) {
            return error(__('auth.no_user_found'), [], 404);
        }

        $user->load([
            User::RELATION_PROFILE,
            User::RELATION_ROLES.':id,name',
            User::RELATION_TYPE,
            User::RELATION_BADGE,
        ]);

        $roleNames = $user->roles->pluck('name')->map(fn ($n) => strtolower($n))->unique()->values();
        $relationsToLoad = [];

        $isExpert = $roleNames->contains('engineer') || $roleNames->contains('contractor');

        if ($roleNames->contains('engineer')) {
            $relationsToLoad[] = User::RELATION_ENGINEER;
        }
        if ($roleNames->contains('contractor')) {
            $relationsToLoad[] = User::RELATION_CONTRACTOR;
        }
        if ($roleNames->contains('employer')) {
            $relationsToLoad[] = User::RELATION_EMPLOYER;
        }
        if ($roleNames->contains('supplier')) {
            $relationsToLoad[] = User::RELATION_SUPPLIER;
        }

        if ($isExpert) {
            $relationsToLoad[] = User::RELATION_EDUIX_RECORDS;
            $relationsToLoad[] = User::RELATION_JOB_EXPERIENCES;
        }

        if (! empty($relationsToLoad)) {
            $user->load($relationsToLoad);
        }

        return success(new UserResource($user), __('global.received_successfully'));
    }

    public function getRoleOptions(): JsonResponse
    {
        $roleOptions = [
            [
                'key' => 'employer',
                'name' => __('auth.employer'),
                'backend_roles' => ['employer'],
            ],
            [
                'key' => 'expert',
                'name' => __('auth.expert'),
                'backend_roles' => ['expert'],
            ],
            [
                'key' => 'expert_and_employer',
                'name' => __('auth.expert_and_employer'),
                'backend_roles' => ['expert', 'employer'],
            ],
            [
                'key' => 'supplier',
                'name' => __('auth.supplier'),
                'backend_roles' => ['supplier'],
            ],
        ];

        return success($roleOptions, __('global.received_successfully'));
    }

    public function setUserType(StoreUserTypeRequest $request, SetUserTypeAction $action): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $newDisplayRoles = collect($request->validated('roles'));

        return $action->execute($user, $newDisplayRoles, $request);
    }

    public function setProfile(StoreUserProfileRequest $request): JsonResponse
    {
        $profile = UserProfile::firstOrCreate([UserProfile::USER_ID => Auth::id()]);
        $data = $request->validated();
        $locked = [UserProfile::NAME, UserProfile::FAMILY, UserProfile::NATIONAL_CODE, UserProfile::BIRTHDAY];
        $mutable = [UserProfile::EMAIL, UserProfile::COMPANY, UserProfile::ECONOMIC_CODE, UserProfile::NATIONAL_ID, UserProfile::REGISTER_ID, UserProfile::PHONE, UserProfile::PROVINCE_ID, UserProfile::CITY_ID, UserProfile::ENTITY];
        $safe = Arr::except($data, $locked);

        $updates = [
            UserProfile::USER_ID => Auth::id(),
            UserProfile::NAME => $profile->{UserProfile::NAME} ?? ($safe[UserProfile::NAME] ?? null),
            UserProfile::FAMILY => $profile->{UserProfile::FAMILY} ?? ($safe[UserProfile::FAMILY] ?? null),
            UserProfile::NATIONAL_CODE => $profile->{UserProfile::NATIONAL_CODE} ?? ($safe[UserProfile::NATIONAL_CODE] ?? null),
            UserProfile::BIRTHDAY => $profile->{UserProfile::BIRTHDAY} ?? ($safe[UserProfile::BIRTHDAY] ?? null),
        ];
        foreach ($mutable as $key) {
            $updates[$key] = array_key_exists($key, $safe) ? $safe[$key] : $profile->{$key};
        }
        foreach ($locked as $key) {
            if (isset($safe[$key]) && $key !== UserProfile::USER_ID) {
                $updates[$key] = $profile->{$key};
            } elseif (is_null($updates[$key]) && ! is_null($profile->{$key})) {
                $updates[$key] = $profile->{$key};
            }
        }

        $profile->update($updates);

        return success(new UserResource(User::with('profile')->find(Auth::id())), __('profile.updated_successfully'));
    }

    public function fetchLegalData(Request $request, CompanyInquiryService $service): JsonResponse
    {
        $request->validate([
            'national_id' => 'required|string',
        ]);

        try {
            $data = $service->fetchByNationalId($request->national_id);

            $raw = $data['company'] ?? $data;

            $response = [

                'company' => $raw['full_name'] ?? '',
                'national_id' => $raw['national_number'] ?? $request->national_id,
                'register_id' => $raw['registration_number'] ?? '',
                'phone' => $raw['phone_number'] ?? '',

                'type' => $raw['type'] ?? '',
                'registration_date' => $raw['registration_date'] ?? '',
                'capital' => $raw['capital'] ?? '',
                'country' => $raw['country'] ?? '',
                'address' => $raw['address'] ?? '',
                'postal_code' => $raw['postal_code'] ?? '',
                'fax_number' => $raw['fax_number'] ?? '',
                'registration_organization' => $raw['registration_organization'] ?? '',
                'status' => $raw['status'] ?? '',
            ];

            return success($response, __('profile.company_info_received'));
        } catch (\Exception $e) {
            return error($e->getMessage(), [], 422);
        }
    }

    public function updateRealProfile(UpdateRealProfileRequest $request): JsonResponse
    {
        $user = Auth::user();
        $profile = $user->profile()->firstOrCreate([UserProfile::USER_ID => $user->id]);
        $data = $request->validated();

        if (isset($data[UserProfile::EMAIL]) && $data[UserProfile::EMAIL] !== $profile->{UserProfile::EMAIL}) {
            $profile->{UserProfile::EMAIL_VERIFIED_AT} = null;
            $profile->{UserProfile::EMAIL_VERIFICATION_TOKEN} = null;
        }

        $profile->fill(array_filter($data));
        $profile->{UserProfile::ENTITY} = 'real';
        $profile->save();

        return success(new UserResource($user->fresh('profile')), __('profile.updated_successfully'));
    }

    public function updateLegalProfile(UpdateLegalProfileRequest $request): JsonResponse
    {
        $user = Auth::user();
        $profile = $user->profile()->firstOrCreate([UserProfile::USER_ID => $user->id]);
        $data = $request->validated();

        $profile->fill($data);
        $profile->{UserProfile::ENTITY} = 'legal';
        $profile->save();

        return success(new UserResource($user->fresh('profile')), __('profile.updated_successfully'));
    }

    // Password, mobile-change, and email management have been moved to
    // AccountSecurityController (same namespace) to keep this controller
    // focused on profile data (name, legal info, role selection).
}
