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
        return success(UserBankAccountResource::collection($accounts), 'حساب‌های بانکی دریافت شد.');
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

            // TODO: ارسال نوتیفیکیشن به ادمین برای بررسی این حساب جدید
        });

        return success([], '.حساب بانکی شما ثبت شد و پس از احراز هویت توسط بک آفیس تایید خواهد شد');
    }
    public function destroy(UserBankAccount $bankAccount): JsonResponse
    {
        if ($bankAccount->{UserBankAccount::USER_ID} !== Auth::id()) {
            return error(__('auth.forbidden'), [], 403);
        }
        if ($bankAccount->{UserBankAccount::STATUS} === UserBankAccount::STATUS_APPROVED) {
             return error('.امکان حذف حساب تایید شده وجود ندارد', [], 400);
        }
        if ($bankAccount->{UserBankAccount::IS_DEFAULT}) {
             return error('.امکان حذف حساب پیش‌فرض وجود ندارد', [], 400);
        }

        $bankAccount->delete();

        return success([], '.حساب بانکی با موفقیت حذف شد');
    }
}
