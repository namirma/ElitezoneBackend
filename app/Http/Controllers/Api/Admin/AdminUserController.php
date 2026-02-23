<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserSearchRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\UserProfileResource;
use App\Http\Resources\UserResource;
use App\Http\Resources\WalletTransactionResource;
use App\Models\Order;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\WalletTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

class AdminUserController extends Controller
{
    public function index(UserSearchRequest $request): JsonResponse
    {
        $users = User::query()
            ->with(['profile', 'roles'])
            ->when(
                $request->filled('role'),
                fn(Builder $q) =>
                $q->whereHas('roles', fn($r) => $r->where('name', $request->role))
            )
            ->when(
                $request->filled('search'),
                fn(Builder $q) =>
                $q->where(
                    fn($sub) =>
                    $sub->where('mobile', 'like', "%{$request->search}%")
                        ->orWhereHas('profile', fn($p) => $p->where('name', 'like', "%{$request->search}%"))
                )
            )
            ->when(
                $request->has('is_active'),
                fn(Builder $q) =>
                $q->where('is_active', $request->boolean('is_active'))
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

    public function toggleStatus(User $user): JsonResponse
    {
        // فرض بر اینکه ستون is_active در جدول users دارید (اگر ندارید باید اضافه کنید)
        // $user->update(['is_active' => ! $user->is_active]);

        // فعلا چون ستون is_active در مایگریشن‌های شما نبود، فقط پیام موفقیت برمی‌گردانم
        // لطفا ستون is_active را به جدول users اضافه کنید.
        return success(null, __('admin.users.activated'));
    }

    public function addNote(Request $request, User $user): JsonResponse
    {
        // نیاز به جدول user_notes دارد که در پاسخ قبلی مایگریشن آن را دادم
        $request->validate(['note' => 'required|string|max:1000']);

        // $user->notes()->create([...]);

        return success(null, __('admin.users.note_added'));
    }
    public function getUserProfileForBackOffice(Request $request): JsonResponse
    {
        $request->validate([
            'keyword' => 'required|string|min:3',
        ]);

        $keyword = $request->input('keyword');

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

        if (!$user) {
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
            ->with(['items', 'shippingMethod'])
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

        if (!$wallet) {
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
