<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
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
        else if (!Carbon::parse(@$user->secret_expires_at)->isPast())
            return success($user, __('auth.code_sent_recently'), 202);

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

        (new SmsService())->send($request->validated('username'), $mobileVerifyCode);
        return success([], __('auth.verification_code_sent_successfully'), 202);
    }

    public function types(): JsonResponse
    {
        return success(UserType::all(), __('global.information_received_successfully'));
    }

    public function verifyCode(StoreUserRequest $request): JsonResponse
    {
        $user = User::where([User::MOBILE => $request->validated('username'), User::SECRET => $request->validated('code')])->first();

        if (!Carbon::parse(@$user->secret_expires_at)->isPast()) {
            Auth::login($user);
            $user->tokens()->delete();
            $token = $user->createToken('auth')->plainTextToken;
            return success(['user' => new UserResource($user), 'token' => $token], __('auth.register_success'));
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
}
