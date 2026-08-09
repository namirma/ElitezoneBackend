<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminRequest;
use App\Http\Requests\Admin\UpdateAdminRequest;
use App\Http\Resources\Profile\UserResource;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * پنل مدیریت — مدیران سیستم (ماژول ۷ نیازمندی فرانت BackOffice).
 *
 * destroy کاربر را حذف نمی‌کند — فقط نقش admin را جدا می‌کند (حذف کاربر با سوابق
 * مالی/سفارش غیرقابل برگشت است). خود-حذفی و خود-غیرفعال‌سازی مجاز نیست.
 */
class AdminManagerController extends Controller
{
    // GET /admin/admins
    public function index(Request $request): JsonResponse
    {
        $query = User::query()
            ->whereHas('roles', fn ($r) => $r->where('name', 'admin'))
            // permissions هم لود می‌شود چون UserResource برای هر ردیف (همه admin) آن را
            // می‌خواند — بدون این، هر ردیف یک کوئری جدا می‌زد (N+1).
            ->with(['profile', 'roles', 'permissions'])
            ->orderByDesc(User::ID);

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(fn ($q) => $q
                ->where(User::MOBILE, 'like', "%{$search}%")
                ->orWhereHas('profile', fn ($p) => $p
                    ->where(UserProfile::NAME, 'like', "%{$search}%")
                    ->orWhere(UserProfile::FAMILY, 'like', "%{$search}%")));
        }

        return success(UserResource::collection($query->paginate($this->perPage())), __('admin.admins.list_received'));
    }

    // GET /admin/admins/{user}
    public function show(User $user): JsonResponse
    {
        if (! $user->hasRole('admin')) {
            return error(__('admin.admins.not_admin'), [], 404);
        }

        return success(new UserResource($user->load(['profile', 'roles', 'permissions'])), __('admin.admins.received'));
    }

    // POST /admin/admins
    public function store(StoreAdminRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            // ادمین هیچ‌کدام از چهار نقشِ محصول نیست — دسترسی‌اش از نقشِ Laratrust
            // «admin» می‌آید، نه از `type_id`. NULL همان «نقشِ محصولی ندارد» است.
            $user = User::create([
                User::MOBILE => $request->string(User::MOBILE),
                User::PASSWORD => Hash::make($request->string(User::PASSWORD)),
                User::TYPE_ID => null,
                // هر دو برای ورود با رمز الزامی‌اند (`AuthController::checkPassword`
                // حسابِ بدون آن‌ها را با `auth.profile_incomplete` رد می‌کند).
                User::NATIONAL_CODE => $request->string(User::NATIONAL_CODE),
                User::BIRTH_DATE => $request->string(User::BIRTH_DATE),
            ]);

            $user->profile()->create([
                UserProfile::NAME => $request->input('name'),
                UserProfile::FAMILY => $request->input('family'),
                UserProfile::EMAIL => $request->input('email'),
                // همان کد ملیِ `users` — پروفایل و کاربر نباید از هم واگرا شوند.
                // (ستون UNIQUE است و قبلاً `''` نوشته می‌شد؛ چون رشته‌ی خالی یک
                // مقدارِ واقعی حساب می‌شود، ساختنِ مدیرِ دوم `Duplicate entry ''`
                // می‌گرفت. مایگریشن `2026_07_26_120000_*` ستون را nullable کرد.)
                UserProfile::NATIONAL_CODE => $request->string(User::NATIONAL_CODE),
            ]);

            $user->addRole('admin');

            // سطح دسترسی — بدون ارسال permissions همه مجوزها داده می‌شود
            $this->syncAdminPermissions($user, $request->input('permissions'));

            return $user;
        });

        activity()->performedOn($user)->causedBy(auth()->user())
            ->withProperties(['mobile' => $user->mobile, 'permissions' => $request->input('permissions')])
            ->log('مدیر سیستم جدید ساخته شد.');

        return created(new UserResource($user->load(['profile', 'roles'])), __('admin.admins.created'));
    }

    // PUT /admin/admins/{user}
    public function update(UpdateAdminRequest $request, User $user): JsonResponse
    {
        if (! $user->hasRole('admin')) {
            return error(__('admin.admins.not_admin'), [], 404);
        }

        DB::transaction(function () use ($request, $user) {
            $userData = [];

            if ($request->filled(User::MOBILE)) {
                $userData[User::MOBILE] = $request->string(User::MOBILE);
            }

            // password اختیاری — فقط وقتی ارسال شود تغییر می‌کند
            if ($request->filled(User::PASSWORD)) {
                $userData[User::PASSWORD] = Hash::make($request->string(User::PASSWORD));
            }

            if ($userData !== []) {
                $user->update($userData);
            }

            $profileData = array_filter([
                UserProfile::NAME => $request->input('name'),
                UserProfile::FAMILY => $request->input('family'),
                UserProfile::EMAIL => $request->input('email'),
            ], fn ($v) => ! is_null($v));

            if ($profileData !== []) {
                $user->profile()->updateOrCreate([UserProfile::USER_ID => $user->id], $profileData);
            }

            // فقط وقتی permissions ارسال شده باشد sync می‌شود (خالی = سلب همه دسترسی‌های سطح‌بندی‌شده)
            if ($request->has('permissions')) {
                $this->syncAdminPermissions($user, $request->input('permissions') ?? []);
            }
        });

        // تغییر رمز، سشن‌های دیگر همین کاربر را باطل می‌کند (همان الگوی AccountSecurity)
        if ($request->filled(User::PASSWORD)) {
            $user->tokens()->delete();
        }

        activity()->performedOn($user)->causedBy(auth()->user())
            ->withProperties([
                'password_changed' => $request->filled(User::PASSWORD),
                'permissions' => $request->has('permissions') ? $request->input('permissions') : null,
            ])
            ->log('اطلاعات مدیر سیستم ویرایش شد.');

        return success(new UserResource($user->refresh()->load(['profile', 'roles'])), __('admin.admins.updated'));
    }

    // POST /admin/admins/{user}/toggle
    public function toggle(User $user): JsonResponse
    {
        if (! $user->hasRole('admin')) {
            return error(__('admin.admins.not_admin'), [], 404);
        }

        if ($user->id === Auth::id()) {
            return error(__('admin.admins.cannot_toggle_self'), [], 422);
        }

        $newState = ! $user->{User::IS_ACTIVE};
        $user->update([User::IS_ACTIVE => $newState]);

        if (! $newState) {
            $user->tokens()->delete();
        }

        activity()->performedOn($user)->causedBy(auth()->user())
            ->withProperties(['is_active' => $newState])
            ->log($newState ? 'حساب مدیر فعال شد.' : 'حساب مدیر غیرفعال شد.');

        return success(new UserResource($user->refresh()->load(['profile', 'roles'])), __('admin.admins.toggled'));
    }

    /**
     * sync مجوزهای user-level ادمین. null یعنی «همه مجوزها» (پیش‌فرض ساخت ادمین).
     */
    private function syncAdminPermissions(User $user, ?array $permissionNames): void
    {
        $names = $permissionNames ?? array_keys(\Database\Seeders\AdminPermissionSeeder::PERMISSIONS);

        $ids = \Laratrust\Models\Permission::whereIn('name', $names)->pluck('id')->all();
        $user->syncPermissions($ids);
    }

    // DELETE /admin/admins/{user} — فقط جداکردن نقش admin
    public function destroy(User $user): JsonResponse
    {
        if (! $user->hasRole('admin')) {
            return error(__('admin.admins.not_admin'), [], 404);
        }

        if ($user->id === Auth::id()) {
            return error(__('admin.admins.cannot_remove_self'), [], 422);
        }

        $user->removeRole('admin');
        $user->tokens()->delete();

        activity()->performedOn($user)->causedBy(auth()->user())
            ->withProperties(['mobile' => $user->mobile])
            ->log('نقش مدیر از کاربر جدا شد.');

        return success(null, __('admin.admins.removed'));
    }
}
