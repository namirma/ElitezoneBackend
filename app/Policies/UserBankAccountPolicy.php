<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserBankAccount;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * Bank-account access is sensitive — controls ability to receive funds via
 * IBAN. Strict per-user ownership; admin bypass for support operations.
 */
class UserBankAccountPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return null;
    }

    public function view(User $user, UserBankAccount $account): Response
    {
        return $account->{UserBankAccount::USER_ID} === $user->id
            ? Response::allow()
            : Response::deny('شما اجازه مشاهده این حساب بانکی را ندارید.');
    }

    public function update(User $user, UserBankAccount $account): Response
    {
        return $account->{UserBankAccount::USER_ID} === $user->id
            ? Response::allow()
            : Response::deny('شما اجازه ویرایش این حساب بانکی را ندارید.');
    }

    public function delete(User $user, UserBankAccount $account): Response
    {
        return $account->{UserBankAccount::USER_ID} === $user->id
            ? Response::allow()
            : Response::deny('شما اجازه حذف این حساب بانکی را ندارید.');
    }
}
