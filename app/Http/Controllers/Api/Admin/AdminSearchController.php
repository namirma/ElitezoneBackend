<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GeneralSearchRequest; // ریکوئست جدید
use App\Models\User;
use App\Models\UserProfile;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class AdminSearchController extends Controller
{
    public function searchUsers(GeneralSearchRequest $request): JsonResponse
    {
        $keyword = $request->input('query');

        $users = User::query()
            ->with([User::RELATION_PROFILE, 'wallet', User::RELATION_ROLES])
            ->where(function (Builder $query) use ($keyword) {
                $query->where(User::MOBILE, 'like', "%{$keyword}%")
                    ->orWhere(User::NATIONAL_CODE, 'like', "%{$keyword}%")
                    ->orWhereHas(User::RELATION_PROFILE, function (Builder $q) use ($keyword) {
                        $q->where(UserProfile::NAME, 'like', "%{$keyword}%")
                            ->orWhere(UserProfile::FAMILY, 'like', "%{$keyword}%")
                            ->orWhere(UserProfile::EMAIL, 'like', "%{$keyword}%");
                    });
            })
            ->latest(User::CREATED_AT)
            ->paginate($request->input('per_page', 20));

        // در اینجا می‌توان از UserResource استفاده کرد اما برای سرچ کلی، جیسون مستقیم هم اوکی است
        return success($users, __('admin.user_search_success'));
    }

    public function searchTransactions(GeneralSearchRequest $request): JsonResponse
    {
        $transactions = WalletTransaction::query()
            ->with(['wallet.user.profile'])
            ->when($request->filled('type'), fn ($q) => $q->where(WalletTransaction::TYPE, $request->type))
            ->when($request->filled('status'), fn ($q) => $q->where(WalletTransaction::STATUS, $request->status))
            ->when($request->boolean('has_receipt'), fn ($q) => $q->whereNotNull(WalletTransaction::META.'->receipt_path'))
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where(function ($sub) use ($request) {
                    $sub->where(WalletTransaction::ID, $request->search)
                        ->orWhere(WalletTransaction::AMOUNT, 'like', "%{$request->search}%")
                        ->orWhere(WalletTransaction::DESCRIPTION, 'like', "%{$request->search}%");
                });
            })
            ->latest(WalletTransaction::CREATED_AT)
            ->paginate($request->input('per_page', 20));

        return success($transactions, __('admin.transaction_search_success'));
    }
}
