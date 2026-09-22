<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GeneralSearchRequest;
use App\Http\Resources\Financial\WalletTransactionResource;
use App\Http\Resources\Profile\UserResource;
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
            ->with([User::RELATION_PROFILE, 'wallet', User::RELATION_ROLES, User::RELATION_TYPE, User::RELATION_ENGINEER, User::RELATION_CONTRACTOR])
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
            ->paginate($this->perPage());

        return success(UserResource::collection($users), __('admin.user_search_success'));
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
            ->paginate($this->perPage());

        return success(WalletTransactionResource::collection($transactions), __('admin.transaction_search_success'));
    }
}
