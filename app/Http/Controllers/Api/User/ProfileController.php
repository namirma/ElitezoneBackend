<?php

namespace App\Http\Controllers\Api\User;

use App\Actions\Auth\RequestOtpAction;
use App\Actions\Profile\SetUserTypeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangeMobileRequest;
use App\Http\Requests\Auth\StorePasswordRequest;
use App\Http\Requests\Profile\StoreUserProfileRequest;
use App\Http\Requests\Profile\StoreUserTypeRequest;
use App\Http\Requests\Profile\UpdateLegalProfileRequest;
use App\Http\Requests\Profile\UpdateRealProfileRequest;
use App\Http\Requests\Auth\VerifyEmailRequest;
use App\Http\Resources\Profile\UserResource;
use App\Jobs\Notification\SendSmsJob;
use App\Mail\VerifyEmail;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\PodiumClient;
use App\Services\PodiumServices\CompanyInquiryService;
use App\Support\PhoneNumber;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

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

            return success($response, 'اطلاعات شرکت با موفقیت دریافت شد.');
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

    public function setPassword(StorePasswordRequest $request): JsonResponse
    {
        $user = User::findOrFail(Auth::id());
        $user->update([User::PASSWORD => Hash::make($request->validated()[User::PASSWORD])]);

        return success(new UserResource($user), __('auth.password_changed_successfully'));
    }

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
                'apiKey' => (string) config('podium.services.nid_mobile_match.sc_api_key'),
                'productEntityId' => (int) config('podium.services.nid_mobile_match.sc_product_id'),
                'providerParameters' => ['body' => ['nationalCode' => $validated['national_code'], 'mobileNumber' => $newMobile09]],
                'asyncCall' => false,
                'wrapper' => true,
            ];
            $matchRes = PodiumClient::normalize($client->callSc2($matchPayload));
            $matched = data_get($matchRes, 'result.matched');
            $matchCode = (string) data_get($matchRes, 'result.resultCode', '');

            if (! ($matched === true || $matched === 'true' || $matchCode === '1')) {
                return error(__('auth.mobile_nid_mismatch'), [], 422);
            }
        } catch (\Throwable $e) {
            Log::error('Mobile match failed: '.$e->getMessage());
        }

        $code = generateVerifyCode();
        $user->update([
            User::SECRET => $code,
            User::SECRET_EXPIRES_AT => Carbon::now()->addMinutes(2),
        ]);

        // ذخیره شماره جدید در کش موقت
        Cache::put('pending_mobile_change_'.$user->id, $validated['new_mobile'], 120);

        SendSmsJob::dispatch($validated['new_mobile'], $code);

        return success([], __('auth.verification_code_sent_to_new_mobile'));
    }

    public function confirmChangeMobile(Request $request): JsonResponse
    {
        $user = Auth::user();
        $request->validate(['code' => 'required|digits:6']);

        $cachedMobile = Cache::get('pending_mobile_change_'.$user->id);

        if (! $cachedMobile) {
            return error(__('auth.request_expired'), [], 400);
        }

        if ($user->{User::SECRET} != $request->input('code') || Carbon::parse($user->{User::SECRET_EXPIRES_AT})->isPast()) {
            return error(__('auth.verification_code_not_match'), [], 400);
        }

        $user->update([
            User::MOBILE => $cachedMobile,
            User::SECRET => null,
            User::SECRET_EXPIRES_AT => null,
        ]);

        // آپدیت پروفایل
        $user->profile()->update([UserProfile::PHONE => $cachedMobile]);

        Cache::forget(key: 'pending_mobile_change_'.$user->id);

        return success([], __('profile.mobile_updated_successfully'));
    }

    public function confirmUpdateMobile(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (! $user) {
            return error(__('auth.unauthenticated'), [], 401);
        }
        $code = $request->input('code');
        $newMobile = session('pending_mobile_update');
        if (is_null($code) || is_null($newMobile)) {
            return error(__('validation.required'), ['code', 'username'], 422);
        }
        $notExpired = $user->{User::SECRET_EXPIRES_AT} ? ! Carbon::parse($user->{User::SECRET_EXPIRES_AT})->isPast() : false;
        if ((int) ($user->{User::SECRET} ?? 0) === (int) $code && $notExpired) {
            $user->update([User::MOBILE => $newMobile, User::SECRET => null, User::SECRET_EXPIRES_AT => null]);
            session()->forget('pending_mobile_update');

            return success(['mobile' => $newMobile], __('profile.mobileUpdateSuccessfully'), 200);
        }

        return error(__('auth.verification_code_not_match_or_expired'), [], 400);
    }

    public function setEmail(VerifyEmailRequest $request): JsonResponse
    {
        $user = Auth::user();
        if (! $user) {
            return error(__('auth.unauthenticated'), [], 401);
        }
        $token = Str::uuid()->toString();
        $email = $request->validated()['email'];
        $profile = UserProfile::firstOrCreate(
            [UserProfile::USER_ID => $user->id],
            [UserProfile::NATIONAL_CODE => $user->{User::NATIONAL_CODE}, UserProfile::BIRTHDAY => $user->{User::BIRTH_DATE} ? Carbon::parse($user->{User::BIRTH_DATE})->format('Y-m-d') : null]
        );
        $profile->update([UserProfile::EMAIL => $email, UserProfile::EMAIL_VERIFIED_AT => null, UserProfile::EMAIL_VERIFICATION_TOKEN => $token]);
        try {
            Mail::to($email)->send(new VerifyEmail($token));

            return success(['email' => $email], __('global.verificationEmailSentSuccessfully'));
        } catch (\Throwable $e) {
            Log::error("Failed to send verification email to {$email}: ".$e->getMessage());

            return error(__('profile.email_send_failed'), [], 500);
        }
    }

    public function checkToken(Request $request): JsonResponse
    {
        $token = $request->input('token');
        if (! $token) {
            return error(__('validation.required'), ['token'], 422);
        }
        $profile = UserProfile::where([UserProfile::EMAIL_VERIFICATION_TOKEN], $token)->whereNull(UserProfile::EMAIL_VERIFIED_AT)->first();
        if ($profile) {
            $profile->update([UserProfile::EMAIL_VERIFIED_AT => Carbon::now(), UserProfile::EMAIL_VERIFICATION_TOKEN => null]);

            return success(['email' => $profile->{UserProfile::EMAIL}], __('global.emailVerifiedSuccessfully'));
        }

        return error(__('global.invalid_verification_token'), [], 400);
    }
}
