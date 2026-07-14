<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserBackOfficeSearchRequest;
use App\Http\Requests\Admin\UserSearchRequest;
use App\Http\Resources\Financial\WalletTransactionResource;
use App\Http\Resources\Order\OrderResource;
use App\Http\Resources\Profile\UserResource;
use App\Models\ContractorProfile;
use App\Models\EngineerProfile;
use App\Models\Order;
use App\Models\User;
use App\Models\UserNote;
use App\Models\UserProfile;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminUserController extends Controller
{
    public function index(UserSearchRequest $request): JsonResponse
    {
        $users = User::query()
            ->with(['profile', 'roles'])
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
        $user->load(['engineer', 'employer', 'supplier', 'contractor', 'wallet', 'profile']);

        return success(new UserResource($user));
    }

    /**
     * فعال/غیرفعال کردن حساب کاربری
     */
    public function toggleStatus(User $user): JsonResponse
    {
        $user->update([User::IS_ACTIVE => ! $user->{User::IS_ACTIVE}]);

        return success(
            new UserResource($user),
            $user->{User::IS_ACTIVE} ? __('admin.users.activated') : __('admin.users.deactivated')
        );
    }

    /**
     * افزودن یادداشت ادمین روی کاربر (یادداشت عمومی کاربر)
     */
    public function addNote(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate(['note' => 'required|string|max:1000']);

        $user->notes()->create([
            UserNote::ADMIN_ID => Auth::id(),
            UserNote::NOTE => $validated['note'],
        ]);

        return success(null, __('admin.users.note_added'));
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
        $profile = $this->resolveEliteYARProfile($user);

        if (! $profile) {
            return error(__('admin.users.no_consultant_profile'), [], 422);
        }

        $profile->update([
            $profile::IS_VERIFIED => true,
            $profile::VERIFIED_AT => now(),
        ]);

        return success(
            [
                'user_id'     => $user->id,
                'profile_type'=> $profile instanceof EngineerProfile ? 'engineer' : 'contractor',
                'is_verified' => true,
                'verified_at' => $profile->{$profile::VERIFIED_AT}->toDateTimeString(),
            ],
            __('admin.users.consultant_verified')
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
            return error(__('admin.users.no_consultant_profile'), [], 422);
        }

        $profile->update([
            $profile::IS_VERIFIED => false,
            $profile::VERIFIED_AT => null,
        ]);

        return success(
            [
                'user_id'     => $user->id,
                'profile_type'=> $profile instanceof EngineerProfile ? 'engineer' : 'contractor',
                'is_verified' => false,
                'verified_at' => null,
            ],
            __('admin.users.consultant_unverified')
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
