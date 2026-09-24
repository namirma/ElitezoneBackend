<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Profile\NotifyEliteYARVerificationChange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserBackOfficeSearchRequest;
use App\Http\Requests\Admin\UserSearchRequest;
use App\Http\Resources\Financial\WalletTransactionResource;
use App\Http\Resources\Order\OrderResource;
use App\Http\Resources\Profile\UserNoteResource;
use App\Http\Resources\Profile\UserResource;
use App\Models\ContractorProfile;
use App\Models\EngineerProfile;
use App\Models\Order;
use App\Models\User;
use App\Models\UserNote;
use App\Models\UserProfile;
use App\Models\WalletTransaction;
use App\Services\Profile\EliteYARVerificationMethods;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminUserController extends Controller
{
    public function index(UserSearchRequest $request): JsonResponse
    {
        $users = User::query()
            // type لود می‌شود تا UserResource بتواند «نوع کاربر» (مثلاً مشتری) را برای
            // کاربرانی که هنوز نقش Laratrust خاصی انتخاب نکرده‌اند نمایش دهد.
            // engineer/contractor هم: `User::isVerifiedEliteYAR()` وقتی روشِ مدرکی روشن
            // است این دو را می‌خواند و بدون eager-load هر ردیفِ الیت‌یار دو کوئری می‌زد.
            ->with(['profile', 'roles', User::RELATION_TYPE, User::RELATION_ENGINEER, User::RELATION_CONTRACTOR])
            // ستون «یادداشت‌ها» در لیست بک‌آفیس فقط به تعداد نیاز دارد، نه متن.
            ->withCount('notes')
            ->when(
                $request->filled('role'),
                fn (Builder $q) => $q->whereHas('roles', fn ($r) => $r->where('name', $request->role))
            )
            ->when(
                $request->filled('search'),
                fn (Builder $q) => $q->where(
                    fn ($sub) => $sub->where('mobile', 'like', "%{$request->search}%")
                        ->orWhereHas('profile', fn ($p) => $p->where('name', 'like', "%{$request->search}%"))
                )
            )
            ->when(
                $request->has('is_active'),
                fn (Builder $q) => $q->where('is_active', $request->boolean('is_active'))
            )
            ->latest()
            ->paginate($this->perPage());

        return success(UserResource::collection($users));
    }

    public function show(User $user): JsonResponse
    {
        $user->load([
            'engineer', 'employer', 'supplier', 'contractor', 'wallet', 'profile',
            User::RELATION_ROLES, User::RELATION_TYPE,
            // یادداشت‌های ادمین (تازه‌ترین اول) — پنل بک‌آفیس آن‌ها را زیر فرم ثبت
            // یادداشت نمایش می‌دهد؛ قبلاً ثبت می‌شدند ولی هیچ‌جا برنمی‌گشتند.
            'notes' => fn ($q) => $q->with('admin.profile')->latest(),
        ])->loadCount('notes');

        return success(new UserResource($user));
    }

    /**
     * فعال/غیرفعال کردن حساب کاربری
     *
     * غیرفعال‌سازی باید فوری اثر کند: توکن‌های Sanctum کاربر حذف می‌شوند و
     * middleware سراسری EnsureUserIsActive جلوی هر توکن/لاگین بعدی را می‌گیرد.
     */
    public function toggleStatus(User $user): JsonResponse
    {
        // ادمین نباید بتواند حساب خودش را غیرفعال کند (خطر قفل‌شدن کامل پنل).
        if ($user->id === Auth::id()) {
            return error(__('admin.users.cannot_deactivate_self'), [], 422);
        }

        $newState = ! $user->{User::IS_ACTIVE};
        $user->update([User::IS_ACTIVE => $newState]);

        if (! $newState) {
            $user->tokens()->delete();
        }

        return success(
            new UserResource($user),
            $newState ? __('admin.users.activated') : __('admin.users.deactivated')
        );
    }

    /**
     * تعیین سقف اعتبار کاربر — بند ۳.۱۲ سند، «دستی توسط بک‌آفیس».
     *
     * فقط `credit_limit` نوشته می‌شود، هرگز `debt_balance`: مانده تنها از مسیر
     * `DebtEngine` عوض می‌شود تا با دفترِ `debt_entries` هم‌خوان بماند. اصلاحِ
     * دستیِ خودِ بدهی باید یک ردیفِ `adjustment` بگذارد، نه یک UPDATE بی‌رد.
     *
     * ستون در `$fillable` نیست (مثل اسنپ‌شات‌های مالیِ Order)، پس `forceFill`.
     */
    public function setCreditLimit(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'credit_limit' => ['required', 'numeric', 'min:0'],
        ], [
            'credit_limit.required' => __('admin.users.credit_limit_required'),
            'credit_limit.min' => __('admin.users.credit_limit_min'),
        ]);

        $user->forceFill([User::CREDIT_LIMIT => $validated['credit_limit']])->save();

        activity()
            ->performedOn($user)
            ->causedBy(Auth::user())
            ->withProperties(['credit_limit' => $validated['credit_limit']])
            ->log('admin.user.credit_limit_updated');

        return success(new UserResource($user->fresh()), __('admin.users.credit_limit_updated'));
    }

    /**
     * افزودن یادداشت ادمین روی کاربر (یادداشت عمومی کاربر)
     */
    public function addNote(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate(['note' => 'required|string|max:1000']);

        $note = $user->notes()->create([
            UserNote::ADMIN_ID => Auth::id(),
            UserNote::NOTE => $validated['note'],
        ]);

        // یادداشتِ ساخته‌شده برگردانده می‌شود تا پنل بتواند بدون رفچِ کامل صفحه
        // آن را به لیست اضافه کند (قبلاً null برمی‌گشت و کاربر فقط توست موفقیت
        // می‌دید بدون اینکه یادداشتش را جایی ببیند).
        $note->load('admin.profile');

        return success(new UserNoteResource($note), __('admin.users.note_added'));
    }

    public function getUserProfileForBackOffice(UserBackOfficeSearchRequest $request): JsonResponse
    {
        $keyword = $request->validated('keyword');

        $user = User::query()
            ->with([
                User::RELATION_PROFILE,
                User::RELATION_ENGINEER,
                User::RELATION_CONTRACTOR,
                User::RELATION_SUPPLIER,
                User::RELATION_EMPLOYER,
                User::RELATION_TYPE,
                User::RELATION_BADGE,
                'badges',
                'wallet',
                'addresses.province',
                'addresses.city',
                'eduixRecords',
                User::RELATION_JOB_EXPERIENCES,
            ])
            ->where(function ($query) use ($keyword) {
                $query->where(User::MOBILE, $keyword)
                    ->orWhere(User::NATIONAL_CODE, $keyword);
            })
            ->orWhereHas(User::RELATION_PROFILE, function ($q) use ($keyword) {
                $q->where(UserProfile::NATIONAL_CODE, $keyword)
                    ->orWhere(UserProfile::NATIONAL_ID, $keyword);
            })
            ->first();

        if (! $user) {
            return error(
                __('admin.users.not_found'),
                ['keyword' => $keyword],
                404
            );
        }

        return success(
            new UserResource($user),
            __('global.received_successfully')
        );
    }

    public function getUserOrders(Request $request, User $user): JsonResponse
    {
        $orders = Order::query()
            ->where(Order::PLACER_USER_ID, $user->id)
            ->with(Order::resourceRelations())
            ->latest()
            ->paginate($this->perPage(10));

        return success(
            OrderResource::collection($orders),
            __('global.received_successfully')
        );
    }

    /**
     * دریافت تراکنش‌های کیف پول یک کاربر خاص
     */
    public function getUserTransactions(Request $request, User $user): JsonResponse
    {
        $wallet = $user->wallet;

        if (! $wallet) {
            $empty = WalletTransaction::query()->whereNull(WalletTransaction::ID)->paginate($this->perPage(10));

            return success(WalletTransactionResource::collection($empty));
        }
        $transactions = WalletTransaction::query()
            ->where(WalletTransaction::WALLET_ID, $wallet->id)
            ->latest()
            ->paginate($this->perPage(10));

        return success(
            WalletTransactionResource::collection($transactions),
            __('global.received_successfully')
        );
    }

    /**
     * تأیید مدارک الیت یار توسط ادمین.
     *
     * یکی از پروفایل مهندس یا پیمانکار کاربر را پیدا کرده و
     * is_verified = true + verified_at = now() روی آن ست می‌کند.
     *
     * POST /admin/users/{user}/verify-elite-yar
     */
    public function verifyEliteYAR(User $user): JsonResponse
    {
        // تأییدِ دستی پشتِ دو روشِ مدرکی است (تنظیمات › تأیید الیت یار). با
        // خاموش‌بودنِ هر دو، `is_verified` در محمولِ تأیید نمی‌شمارد — پس ثبتش
        // یک تیکِ بی‌اثر می‌شد که ادمین «تأییدشده» می‌خواند و سایت نه.
        if (! EliteYARVerificationMethods::documentReviewEnabled()) {
            return error(__('admin.users.manual_verification_disabled'), [], 422);
        }

        $profile = $this->resolveEliteYARProfile($user);

        if (! $profile) {
            return error(__('admin.users.no_elite_yar_profile'), [], 422);
        }

        $wasVerified = $user->isVerifiedEliteYAR();
        $profile->update([
            $profile::IS_VERIFIED => true,
            $profile::VERIFIED_AT => now(),
        ]);
        app(NotifyEliteYARVerificationChange::class)->execute($user->refresh(), $wasVerified, $user->isVerifiedEliteYAR(), 'admin');

        return success(
            [
                'user_id' => $user->id,
                'profile_type' => $profile instanceof EngineerProfile ? 'engineer' : 'contractor',
                'is_verified' => true,
                'verified_at' => $profile->{$profile::VERIFIED_AT}->toDateTimeString(),
            ],
            __('admin.users.elite_yar_verified')
        );
    }

    /**
     * لغو تأیید مدارک الیت یار توسط ادمین.
     *
     * POST /admin/users/{user}/unverify-elite-yar
     */
    public function unverifyEliteYAR(User $user): JsonResponse
    {
        $profile = $this->resolveEliteYARProfile($user);

        if (! $profile) {
            return error(__('admin.users.no_elite_yar_profile'), [], 422);
        }

        $wasVerified = $user->isVerifiedEliteYAR();
        $profile->update([
            $profile::IS_VERIFIED => false,
            $profile::VERIFIED_AT => null,
        ]);
        app(NotifyEliteYARVerificationChange::class)->execute($user->refresh(), $wasVerified, $user->isVerifiedEliteYAR(), 'admin');

        return success(
            [
                'user_id' => $user->id,
                'profile_type' => $profile instanceof EngineerProfile ? 'engineer' : 'contractor',
                'is_verified' => false,
                'verified_at' => null,
            ],
            __('admin.users.elite_yar_unverified')
        );
    }

    /**
     * پیدا کردن پروفایل الیت یار (مهندس یا پیمانکار) برای یک کاربر.
     * مهندس اولویت دارد؛ اگر نبود پیمانکار را برمی‌گرداند.
     */
    private function resolveEliteYARProfile(User $user): EngineerProfile|ContractorProfile|null
    {
        $engineer = EngineerProfile::where(EngineerProfile::USER_ID, $user->id)->first();
        if ($engineer) {
            return $engineer;
        }

        return ContractorProfile::where(ContractorProfile::USER_ID, $user->id)->first();
    }
}
