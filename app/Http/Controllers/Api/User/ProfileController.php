<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePasswordRequest;
use App\Http\Requests\StoreUserProfileRequest;
use App\Http\Requests\StoreUserTypeRequest;
use App\Http\Requests\UpdateMobileRequest;
use App\Http\Requests\UpdateUserProfileRequest;
use App\Http\Resources\UserMessageResource;
use App\Http\Resources\UserResource;
use App\Models\ContractorProfile;
use App\Models\EmployerProfile;
use App\Models\EngineerProfile;
use App\Models\Role;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\UserProfile;
use App\Services\SmsService;
use \Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    public function getProfile(): JsonResponse
    {
        return success(new UserResource(User::with(['profile', 'engineer', 'contractor', 'supplier', 'employer'])->find(Auth::id())));
    }

    public function setProfile(StoreUserProfileRequest $request): JsonResponse
    {
        UserProfile::updateOrCreate(
            [
                UserProfile::USER_ID => Auth::id()
            ],

            [
            /* Personal information */
                $request->validated()
//            UserProfile::USER_ID => Auth::id(),
//            UserProfile::NAME => @$request->validated('name'),
//            UserProfile::FAMILY => @$request->validated('family'),
//            UserProfile::EMAIL => @$request->validated('email'),
//            UserProfile::NATIONAL_CODE => @$request->validated('national_code'),
//            UserProfile::BIRTHDAY => @$request->validated('birthday'),
//            /* Company information */
//            UserProfile::COMPANY => @$request->validated('company'),
//            UserProfile::ECONOMIC_CODE => @$request->validated('economic_code'),
//            UserProfile::NATIONAL_ID => @$request->validated('national_id'),
//            UserProfile::REGISTER_ID => @$request->validated('register_id'),
//            UserProfile::PHONE => @$request->validated('phone'),
//            UserProfile::PROVINCE_ID => @$request->validated('province_id'),
//            UserProfile::CITY_ID => @$request->validated('city_id'),
//            UserProfile::ENTITY => @$request->validated('entity', 'real'),
        ]);

        return success(new UserResource(User::with('profile')->find(Auth::id())));
    }

    public function updateProfile(UpdateUserProfileRequest $request)
    {
        $profile = UserProfile::where([UserProfile::USER_ID => Auth::id()])->first();
        $profile->update($request->validated());
        return success(new UserResource(User::with('profile')->find(Auth::id())));
    }

    public function setUserType(StoreUserTypeRequest $request): JsonResponse
    {
        $user = User::find(Auth::id());
        $role = Role::where('id', $user->type_id)->first();
        if ($role)
            $user->removeRole($role);

        if (@$user->type_id)
            self::removeProfile();

        $user->update([User::TYPE_ID => $request->validated('type_id')]);
        $user->addRole($request->validated('type_id'));

        return success(new UserResource($user->load('profile')), __('profile.userType'));
    }

    public function setPassword(StorePasswordRequest $request)
    {
        $user = User::find(Auth::id());
        $user->update([User::PASSWORD => Hash::make($request->validated('password'))]);

        return success(new UserResource($user->load('profile')), __('auth.password_changed_successfully'));
    }

    public function changePasswordFirstAttempt()
    {
        $user = User::find(Auth::id());
        $mobileVerifyCode = generateVerifyCode();
        if (!Carbon::parse(@$user->secret_expires_at)->isPast())
            return success($user, __('auth.code_sent_recently'), 202);

        User::where([User::ID => Auth::id()])->update([
            User::SECRET => $mobileVerifyCode,
            User::SECRET_EXPIRES_AT => Carbon::now()->addMinutes(2)
        ]);

        (new SmsService())->send($user->mobile, $mobileVerifyCode);

        return success(new UserResource($user->load('profile')), __('auth.change_password_first_attempt'));
    }

    public function updateMobile()
    {
        $user = Auth::user();
        $mobileVerifyCode = generateVerifyCode();

        if (!Carbon::parse(@$user->secret_expires_at)->isPast())
            return success($user, __('auth.code_sent_recently'), 202);

        User::where([User::ID => Auth::id()])->update([
            User::SECRET => $mobileVerifyCode,
            User::SECRET_EXPIRES_AT => Carbon::now()->addMinutes(2)
        ]);

        (new SmsService())->send($user->mobile, $mobileVerifyCode);
        return success([], __('auth.verification_code_sent_successfully'), 202);
    }

    public function verifyCode(UpdateMobileRequest $request): JsonResponse
    {
        $user = Auth::user();

        if ((int)$user->secret === $request->validated('code') &&
            !Carbon::parse(@$user->secret_expires_at)->isPast() && $request->has('username')) {

            $mobileVerifyCode = generateVerifyCode();
            (new SmsService())->send($request->validated('username'), $mobileVerifyCode);
            User::where([User::ID => Auth::id()])->update([
                User::SECRET => $mobileVerifyCode,
                User::SECRET_EXPIRES_AT => Carbon::now()->addMinutes(2)
            ]);

            return success([], __('auth.verification_code_sent_successfully'), 202);
        }

        return error(__('auth.verification_code_not_match'), [], 404);
    }

    public function confirmUpdateMobile(Request $request)
    {
        $user = Auth::user();
        if ((int)$user->secret === $request->input('code') &&
            !Carbon::parse(@$user->secret_expires_at)->isPast() && $request->has('username')) {

            DB::table('users')
                ->where(['id' => $user->id])
                ->update(['mobile' => $request->input('username'), 'secret' => null]);

            return success([], __('profile.mobileUpdateSuccessfully'), 202);
        }

        return error(__('auth.verification_code_not_match'), [], 404);

    }

    public static function removeProfile(): void
    {
        ContractorProfile::where([ContractorProfile::USER_ID => Auth::id()])->delete();
        EmployerProfile::where([EmployerProfile::USER_ID => Auth::id()])->delete();
        EngineerProfile::where([EngineerProfile::USER_ID => Auth::id()])->delete();
        SupplierProfile::where([SupplierProfile::USER_ID => Auth::id()])->delete();
    }

    public function getMessages()
    {
        return success(UserMessageResource::collection(
            UserNotification::where([UserNotification::USER_ID => Auth::id()])->get())
        );
    }

    public function updateMessageStatus(UserNotification $userNotification)
    {
        $userNotification->update([UserNotification::READ_AT => Carbon::now()->toDateTimeString()]);

        return success(new UserMessageResource($userNotification));
    }

    public function updateAllMessageStatus()
    {
        UserNotification::where([UserNotification::USER_ID => Auth::id()])
            ->update([UserNotification::READ_AT => Carbon::now()->toDateTimeString()]);

        return success([]);
    }
}
