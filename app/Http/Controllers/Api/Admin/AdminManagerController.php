<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminRequest;
use App\Http\Requests\Admin\UpdateAdminRequest;
use App\Http\Resources\Admin\AdminManagerResource;
use App\Models\User;
use App\Models\UserProfile;
use Database\Seeders\AdminPermissionSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laratrust\Models\Permission;

/**
 * پنل مدیریت — مدیران سیستم (ماژول ۷ نیازمندی فرانت BackOffice).
 *
 * destroy کاربر را حذف نمی‌کند — فقط نقش admin را جدا می‌کند (حذف کاربر با سوابق
 * مالی/سفارش غیرقابل برگشت است). خود-حذفی، خود-غیرفعال‌سازی و تغییرِ سطحِ خود مجاز نیست.
 *
 * سطحِ اختیار (`admin_level`) از ۲۰۲۶-۱۰-۰۳ در ساخت الزامی است و در ویرایش قابلِ
 * تغییر؛ پیش از آن هیچ‌جا نوشته نمی‌شد و ادمینِ تازه روی همه‌ی مسیرهای
 * `admin.level:N` ۴۰۳ می‌گرفت. پین: `Admin/AdminManagerLevelTest`.
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

        return success(AdminManagerResource::collection($query->paginate($this->perPage())), __('admin.admins.list_received'));
    }

    // GET /admin/admins/{user}
    public function show(User $user): JsonResponse
    {
        if (! $user->hasRole('admin')) {
            return error(__('admin.admins.not_admin'), [], 404);
        }

        return success(new AdminManagerResource($user->load(['profile', 'roles', 'permissions'])), __('admin.admins.received'));
    }

    // POST /admin/admins
    public function store(StoreAdminRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            // ادمین هیچ‌کدام از چهار نقشِ محصول نیست — دسترسی‌اش از نقشِ Laratrust
            // «admin» می‌آید، نه از `type_id`. NULL همان «نقشِ محصولی ندارد» است.
            $user = new User([
                User::MOBILE => $request->string(User::MOBILE),
                User::PASSWORD => Hash::make($request->string(User::PASSWORD)),
                User::TYPE_ID => null,
                // هر دو برای ورود با رمز الزامی‌اند (`AuthController::checkPassword`
                // حسابِ بدون آن‌ها را با `auth.profile_incomplete` رد می‌کند).
                User::NATIONAL_CODE => $request->string(User::NATIONAL_CODE),
                User::BIRTH_DATE => $request->string(User::BIRTH_DATE),
            ]);
            // سطح در `$fillable` نیست (ستونِ اختیار است): فقط این‌جا و فقط پس از قاعده‌ی
            // سقفِ اعطا در `StoreAdminRequest` نوشته می‌شود. یک INSERT، نه دو نوشتن.
            $user->forceFill([User::ADMIN_LEVEL => $request->integer(User::ADMIN_LEVEL)])->save();

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
            ->withProperties([
                'mobile' => $user->mobile,
                'admin_level' => $request->integer(User::ADMIN_LEVEL),
                'permissions' => $request->input('permissions'),
            ])
            ->log('مدیر سیستم جدید ساخته شد.');

        return created(new AdminManagerResource($user->load(['profile', 'roles'])), __('admin.admins.created'));
    }

    // PUT /admin/admins/{user}
    public function update(UpdateAdminRequest $request, User $user): JsonResponse
    {
        if (! $user->hasRole('admin')) {
            return error(__('admin.admins.not_admin'), [], 404);
        }

        $levelBefore = $user->{User::ADMIN_LEVEL} === null ? null : (int) $user->{User::ADMIN_LEVEL};
        $levelChanges = $request->has(User::ADMIN_LEVEL) && $request->integer(User::ADMIN_LEVEL) !== $levelBefore;

        // سطحِ خود قابلِ تغییر نیست — همان قاعده‌ی `cannot_toggle_self`: مدیر ارشدی که
        // خودش را تنزل دهد ممکن است آخرین مدیر ارشد باشد، و آن‌وقت دیگر هیچ‌کس به این
        // صفحه نمی‌رسد. فرستادنِ همان سطحِ فعلی (فرمِ ویرایش پیش‌پرش می‌کند) مجاز است.
        if ($levelChanges && $user->id === Auth::id()) {
            return error(__('admin.levels.self_demote'), [], 422);
        }

        DB::transaction(function () use ($request, $user, $levelChanges) {
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

            // سطح در `$fillable` نیست؛ سقفِ اعطا را `UpdateAdminRequest` سنجیده است. اثرش
            // فوری است: `EnsureAdminHasLevel` در هر درخواست ستون را می‌خواند.
            if ($levelChanges) {
                $user->forceFill([User::ADMIN_LEVEL => $request->integer(User::ADMIN_LEVEL)])->save();
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
                'admin_level' => $levelChanges
                    ? ['from' => $levelBefore, 'to' => $request->integer(User::ADMIN_LEVEL)]
                    : null,
                'permissions' => $request->has('permissions') ? $request->input('permissions') : null,
            ])
            ->log('اطلاعات مدیر سیستم ویرایش شد.');

        return success(new AdminManagerResource($user->refresh()->load(['profile', 'roles'])), __('admin.admins.updated'));
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

        return success(new AdminManagerResource($user->refresh()->load(['profile', 'roles'])), __('admin.admins.toggled'));
    }

    /**
     * sync مجوزهای user-level ادمین. null یعنی «همه مجوزها» (پیش‌فرض ساخت ادمین).
     */
    private function syncAdminPermissions(User $user, ?array $permissionNames): void
    {
        $names = $permissionNames ?? array_keys(AdminPermissionSeeder::PERMISSIONS);

        $ids = Permission::whereIn('name', $names)->pluck('id')->all();
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

        $levelWas = $user->{User::ADMIN_LEVEL};

        // قاعده‌ی ستون: NULL یعنی «ادمین نیست». سطحِ جامانده روی کاربرِ عادی، اگر روزی
        // نقش دوباره داده شود، بی‌صدا همان اختیارِ قبلی را برمی‌گرداند.
        DB::transaction(function () use ($user) {
            $user->removeRole('admin');
            $user->forceFill([User::ADMIN_LEVEL => null])->save();
        });
        $user->tokens()->delete();

        activity()->performedOn($user)->causedBy(auth()->user())
            ->withProperties(['mobile' => $user->mobile, 'admin_level_was' => $levelWas])
            ->log('نقش مدیر از کاربر جدا شد.');

        return success(null, __('admin.admins.removed'));
    }
}
