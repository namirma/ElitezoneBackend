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
use App\Mail\VerifyEmail;
use App\Models\ContractorProfile;
use App\Models\EmployerProfile;
use App\Models\EngineerProfile;
use App\Models\Role;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\UserProfile;
use App\Services\PricingRoleResolver;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProfileController extends Controller
{
    public function getProfile(): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'کاربر یافت نشد.'], 404);
        }

        $user->load(['profile', 'roles:id,name']);
        $roleNames = $user->roles->pluck('name')->map(fn ($n) => strtolower($n))->unique()->values();

        $relations = [];
        if ($roleNames->contains('engineer'))   $relations[] = 'engineer';
        if ($roleNames->contains('contractor')) $relations[] = 'contractor';
        if ($roleNames->contains('supplier'))   $relations[] = 'supplier';
        if ($roleNames->contains('employer'))   $relations[] = 'employer';
        if ($relations) $user->load($relations);

        $resolvedTypeId = $user->type_id ?? $this->resolveTypeIdFromRoleNames($roleNames->all());
        if (is_null($user->type_id) && $resolvedTypeId && Schema::hasColumn('users', User::TYPE_ID)) {
            $user->forceFill([User::TYPE_ID => $resolvedTypeId])->save();
        }

        $payload = [
            'type_id'         => $resolvedTypeId,
            'mobile'          => $user->mobile,
            'created_at'      => $user->created_at instanceof Carbon ? $user->created_at->format('Y-m-d H:i:s') : $user->created_at,
            'password'        => false,
            'created_at_p'    => $user->created_at_p ?? null,
            'created_at_diff' => $user->created_at_diff ?? null,
            'profile'         => $user->profile,
            'engineer'        => $roleNames->contains('engineer')   ? $user->engineer   : null,
            'contractor'      => $roleNames->contains('contractor') ? $user->contractor : null,
            'supplier'        => $roleNames->contains('supplier')   ? $user->supplier   : null,
            'employer'        => $roleNames->contains('employer')   ? $user->employer   : null,
            'roles'           => $roleNames->values()->all(),
        ];

        return success($payload, 'اطلاعات با موفقیت دریافت شد.');
    }

    public function setProfile(StoreUserProfileRequest $request): JsonResponse
    {
        $data    = $request->validated();
        $profile = UserProfile::firstOrCreate([UserProfile::USER_ID => Auth::id()]);

        $profile->update([
            UserProfile::USER_ID       => Auth::id(),
            UserProfile::NAME          => $data['name']          ?? $profile->name,
            UserProfile::FAMILY        => $data['family']        ?? $profile->family,
            UserProfile::EMAIL         => $data['email']         ?? $profile->email,
            UserProfile::NATIONAL_CODE => $data['national_code'] ?? $profile->national_code,
            UserProfile::BIRTHDAY      => $data['birthday']      ?? $profile->birthday,
            UserProfile::COMPANY       => $data['company']       ?? $profile->company,
            UserProfile::ECONOMIC_CODE => $data['economic_code'] ?? $profile->economic_code,
            UserProfile::NATIONAL_ID   => $data['national_id']   ?? $profile->national_id,
            UserProfile::REGISTER_ID   => $data['register_id']   ?? $profile->register_id,
            UserProfile::PHONE         => $data['phone']         ?? $profile->phone,
            UserProfile::PROVINCE_ID   => $data['province_id']   ?? $profile->province_id,
            UserProfile::CITY_ID       => $data['city_id']       ?? $profile->city_id,
            UserProfile::ENTITY        => $data['entity']        ?? $profile->entity,
        ]);

        return success(new UserResource(User::with('profile')->find(Auth::id())));
    }

    public function updateProfile(UpdateUserProfileRequest $request): JsonResponse
    {
        $profile = UserProfile::where(UserProfile::USER_ID, Auth::id())->firstOrFail();
        $profile->update($request->validated());
        return success(new UserResource(User::with('profile')->find(Auth::id())));
    }

    public function setUserType(StoreUserTypeRequest $request): JsonResponse
    {
        $user      = User::findOrFail(Auth::id());
        $validated = $request->validated();

        if (!empty($validated['roles']) && is_array($validated['roles'])) {
            $normalizedRoles = collect($validated['roles'])
                ->map(fn ($r) => strtolower(trim((string) $r)))
                ->filter()
                ->unique()
                ->values();

            if ($normalizedRoles->contains('supplier') && $normalizedRoles->count() > 1) {
                return error('validation_error', ['roles' => ['supplier نمی‌تواند همزمان با نقش‌های دیگر باشد.']], 422);
            }

            DB::transaction(function () use ($user, $normalizedRoles, $validated) {
                $roleIds = $normalizedRoles->map(function (string $name) {
                    return Role::firstOrCreate(
                        ['name' => $name],
                        ['display_name' => ucfirst($name), 'description' => null]
                    )->id;
                })->values()->all();

                if (method_exists($user, 'syncRoles')) {
                    $user->syncRoles($roleIds);
                } else {
                    $user->roles()->sync($roleIds);
                }

                $this->purgeObsoleteProfiles($user, $normalizedRoles);

                if ($normalizedRoles->contains('employer') && array_key_exists('employer_has_consultant', $validated)) {
                    EmployerProfile::updateOrCreate(
                        [EmployerProfile::USER_ID => $user->id],
                        [EmployerProfile::HAS_CONSULTANT => (bool) $validated['employer_has_consultant']]
                    );
                }

                if (Schema::hasColumn('users', User::TYPE_ID)) {
                    $resolvedTypeId = $this->resolveTypeIdFromRoleNames($normalizedRoles->all());
                    $user->forceFill([User::TYPE_ID => $resolvedTypeId])->save();
                }
            });

            $user = $user->fresh(['profile', 'roles:id,name', 'employer']);

            return success([
                'user'                              => new UserResource($user),
                'roles'                             => $user->roles->pluck('name')->values()->all(),
                'active_price_filter_category_code' => app(PricingRoleResolver::class)->resolve($user),
            ], __('profile.userType'));
        }

        DB::transaction(function () use ($user, $validated) {
            if (!empty($user->type_id)) {
                if (method_exists($user, 'removeRole')) {
                    $prev = Role::where('id', $user->type_id)->first();
                    if ($prev) $user->removeRole($prev);
                }
                self::removeProfile();
            }

            $user->update([User::TYPE_ID => $validated['type_id'] ?? null]);

            if (!empty($validated['type_id'])) {
                if (method_exists($user, 'addRole')) {
                    $user->addRole($validated['type_id']);
                } else {
                    $role = Role::find($validated['type_id']);
                    if ($role) {
                        $user->roles()->syncWithoutDetaching([$role->id]);
                    }
                }
            }
        });

        $user->load(['profile', 'roles:id,name']);
        return success(new UserResource($user), __('profile.userType'));
    }

    public function setPassword(StorePasswordRequest $request): JsonResponse
    {
        $user = User::findOrFail(Auth::id());
        $user->update([User::PASSWORD => Hash::make($request->validated()['password'])]);
        return success(new UserResource($user->load('profile')), __('auth.password_changed_successfully'));
    }

    public function changePasswordFirstAttempt(): JsonResponse
    {
        $user = User::findOrFail(Auth::id());
        $code = generateVerifyCode();

        $notExpired = $user->secret_expires_at ? !Carbon::parse($user->secret_expires_at)->isPast() : false;
        if ($notExpired) {
            return success(new UserResource($user->load('profile')), __('auth.code_sent_recently'), 202);
        }

        User::where(User::ID, $user->id)->update([
            User::SECRET            => $code,
            User::SECRET_EXPIRES_AT => Carbon::now()->addMinutes(2),
        ]);

        (new SmsService())->send($user->mobile, $code);

        return success(new UserResource($user->load('profile')), __('auth.change_password_first_attempt'));
    }

    public function updateMobile(): JsonResponse
    {
        $user = Auth::user();
        $code = generateVerifyCode();

        $notExpired = $user->secret_expires_at ? !Carbon::parse($user->secret_expires_at)->isPast() : false;
        if ($notExpired) {
            return success([], __('auth.code_sent_recently'), 202);
        }

        User::where(User::ID, $user->id)->update([
            User::SECRET            => $code,
            User::SECRET_EXPIRES_AT => Carbon::now()->addMinutes(2),
        ]);

        (new SmsService())->send($user->mobile, $code);

        return success([], __('auth.verification_code_sent_successfully'), 202);
    }

    public function verifyCode(UpdateMobileRequest $request): JsonResponse
    {
        $user = Auth::user();
        $data = $request->validated();

        $notExpired = $user->secret_expires_at ? !Carbon::parse($user->secret_expires_at)->isPast() : false;

        if (
            (int)($user->secret ?? 0) === (int)$data['code'] &&
            $notExpired &&
            !empty($data['username'])
        ) {
            $code = generateVerifyCode();
            (new SmsService())->send($data['username'], $code);

            User::where(User::ID, $user->id)->update([
                User::SECRET            => $code,
                User::SECRET_EXPIRES_AT => Carbon::now()->addMinutes(2),
            ]);

            return success([], __('auth.verification_code_sent_successfully'), 202);
        }

        return error(__('auth.verification_code_not_match'), [], 404);
    }

    public function confirmUpdateMobile(Request $request): JsonResponse
    {
        $user     = Auth::user();
        $code     = $request->input('code');
        $username = $request->input('username');

        $notExpired = $user->secret_expires_at ? !Carbon::parse($user->secret_expires_at)->isPast() : false;

        if (
            (int)($user->secret ?? 0) === (int)$code &&
            $notExpired &&
            !empty($username)
        ) {
            DB::table('users')->where('id', $user->id)->update([
                'mobile'            => $username,
                'secret'            => null,
                'secret_expires_at' => null,
            ]);

            return success([], __('profile.mobileUpdateSuccessfully'), 202);
        }

        return error(__('auth.verification_code_not_match'), [], 404);
    }

    public static function removeProfile(): void
    {
        $uid = Auth::id();
        ContractorProfile::where(ContractorProfile::USER_ID, $uid)->delete();
        EmployerProfile::where(EmployerProfile::USER_ID, $uid)->delete();
        EngineerProfile::where(EngineerProfile::USER_ID, $uid)->delete();
        SupplierProfile::where(SupplierProfile::USER_ID, $uid)->delete();
    }

    public function getMessages(): JsonResponse
    {
        $items = UserNotification::where(UserNotification::USER_ID, Auth::id())->latest()->get();
        return success(UserMessageResource::collection($items));
    }

    public function updateMessageStatus(UserNotification $userNotification): JsonResponse
    {
        $userNotification->update([UserNotification::READ_AT => Carbon::now()->toDateTimeString()]);
        return success(new UserMessageResource($userNotification));
    }

    public function updateAllMessageStatus(): JsonResponse
    {
        UserNotification::where(UserNotification::USER_ID, Auth::id())
            ->update([UserNotification::READ_AT => Carbon::now()->toDateTimeString()]);
        return success([]);
    }

    public function setEmail(VerifyEmailRequest $request): JsonResponse
    {
        $token = Str::uuid()->toString();

        $profile = UserProfile::firstOrCreate([UserProfile::USER_ID => Auth::id()]);
        $profile->update([
            UserProfile::EMAIL                    => $request->validated()['email'],
            UserProfile::EMAIL_VERIFIED_AT        => null,
            UserProfile::EMAIL_VERIFICATION_TOKEN => $token,
        ]);

        Mail::to($request->validated()['email'])->send(new VerifyEmail($token));

        return success([], 'global.verificationEmailSentSuccessfully');
    }

    public function checkToken(Request $request): JsonResponse
    {
        $profile = UserProfile::where([
            UserProfile::EMAIL_VERIFICATION_TOKEN => $request->input('token'),
            UserProfile::EMAIL_VERIFIED_AT        => null,
        ])->first();

        if ($profile) {
            $profile->update([UserProfile::EMAIL_VERIFIED_AT => Carbon::now()]);
            return success([], 'global.emailVerifiedSuccessfully');
        }

        return error('global.notFound', [], 404);
    }

    private function purgeObsoleteProfiles(User $user, Collection $roleNames): void
    {
        $keep = $roleNames->map(fn ($r) => strtolower($r))->unique()->values()->all();

        if (!in_array('engineer', $keep, true)) {
            EngineerProfile::where(EngineerProfile::USER_ID, $user->id)->delete();
        }
        if (!in_array('contractor', $keep, true)) {
            ContractorProfile::where(ContractorProfile::USER_ID, $user->id)->delete();
        }
        if (!in_array('supplier', $keep, true)) {
            SupplierProfile::where(SupplierProfile::USER_ID, $user->id)->delete();
        }
        if (!in_array('employer', $keep, true)) {
            EmployerProfile::where(EmployerProfile::USER_ID, $user->id)->delete();
        }
    }

    private function resolveTypeIdFromRoleNames(array $roleNames): ?int
    {
        $priority = ['engineer', 'contractor', 'employer', 'supplier'];
        $primary  = collect($priority)->first(fn ($r) => in_array($r, $roleNames, true));
        if (!$primary) return null;

        return DB::table('user_types')->where('slug', $primary)->value('id')
            ?: DB::table('user_types')->where('name', ucfirst($primary))->value('id');
    }
}
