<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuestionRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Question;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\UserType;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(StoreUserRequest $request): JsonResponse
    {
        $user = User::where([User::MOBILE => $request->validated('username')])->first();

        if ($user && @$user->password)
            return success([], __('auth.login_success'));
        else if ($user && isset($user->secret_expires_at) && !Carbon::parse($user->secret_expires_at)->isPast()) {
            return success($user, __('auth.code_sent_recently'), 202);
        }


        $mobileVerifyCode = generateVerifyCode();

        User::updateOrCreate(
            [
                User::MOBILE => $request->validated('username')
            ],
            [
                User::MOBILE => $request->validated('username'),
                User::SECRET => $mobileVerifyCode,
                User::SECRET_EXPIRES_AT => Carbon::now()->addMinutes(2)
            ]
        );

        (new SmsService())->send($request->validated('username'),
            __('sms.loginMessage', ['code' => $mobileVerifyCode]));

        return success([], __('auth.verification_code_sent_successfully'), 202);
    }

    public function types(): JsonResponse
    {
        return success(UserType::all(), __('global.information_received_successfully'));
    }

    public function verifyCode(StoreUserRequest $request): JsonResponse
    {
        $user = User::where([User::MOBILE => $request->validated('username'), User::SECRET => $request->validated('code')])->first();
//        dd($user);
        if (!Carbon::parse(@$user->secret_expires_at)->isPast()) {

            $createdAt = $user->created_at instanceof Carbon
                ? $user->created_at
                : Carbon::parse($user->created_at);

            $isNewUser = $user->wasRecentlyCreated || $createdAt->diffInMinutes(now()) < 2;

            if ($request->has('forget_password') && $request->validated('forget_password')) {
                return success([]);
            } else {
                Auth::login($user);
                $user->tokens()->delete();
                $token = $user->createToken('auth')->plainTextToken;

                if ($isNewUser) {
                    (new SmsService())->send($request->validated('username'), __('sms.newRegisterUser'));

                    UserNotification::create([
                       UserNotification::USER_ID => Auth::id(),
                       UserNotification::SUBJECT => __('global.newUserNotificationSubject'),
                        UserNotification::BODY => __('global.newUserNotificationBody')
                    ]);
                }


                return success(['user' => new UserResource($user), 'token' => $token], __('auth.register_success'));
            }
        }

        return error(__('auth.verification_code_not_match'), [], 404);
    }

    public function checkPassword(Request $request)
    {
        $user = User::where([User::MOBILE => $request->input('username')])->firstOrFail();

        if (Hash::check($request->input('password'), $user->password)) {
            Auth::login($user);
            $user->tokens()->delete();
            $token = $user->createToken('auth')->plainTextToken;

            return success(['user' => new UserResource($user), 'token' => $token], __('auth.login_success'));
        }

        return error(__('auth.password_error'), [], 404);
    }

    public function forgetPassword(Request $request)
    {
        $request->validate([
            'username' => 'required|exists:users,mobile'
        ]);

        $user = User::where([User::MOBILE => $request->input('username')])->where(User::PASSWORD, '!=', null)->first();
        if (!is_null($user)) {

            $mobileVerifyCode = generateVerifyCode();
            $user->update([
                User::SECRET => $mobileVerifyCode,
                User::SECRET_EXPIRES_AT => Carbon::now()->addMinutes(3)
            ]);

            (new SmsService())->send($request->validated('username'),
                __('sms.loginMessage', ['code' => $mobileVerifyCode]));
            return success([], 'auth.forget_password_code_sent_successfully');
        }

        return error(__('auth.no_user_found'), [], 404);
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'username' => 'required|exists:users,mobile',
            'code' => 'required',
            User::PASSWORD => 'required|confirmed'
        ]);

        $user = User::where([
            User::MOBILE => $request->input('username'),
            User::SECRET => $request->input('code')
        ])
            ->where(User::PASSWORD, '!=', null)
            ->first();

        if (Carbon::parse(@$user->secret_expires_at)->isPast() && is_null($user))
            return error('something went wrong', [], 400);

        $user->update([User::PASSWORD => Hash::make($request->input('password'))]);

        Auth::login($user);
        $user->tokens()->delete();
        $token = $user->createToken('auth')->plainTextToken;
        return success(['user' => new UserResource($user), 'token' => $token], __('auth.password_changed_successfully'));
    }

    public function sendVerificationCode(Request $request)
    {
        $request->validate([
            'username' => 'required|exists:users,mobile'
        ]);

        $user = User::where([User::MOBILE => $request->input('username')])->first();

        $mobileVerifyCode = generateVerifyCode();
        $user->update([
            User::SECRET => $mobileVerifyCode,
            User::SECRET_EXPIRES_AT => Carbon::now()->addMinutes(2)
        ]);

        (new SmsService())->send($request->validated('username'),
            __('sms.loginMessage', ['code' => $mobileVerifyCode]));

        return success([], __('auth.verification_code_sent_successfully'));

    }

}
