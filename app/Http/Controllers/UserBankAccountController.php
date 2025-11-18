<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserBankAccountRequest;
use App\Http\Resources\UserBankAccountResource;
use App\Models\UserBankAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UserBankAccountController extends Controller
{
    public function index(): JsonResponse
    {
        $accounts = Auth::user()->bankAccounts()->latest()->get();
        return success(UserBankAccountResource::collection($accounts), __('bank.list_received'));
    }

    public function store(StoreUserBankAccountRequest $request): JsonResponse
    {
        $user = Auth::user();
        $data = $request->validated();

        DB::transaction(function () use ($user, $data) {
            if (!empty($data[UserBankAccount::IS_DEFAULT])) {
                $user->bankAccounts()->update([UserBankAccount::IS_DEFAULT => false]);
            }
            $user->bankAccounts()->create([
                UserBankAccount::BANK_NAME => $data[UserBankAccount::BANK_NAME],
                UserBankAccount::ACCOUNT_HOLDER_NAME => $data[UserBankAccount::ACCOUNT_HOLDER_NAME],
                UserBankAccount::SHABA => $data[UserBankAccount::SHABA],
                UserBankAccount::IS_DEFAULT => $data[UserBankAccount::IS_DEFAULT] ?? false,
                UserBankAccount::STATUS => UserBankAccount::STATUS_PENDING,
            ]);

            // TODO: ارسال نوتیفیکیشن به ادمین
        });

        return success([], __('bank.store_success'));
    }

    public function destroy(UserBankAccount $bankAccount): JsonResponse
    {
        if ($bankAccount->{UserBankAccount::USER_ID} !== Auth::id()) {
            return error(__('auth.forbidden'), [], 403);
        }

        if ($bankAccount->{UserBankAccount::STATUS} === UserBankAccount::STATUS_APPROVED) {
             return error(__('bank.delete_approved_error'), [], 400);
        }

        if ($bankAccount->{UserBankAccount::IS_DEFAULT}) {
             return error(__('bank.delete_default_error'), [], 400);
        }

        $bankAccount->delete();

        return success([], __('bank.delete_success'));
    }
}
