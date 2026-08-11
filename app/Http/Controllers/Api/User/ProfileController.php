<?php

namespace App\Http\Controllers\Api\User;

use App\Actions\Profile\SetUserTypeAction;
use App\Actions\Profile\SyncUserBadgeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\StoreUserProfileRequest;
use App\Http\Requests\Profile\StoreUserTypeRequest;
use App\Http\Requests\Profile\UpdateLegalProfileRequest;
use App\Http\Requests\Profile\UpdateRealProfileRequest;
use App\Http\Requests\Profile\UpdateUserProfileRequest;
use App\Http\Resources\Profile\BadgeResource;
use App\Http\Resources\Profile\UserResource;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\UserType;
use App\Services\PodiumServices\CompanyInquiryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ProfileController extends Controller
{
    public function getProfile(Request $request, SyncUserBadgeAction $badgeSync): JsonResponse
    {
        $user = Auth::user();
        if (! $user) {
            return error(__('auth.no_user_found'), [], 404);
        }

        // On-read badge sync so is_saze90_user / is_verified / the badge pivot are correct on
        // the FIRST request after login — no separate /badge call, no refresh, no sync race.
        // The saze90 lookup is cached (members 30 min, non-members 5 min), so this is a cache
        // read on the vast majority of profile loads. This response is now the single source
        // of truth for the badge state (badge, is_pioneer, is_saze90_user, badges).
        $badge = $badgeSync->execute($user);

        $user->load([
            User::RELATION_PROFILE,
            User::RELATION_ROLES.':id,name',
            User::RELATION_TYPE,
            User::RELATION_BADGE,
        ]);

        $roleNames = $user->roles->pluck('name')->map(fn ($n) => strtolower($n))->unique()->values();
        $relationsToLoad = [];

        $typeSlug = strtolower((string) optional($user->{User::RELATION_TYPE})->{UserType::SLUG});

        // نقشِ `elite_yar` لحظهٔ انتخابِ نقش وصل می‌شود، مستقل از اینکه مدارک
        // تأیید شده باشد یا نه — پس یک سیگنال هر دو حالت را می‌پوشاند و دیگر
        // لازم نیست type.slug هم خوانده شود.
        //
        // (شاخهٔ supplier پایین‌تر هنوز type.slug را می‌خواند و این عمدی است —
        // آنجا نقش فقط با تأیید ادمین وصل می‌شود، پس متقاضیِ در انتظار یا ردشده
        // هیچ نقشی ندارد و بدون type.slug وضعیتِ خودش را نمی‌بیند.)
        $isEliteYar = $roleNames->contains('elite_yar');

        if ($roleNames->contains('employer')) {
            $relationsToLoad[] = User::RELATION_EMPLOYER;
        }
        if ($roleNames->contains('supplier')) {
            $relationsToLoad[] = User::RELATION_SUPPLIER;
        }

        if ($isEliteYar) {
            // پروفایل‌های مهندس/پیمانکار برای ارزیابی is_verified در UserResource لود می‌شوند.
            // اگر کاربر هنوز مدرکی آپلود نکرده باشد این relationها null خواهند بود (بی‌خطر).
            $relationsToLoad[] = User::RELATION_ENGINEER;
            $relationsToLoad[] = User::RELATION_CONTRACTOR;
            $relationsToLoad[] = User::RELATION_EDUIX_RECORDS;
            $relationsToLoad[] = User::RELATION_JOB_EXPERIENCES;
        }

        if (! empty($relationsToLoad)) {
            $user->load(array_unique($relationsToLoad));
        }

        // Merge the badge summary into the profile payload so the frontend has one source of
        // truth at session load (no separate /api/profile/badge round-trip needed).
        $payload = (new UserResource($user))->resolve($request);
        $payload['badge'] = $badge['badge'];
        $payload['is_pioneer'] = $badge['is_pioneer'];
        $payload['is_saze90_user'] = $badge['is_saze90_user'];
        $payload['badges'] = BadgeResource::collection($badge['badges'])->resolve($request);

        return success($payload, __('global.received_successfully'));
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
                'key' => 'elite_yar',
                'name' => __('auth.elite_yar'),
                'backend_roles' => ['elite_yar'],
            ],
            [
                'key' => 'elite_yar_and_employer',
                'name' => __('auth.elite_yar_and_employer'),
                'backend_roles' => ['elite_yar', 'employer'],
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
            Log::warning('Company inquiry failed', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return error(__('profile.company_inquiry_failed'), [], 503);
        }
    }

    /**
     * General-purpose profile update (PUT /profile).
     *
     * Accepts any subset of profile fields. Locked fields (name, family,
     * national_code, birthday) can only be written the first time — once set,
     * subsequent requests silently preserve the existing value so they cannot
     * be changed via this endpoint.
     */
    public function updateProfile(UpdateUserProfileRequest $request): JsonResponse
    {
        $user = Auth::user();
        $profile = $user->profile()->firstOrCreate([UserProfile::USER_ID => $user->id]);
        $data = $request->validated();

        $locked = [UserProfile::NAME, UserProfile::FAMILY, UserProfile::NATIONAL_CODE, UserProfile::BIRTHDAY];

        foreach ($data as $key => $value) {
            if (in_array($key, $locked, true) && ! is_null($profile->{$key})) {
                // Locked field already has a value — do not overwrite.
                continue;
            }
            $profile->{$key} = $value;
        }

        $profile->save();

        return success(new UserResource($user->fresh('profile')), __('profile.updated_successfully'));
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
