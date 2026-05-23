<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserBackOfficeSearchRequest;
use App\Http\Requests\Admin\UserSearchRequest;
use App\Http\Resources\Order\OrderResource;
use App\Http\Resources\Profile\UserResource;
use App\Http\Resources\Financial\WalletTransactionResource;
use App\Models\Order;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            ->paginate($request->get('per_page', 20));

        return success(UserResource::collection($users));
    }

    public function show(User $user): JsonResponse
    {
        $user->load(['engineer', 'employer', 'supplier', 'contractor', 'wallet', 'profile']);

        return success(new UserResource($user));
    }

    /**
     * فعال/غیرفعال کردن حساب کاربری
     *
     * @todo ستون is_active هنوز به جدول users اضافه نشده است.
     *       migration زیر را اجرا کنید و سپس این متد را فعال کنید:
     *       php artisan make:migration add_is_active_to_users_table
     *       $table->boolean('is_active')->default(true)->after('mobile');
     */
    public function toggleStatus(User $user): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => 'این قابلیت هنوز پیاده‌سازی نشده است. '
                .'ابتدا ستون is_active را به جدول users اضافه کنید.',
        ], 501);
    }

    /**
     * افزودن یادداشت ادمین روی کاربر
     *
     * @todo جدول user_notes هنوز ساخته نشده است.
     *       migration زیر را اجرا کنید و سپس این متد را فعال کنید:
     *       php artisan make:migration create_user_notes_table
     */
    public function addNote(Request $request, User $user): JsonResponse
    {
        $request->validate(['note' => 'required|string|max:1000']);

        return response()->json([
            'status' => false,
            'message' => 'این قابلیت هنوز پیاده‌سازی نشده است. '
                .'ابتدا جدول user_notes را بسازید.',
        ], 501);
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
                'کاربری با این مشخصات یافت نشد.',
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
            ->paginate($request->get('per_page', 10));

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
            $empty = WalletTransaction::query()->whereNull(WalletTransaction::ID)->paginate($request->get('per_page', 10));

            return success(WalletTransactionResource::collection($empty));
        }
        $transactions = WalletTransaction::query()
            ->where(WalletTransaction::WALLET_ID, $wallet->id)
            ->latest()
            ->paginate($request->get('per_page', 10));

        return success(
            WalletTransactionResource::collection($transactions),
            __('global.received_successfully')
        );
    }
}
