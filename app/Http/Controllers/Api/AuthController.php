<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\RequestOtpAction;
use App\Actions\Auth\SyncIdentityToProfileAction;
use App\Actions\Auth\VerifyIdentityWithPodiumAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreUserRequest;
use App\Http\Resources\Profile\UserResource;
use App\Jobs\Notification\SendSmsJob;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\UserType;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /**
     * قفل تجمعی تأیید کد — مکمل throttle دقیقه‌ای روت، چون کد ۶ رقمی
     * با IPهای متعدد قابل brute-force است.
     */
    private function isOtpLocked(string $mobile): bool
    {
        return Cache::has("otp_lock:{$mobile}");
    }

    private function recordOtpFailure(string $mobile): void
    {
        $failKey = "otp_fails:{$mobile}";
        $lockMinutes = (int) config('auth.otp_lockout_minutes', 10);

        $fails = Cache::get($failKey, 0) + 1;
        Cache::put($failKey, $fails, now()->addMinutes($lockMinutes));

        if ($fails >= (int) config('auth.otp_max_attempts', 5)) {
            Cache::put("otp_lock:{$mobile}", true, now()->addMinutes($lockMinutes));
            Cache::forget($failKey);
        }
    }

    private function clearOtpFailures(string $mobile): void
    {
        Cache::forget("otp_fails:{$mobile}");
        Cache::forget("otp_lock:{$mobile}");
    }

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
            $customerType = UserType::firstOrCreate(
                ['slug' => 'customer'],
                ['name' => 'مشتری', 'icon' => 'user'],
            );
            $user = User::create([
                User::MOBILE => $mobile,
                User::NATIONAL_CODE => $nationalCode,
                User::BIRTH_DATE => $birthDate,
                User::TYPE_ID => $customerType->id,
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
                    __('auth.profile_incomplete'),
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

        if ($this->isOtpLocked($mobile)) {
            return error(__('auth.too_many_otp_attempts'), [], 429);
        }

        // Load user by mobile only — comparing the OTP inside a SQL WHERE clause leaks
        // timing information via DB query latency. hash_equals() runs in constant time.
        $user = User::where(User::MOBILE, $mobile)->first();

        $storedSecret = (string) ($user?->{User::SECRET} ?? '');
        $submittedCode = (string) ($request->validated('code') ?? '');

        if (
            ! $user
            || ! $user->{User::SECRET}
            || ! hash_equals($storedSecret, $submittedCode)
            || Carbon::parse($user->{User::SECRET_EXPIRES_AT})->isPast()
        ) {
            $this->recordOtpFailure($mobile);

            return error(__('auth.verification_code_not_match'), [], 404);
        }

        $this->clearOtpFailures($mobile);

        $createdAt = $user->created_at instanceof Carbon ? $user->created_at : Carbon::parse($user->created_at);
        $isNewUser = $user->wasRecentlyCreated || $createdAt->diffInMinutes(now()) < 2;

        if ($request->has('forget_password') && $request->validated('forget_password')) {
            $user->update([User::SECRET_EXPIRES_AT => null]);

            return success([
                'reset_token' => $user->{User::SECRET},
            ], __('auth.code_verified_proceed_to_password'));
        }

        // کد یکبارمصرف است — بلافاصله پس از مصرف باطل می‌شود تا در پنجره‌ی انقضا قابل replay نباشد
        $user->update([
            User::SECRET => null,
            User::SECRET_EXPIRES_AT => null,
        ]);

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
        $mobile = PhoneNumber::to09($request->input('username'));

        if ($this->isOtpLocked($mobile)) {
            return error(__('auth.too_many_otp_attempts'), [], 429);
        }

        $user = User::where(User::MOBILE, $mobile)->first();

        if (! $user) {
            $this->recordOtpFailure($mobile);

            return error(__('auth.no_user_found'), [], 404);
        }
        if (empty($user->{User::NATIONAL_CODE}) || empty($user->{User::BIRTH_DATE})) {
            return error(
                __('auth.profile_incomplete'),
                ['action' => 'register', 'mobile' => $user->{User::MOBILE}],
                404
            );
        }
        if (! Hash::check($request->input('password'), $user->password)) {
            $this->recordOtpFailure($mobile);

            return error(__('auth.password_error'), [], 404);
        }

        $this->clearOtpFailures($mobile);
        Auth::login($user);
        $user->tokens()->delete();
        $token = $user->createToken('auth')->plainTextToken;

        $userWithRelations = $user->fresh([
            User::RELATION_PROFILE,
            User::RELATION_TYPE,
            User::RELATION_ROLES,
            User::RELATION_BADGE,
        ]);

        return success(['user' => new UserResource($userWithRelations), 'token' => $token], __('auth.login_success'));
    }

    public function forgetPassword(Request $request)
    {
        $request->validate([
            'username' => 'required|exists:users,mobile',
        ]);

        $mobile = PhoneNumber::to09($request->input('username'));

        $user = User::where(User::MOBILE, $mobile)->where(User::PASSWORD, '!=', null)->first();
        if (! is_null($user)) {
            app(RequestOtpAction::class)->execute($user, $mobile, 3);

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

        $mobile = PhoneNumber::to09($request->input('username'));
        if ($this->isOtpLocked($mobile)) {
            return error(__('auth.too_many_otp_attempts'), [], 429);
        }

        // Load user by mobile only — comparing the code inside a SQL WHERE clause leaks
        // timing information via DB query latency; hash_equals() runs in constant time.
        $user = User::where(User::MOBILE, $mobile)
            ->where(User::PASSWORD, '!=', null)
            ->first();

        if (! $user || ! hash_equals((string) ($user->{User::SECRET} ?? ''), (string) $request->input('code'))) {
            $this->recordOtpFailure($mobile);

            return error(__('auth.invalid_reset_token'), [], 400);
        }
        $this->clearOtpFailures($mobile);
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
