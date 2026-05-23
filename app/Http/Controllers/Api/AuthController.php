<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\RequestOtpAction;
use App\Actions\Auth\SyncIdentityToProfileAction;
use App\Actions\Auth\VerifyIdentityWithPodiumAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreUserRequest;
use App\Http\Resources\Profile\UserResource;
use App\Jobs\SendSmsJob;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\UserType;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function me(Request $request)
    {
        return success(new UserResource($request->user()));
    }

    public function register(StoreUserRequest $request): JsonResponse
    {
        $mobile = PhoneNumber::to09($request->validated('username'));
        $nationalCode = (string) $request->validated('national_code');
        $birthDate = str_replace('-', '', (string) $request->validated('birth_date'));

        $user = User::where(User::MOBILE, $mobile)->first();

        if ($user && ! empty($user->{User::NATIONAL_CODE})) {
            if ($user->{User::PASSWORD}) {
                return success(['action' => 'login_with_password'], __('auth.user_has_password'));
            }
            if (isset($user->{User::SECRET_EXPIRES_AT}) && ! Carbon::parse($user->{User::SECRET_EXPIRES_AT})->isPast()) {
                return success([], __('auth.code_sent_recently'), 202);
            }
            app(RequestOtpAction::class)->execute($user, $mobile);

            return success([], __('auth.verification_code_sent_successfully'), 202);
        }

        // Delegate Podium identity verification to a dedicated Action
        $verifyAction = app(VerifyIdentityWithPodiumAction::class);
        $errorKey = $verifyAction->execute($mobile, $nationalCode, $birthDate);

        if ($errorKey) {
            $httpStatus = str_contains($errorKey, 'check_failed') ? 502 : 422;

            return error(__($errorKey), [], $httpStatus);
        }

        // Create or update the user record
        if ($user) {
            $user->update([
                User::NATIONAL_CODE => $nationalCode,
                User::BIRTH_DATE => $birthDate,
            ]);
        } else {
            $user = User::create([
                User::MOBILE => $mobile,
                User::NATIONAL_CODE => $nationalCode,
                User::BIRTH_DATE => $birthDate,
                User::TYPE_ID => null,
            ]);
        }

        // Generate OTP and send SMS via dedicated Action
        app(RequestOtpAction::class)->execute($user, $mobile);

        return success([], __('auth.verification_code_sent_successfully'), 202);
    }

    public function checkUserAndSendCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'regex:/^(\+?98|0098|0)?9\d{9}$/'],
        ]);

        $mobile = PhoneNumber::to09($validated['username']);
        $user = User::where(User::MOBILE, $mobile)->first();

        if ($user) {
            if (empty($user->{User::NATIONAL_CODE}) || empty($user->{User::BIRTH_DATE})) {
                return error(
                    '.لطفاً جهت تکمیل اطلاعات کاربری و احراز هویت، مجددا ثبت‌نام کنید',
                    ['action' => 'register', 'mobile' => $mobile],
                    404
                );
            }

            if ($user->{User::PASSWORD}) {
                return success(['action' => 'login_with_password'], __('auth.user_has_password'));
            }

            if (isset($user->{User::SECRET_EXPIRES_AT}) && ! Carbon::parse($user->{User::SECRET_EXPIRES_AT})->isPast()) {
                return success(['action' => 'login_with_code'], __('auth.code_sent_recently'), 202);
            }

            app(RequestOtpAction::class)->execute($user, $mobile);

            return success(['action' => 'login_with_code'], __('auth.verification_code_sent_successfully'));
        } else {
            return error(__('auth.no_user_found_register_required'), ['action' => 'register'], 404);
        }
    }

    public function types(): JsonResponse
    {
        return success(UserType::all(), __('global.information_received_successfully'));
    }

    public function verifyCode(StoreUserRequest $request): JsonResponse
    {
        $mobile = PhoneNumber::to09($request->validated('username'));

        $user = User::where([
            User::MOBILE => $mobile,
            User::SECRET => $request->validated('code'),
        ])->first();

        if (! $user || Carbon::parse(@$user->secret_expires_at)->isPast()) {
            return error(__('auth.verification_code_not_match'), [], 404);
        }

        $createdAt = $user->created_at instanceof Carbon ? $user->created_at : Carbon::parse($user->created_at);
        $isNewUser = $user->wasRecentlyCreated || $createdAt->diffInMinutes(now()) < 2;

        if ($request->has('forget_password') && $request->validated('forget_password')) {
            $user->update([User::SECRET_EXPIRES_AT => null]);

            return success([
                'reset_token' => $user->{User::SECRET},
            ], __('auth.code_verified_proceed_to_password'));
        }

        Auth::login($user);
        $roles = (array) $request->input('roles', []);
        $user = Auth::user();
        foreach ($roles as $role) {
            match ($role) {
                'engineer' => $user->engineer()->firstOrCreate([]),
                'contractor' => $user->contractor()->firstOrCreate([]),
                'employer' => $user->employer()->firstOrCreate([]),
                'supplier' => $user->supplier()->firstOrCreate([]),
                default => null,
            };
        }

        app(SyncIdentityToProfileAction::class)->execute($user);
        $user->tokens()->delete();
        $token = $user->createToken('auth')->plainTextToken;

        if ($isNewUser) {
            SendSmsJob::dispatch($mobile, __('sms.newRegisterUser'));

            UserNotification::create([
                UserNotification::USER_ID => Auth::id(),
                UserNotification::SUBJECT => __('global.newUserNotificationSubject'),
                UserNotification::BODY => __('global.newUserNotificationBody'),
            ]);
        }
        $userWithRelations = $user->fresh([
            User::RELATION_PROFILE,
            User::RELATION_TYPE,
            User::RELATION_ROLES,
            User::RELATION_BADGE,
        ]);

        return success([
            'user' => new UserResource($userWithRelations),
            'token' => $token,
        ], __('auth.register_success'));
    }

    public function checkPassword(Request $request)
    {
        $user = User::where([User::MOBILE => $request->input('username')])->first();

        if (! $user) {
            return error(__('auth.no_user_found'), [], 404);
        }
        if (empty($user->{User::NATIONAL_CODE}) || empty($user->{User::BIRTH_DATE})) {
            return error(
                '.اطلاعات کاربری شما ناقص است. لطفاً جهت تکمیل ثبت‌نام اقدام کنید',
                ['action' => 'register', 'mobile' => $user->{User::MOBILE}],
                404
            );
        }
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
            'username' => 'required|exists:users,mobile',
        ]);

        $user = User::where([User::MOBILE => $request->input('username')])->where(User::PASSWORD, '!=', null)->first();
        if (! is_null($user)) {
            app(RequestOtpAction::class)->execute($user, $request->input('username'), 3);

            return success([], 'auth.forget_password_code_sent_successfully');
        }

        return error(__('auth.no_user_found'), [], 404);
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'username' => 'required|exists:users,mobile',
            'code' => 'required',
            User::PASSWORD => 'required|confirmed',
        ]);
        $user = User::where([
            User::MOBILE => $request->input('username'),
            User::SECRET => $request->input('code'),
        ])
            ->where(User::PASSWORD, '!=', null)
            ->first();
        if ($user === null) {
            return error(__('auth.invalid_reset_token'), [], 400);
        }
        if ($user->{User::SECRET_EXPIRES_AT} !== null) {
            return error(__('auth.code_not_verified_yet'), [], 400);
        }
        $user->update([
            User::PASSWORD => Hash::make($request->input('password')),
            User::SECRET => null,
        ]);
        Auth::login($user);
        $user->tokens()->delete();
        $token = $user->createToken('auth')->plainTextToken;

        return success(['user' => new UserResource($user), 'token' => $token], __('auth.password_changed_successfully'));
    }

    public function sendVerificationCode(Request $request)
    {
        $validated = $request->validate([
            'username' => 'required|exists:users,mobile',
        ], [
            'username.required' => __('auth.username_required'),
            'username.exists' => __('auth.no_user_found'),
        ]);

        $user = User::where([User::MOBILE => $validated['username']])->first();
        if (isset($user->{User::SECRET_EXPIRES_AT}) && ! Carbon::parse($user->{User::SECRET_EXPIRES_AT})->isPast()) {
            return success([], __('auth.code_sent_recently'), 202);
        }

        app(RequestOtpAction::class)->execute($user, $validated['username']);

        return success([], __('auth.verification_code_sent_successfully'));
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return error(__('auth.unauthenticated'), [], 401);
        }
        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        } else {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return success([], __('auth.logout_success'));
    }
}
