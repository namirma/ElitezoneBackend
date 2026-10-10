<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Financial\DeleteUserBankAccountAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Financial\UserBankAccountResource;
use App\Models\User;
use App\Models\UserBankAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * حساب‌های بانکیِ یک کاربر در بک‌آفیس — تنها جایی که حساب حذف می‌شود (علی ۱۴۰۵/۰۷/۱۹).
 */
class AdminUserBankAccountController extends Controller
{
    /**
     * همه‌ی حساب‌های کاربر، حذف‌شده‌ها هم (با `deleted_at`) — تاریخچه‌ی صادقانه برای پشتیبانی.
     */
    public function index(User $user): JsonResponse
    {
        $accounts = $user->bankAccounts()->withTrashed()->latest()->get();

        return success(UserBankAccountResource::collection($accounts), __('bank.list_received'));
    }

    public function destroy(Request $request, User $user, UserBankAccount $bankAccount, DeleteUserBankAccountAction $action): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);

        /** @var User $admin */
        $admin = $request->user();
        $action->execute($bankAccount, $admin, $data['reason']);

        return success(null, __('bank.admin_delete_success'));
    }
}
