<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePasswordRequest;
use App\Http\Requests\StoreUserProfileRequest;
use App\Http\Requests\StoreUserTypeRequest;
use App\Http\Requests\UpdateMobileRequest;
use App\Http\Requests\UpdateUserProfileRequest;
use App\Http\Requests\VerifyEmailRequest;
use App\Http\Resources\UserMessageResource;
use App\Http\Resources\UserResource;
use App\Mail\TestEmail;
use App\Mail\VerifyEmail;
use App\Services\PricingRoleResolver; // ← اضافه
use Illuminate\Support\Facades\Schema;
use App\Models\ContractorProfile;
use App\Models\EmployerProfile;
use App\Models\EngineerProfile;
use App\Models\Role;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\UserProfile;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ProfileController extends Controller
{
    public function getProfile(): JsonResponse
    {
        //        $response = Http::get('https://saze90.com/check-certificate?username=IsaM');
        //        dd($response);

        return success(new UserResource(User::with(['profile', 'engineer', 'contractor', 'supplier', 'employer'])->find(Auth::id())));
    }

    public function setProfile(StoreUserProfileRequest $request): JsonResponse
    {
        $profile = UserProfile::firstOrCreate([UserProfile::USER_ID => Auth::id()]);

        $profile->update([
            /* Personal information */
            UserProfile::USER_ID => Auth::id(),
            UserProfile::NAME => $request->validated('name') ?? $this->name ?? null,
            UserProfile::FAMILY => $request->validated('family') ?? $profile->family ?? null,
            UserProfile::EMAIL => $request->validated('email') ?? $profile->email ?? null,
            UserProfile::NATIONAL_CODE => $request->validated('national_code') ?? $profile->national_code,
            UserProfile::BIRTHDAY => $request->validated('birthday') ?? $profile->birthday ?? null,

            /* Company information */
            UserProfile::COMPANY => $request->validated('company') ?? $profile->company ?? null,
            UserProfile::ECONOMIC_CODE => $request->validated('economic_code') ?? $profile->economic_code ?? null,
            UserProfile::NATIONAL_ID => $request->validated('national_id') ?? $profile->national_id ?? null,
            UserProfile::REGISTER_ID => $request->validated('register_id') ?? $profile->register_id ?? null,
            UserProfile::PHONE => $request->validated('phone') ?? $profile->phone ?? null,

            /* Location and other info */
            UserProfile::PROVINCE_ID => $request->validated('province_id') ?? $profile->province_id ?? null,
            UserProfile::CITY_ID => $request->validated('city_id') ?? $profile->city_id ?? null,
            UserProfile::ENTITY => $request->validated('entity') ?? $profile->entity,
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
        $user = User::findOrFail(Auth::id());
        $validated = $request->validated();

        if (!empty($validated['roles'])) {
            $aliasMap = [
                'engineer'   => 'engineer',
                'contractor' => 'contractor',
                'employer'   => 'employer',
                'supplier'   => 'supplier',
            ];

            $roleNames = collect($validated['roles'])
                ->map(fn($r) => $aliasMap[strtolower($r)] ?? null)
                ->filter()
                ->unique()
                ->values();

            if ($roleNames->isEmpty()) {
                return error('validation_error', ['roles' => ['at least one valid role is required']], 422);
            }

            DB::transaction(function () use ($user, $roleNames, $validated) {
                $roleIds = $roleNames
                    ->map(fn(string $name) => Role::firstOrCreate(
                        ['name' => $name],
                        ['display_name' => ucfirst($name), 'description' => null]
                    )->id)
                    ->values()
                    ->all();

                if (method_exists($user, 'syncRoles')) {
                    $user->syncRoles($roleIds);
                } else {
                    $user->roles()->sync($roleIds);
                }

                if ($roleNames->contains('employer') && array_key_exists('employer_has_consultant', $validated)) {
                    EmployerProfile::updateOrCreate(
                        [EmployerProfile::USER_ID => $user->id],
                        [EmployerProfile::HAS_CONSULTANT => (bool) $validated['employer_has_consultant']]
                    );
                }

                if (Schema::hasColumn('users', User::TYPE_ID)) {
                    $priority = ['engineer', 'contractor', 'employer', 'supplier'];
                    $primary = collect($priority)->first(fn($p) => $roleNames->contains($p));
                    $typeId = null;

                    if ($primary) {
                        $typeId = DB::table('user_types')->where('slug', $primary)->value('id')
                            ?? DB::table('user_types')->where('name', ucfirst($primary))->value('id');
                    }

                    $user->forceFill([User::TYPE_ID => $typeId])->save();
                }
            });

            $user->refresh();

            return success([
                'user'  => new UserResource($user->load('profile')),
                'roles' => $user->roles()->pluck('name')->values()->all(),
                'active_price_filter_category_code' => app(PricingRoleResolver::class)->resolve($user),
            ], __('profile.userType'));
        }

        $previousRole = Role::where('id', $user->type_id)->first();
        if ($previousRole) {
            $user->removeRole($previousRole);
        }

        if (!empty($user->type_id) && method_exists($this, 'removeProfile')) {
            self::removeProfile();
        }

        $user->update([User::TYPE_ID => $validated['type_id'] ?? null]);

        if (!empty($validated['type_id'])) {
            $user->addRole($validated['type_id']);
        }

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

        if (
            (int)$user->secret === $request->validated('code') &&
            ($user->secret_expires_at && !Carbon::parse($user->secret_expires_at)->isPast()) && $request->has('username')
        ) {

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
        if (
            (int)$user->secret === $request->input('code') &&
            ($user->secret_expires_at && !Carbon::parse($user->secret_expires_at)->isPast()) && $request->has('username')
        ) {

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
        return success(
            UserMessageResource::collection(
                UserNotification::where([UserNotification::USER_ID => Auth::id()])->get()
            )
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

    public function setEmail(VerifyEmailRequest $request)
    {
        $token = Str::uuid();

        $userProfile = UserProfile::firstOrCreate(
            [UserProfile::USER_ID => Auth::id()]
        );

        $userProfile->update([
            UserProfile::EMAIL => $request->validated('email'),
            UserProfile::EMAIL_VERIFIED_AT => null,
            UserProfile::EMAIL_VERIFICATION_TOKEN => $token
        ]);

        Mail::to($request->validated('email'))->send(new VerifyEmail($token));

        return success([], 'global.verificationEmailSentSuccessfully');
    }

    public function checkToken(Request $request)
    {
        $profile = UserProfile::where([
            UserProfile::EMAIL_VERIFICATION_TOKEN => $request->input('token'),
            UserProfile::EMAIL_VERIFIED_AT => null
        ])->first();

        if ($profile) {
            $profile->update([UserProfile::EMAIL_VERIFIED_AT => Carbon::now()]);
            return success([], 'global.emailVerifiedSuccessfully');
        }

        return error('global.notFound', [], 404);
    }
}
