<?php

namespace App\Http\Controllers\Api\User;

use App\Actions\Auth\RequestOtpAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangeMobileRequest;
use App\Http\Requests\Auth\StorePasswordRequest;
use App\Http\Requests\Auth\VerifyEmailRequest;
use App\Http\Resources\Profile\UserResource;
use App\Jobs\Notification\SendSmsJob;
use App\Mail\VerifyEmail;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\PodiumClient;
use App\Support\PhoneNumber;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Handles account credential & contact-info changes:
 * password, mobile number, email address.
 *
 * Extracted from ProfileController to keep that class focused on
 * profile data (name, legal info, role selection).
 */
class AccountSecurityController extends Controller
{
    // ─────────────────────────────────────────────────────────────
    // Password
    // ─────────────────────────────────────────────────────────────

    /**
     * Step 1 of password change: send OTP to the user's registered mobile.
     * Call this before POST /profile/password.
     */
    public function changePasswordFirstAttempt(RequestOtpAction $otpAction): JsonResponse
    {
        $user = User::findOrFail(Auth::id());

        $notExpired = $user->{User::SECRET_EXPIRES_AT}
            ? ! Carbon::parse($user->{User::SECRET_EXPIRES_AT})->isPast()
            : false;

        if ($notExpired) {
            return success([], __('auth.code_sent_recently'), 202);
        }

        $otpAction->execute($user, $user->{User::MOBILE});

        return success([], __('auth.change_password_first_attempt'), 200);
    }

    /**
     * Step 2 of password change: set the new password.
     */
    public function setPassword(StorePasswordRequest $request): JsonResponse
    {
        $user = User::findOrFail(Auth::id());
        $user->update([User::PASSWORD => Hash::make($request->validated()[User::PASSWORD])]);

        return success(new UserResource($user), __('auth.password_changed_successfully'));
    }

    // ─────────────────────────────────────────────────────────────
    // Mobile number
    // ─────────────────────────────────────────────────────────────

    /**
     * GET pending mobile-change status.
     * Returns the new number waiting for OTP confirmation, if any.
     */
    public function updateMobile(): JsonResponse
    {
        $user = Auth::user();
        $pending = Cache::get('pending_mobile_change_'.$user->id);

        return success([
            'pending_mobile' => $pending ?? null,
        ], __('global.received_successfully'));
    }

    /**
     * Step 1 of mobile change: verify identity via Podium NID-mobile match,
     * then send OTP to the new number.
     */
    public function requestChangeMobile(ChangeMobileRequest $request): JsonResponse
    {
        $user = Auth::user();
        $validated = $request->validated();

        $currentProfile = $user->profile;
        if (
            ! $currentProfile ||
            $currentProfile->{UserProfile::NATIONAL_CODE} !== $validated['national_code'] ||
            $currentProfile->{UserProfile::BIRTHDAY} !== $validated['birth_date']
        ) {
            return error(__('auth.identity_mismatch'), [], 403);
        }

        try {
            $client = app(PodiumClient::class);
            $newMobile09 = PhoneNumber::to09($validated['new_mobile']);

            $matchPayload = [
                'apiKey'            => (string) config('podium.services.nid_mobile_match.sc_api_key'),
                'productEntityId'   => (int) config('podium.services.nid_mobile_match.sc_product_id'),
                'providerParameters' => ['body' => ['nationalCode' => $validated['national_code'], 'mobileNumber' => $newMobile09]],
                'asyncCall'         => false,
                'wrapper'           => true,
            ];

            $matchRes = PodiumClient::normalize($client->callSc2($matchPayload));
            $matched  = data_get($matchRes, 'result.matched');
            $matchCode = (string) data_get($matchRes, 'result.resultCode', '');

            if (! ($matched === true || $matched === 'true' || $matchCode === '1')) {
                return error(__('auth.mobile_nid_mismatch'), [], 422);
            }
        } catch (\Throwable $e) {
            Log::error('Mobile match failed: '.$e->getMessage());
        }

        $code = generateVerifyCode();
        $user->update([
            User::SECRET            => $code,
            User::SECRET_EXPIRES_AT => Carbon::now()->addMinutes(2),
        ]);

        Cache::put('pending_mobile_change_'.$user->id, $validated['new_mobile'], 120);

        SendSmsJob::dispatch($validated['new_mobile'], $code);

        return success([], __('auth.verification_code_sent_to_new_mobile'));
    }

    /**
     * Step 2 of mobile change: confirm OTP and commit the new number.
     */
    public function confirmChangeMobile(Request $request): JsonResponse
    {
        $user = Auth::user();
        $request->validate(['code' => 'required|digits:6']);

        $cachedMobile = Cache::get('pending_mobile_change_'.$user->id);

        if (! $cachedMobile) {
            return error(__('auth.request_expired'), [], 400);
        }

        if (
            $user->{User::SECRET} != $request->input('code') ||
            Carbon::parse($user->{User::SECRET_EXPIRES_AT})->isPast()
        ) {
            return error(__('auth.verification_code_not_match'), [], 400);
        }

        $user->update([
            User::MOBILE            => $cachedMobile,
            User::SECRET            => null,
            User::SECRET_EXPIRES_AT => null,
        ]);

        $user->profile()->update([UserProfile::PHONE => $cachedMobile]);

        Cache::forget('pending_mobile_change_'.$user->id);

        return success([], __('profile.mobile_updated_successfully'));
    }

    // ─────────────────────────────────────────────────────────────
    // Email address
    // ─────────────────────────────────────────────────────────────

    /**
     * Set / change email — sends a verification link to the new address.
     */
    public function setEmail(VerifyEmailRequest $request): JsonResponse
    {
        $user = Auth::user();
        $token = Str::uuid()->toString();
        $email = $request->validated()['email'];

        $profile = UserProfile::firstOrCreate(
            [UserProfile::USER_ID => $user->id],
            [
                UserProfile::NATIONAL_CODE => $user->{User::NATIONAL_CODE},
                UserProfile::BIRTHDAY      => $user->{User::BIRTH_DATE}
                    ? Carbon::parse($user->{User::BIRTH_DATE})->format('Y-m-d')
                    : null,
            ]
        );

        $profile->update([
            UserProfile::EMAIL                    => $email,
            UserProfile::EMAIL_VERIFIED_AT        => null,
            UserProfile::EMAIL_VERIFICATION_TOKEN => $token,
        ]);

        try {
            Mail::to($email)->send(new VerifyEmail($token));

            return success(['email' => $email], __('global.verificationEmailSentSuccessfully'));
        } catch (\Throwable $e) {
            Log::error("Failed to send verification email to {$email}: ".$e->getMessage());

            return error(__('profile.email_send_failed'), [], 500);
        }
    }

    /**
     * Verify the email token that was sent by setEmail().
     */
    public function checkToken(Request $request): JsonResponse
    {
        $token = $request->input('token');
        if (! $token) {
            return error(__('validation.required'), ['token'], 422);
        }

        $profile = UserProfile::where([UserProfile::EMAIL_VERIFICATION_TOKEN], $token)
            ->whereNull(UserProfile::EMAIL_VERIFIED_AT)
            ->first();

        if ($profile) {
            $profile->update([
                UserProfile::EMAIL_VERIFIED_AT        => Carbon::now(),
                UserProfile::EMAIL_VERIFICATION_TOKEN => null,
            ]);

            return success(['email' => $profile->{UserProfile::EMAIL}], __('global.emailVerifiedSuccessfully'));
        }

        return error(__('global.invalid_verification_token'), [], 400);
    }
}
