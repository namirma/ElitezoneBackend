<?php

namespace App\Policies;

use App\Models\Address;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

class AddressPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return null;
    }

    public function view(User $user, Address $address): Response
    {
        return $address->{Address::USER_ID} === $user->id
            ? Response::allow()
            : Response::deny('شما اجازه مشاهده این آدرس را ندارید.');
    }

    public function update(User $user, Address $address): Response
    {
        return $address->{Address::USER_ID} === $user->id
            ? Response::allow()
            : Response::deny('شما اجازه ویرایش این آدرس را ندارید.');
    }

    public function delete(User $user, Address $address): Response
    {
        return $address->{Address::USER_ID} === $user->id
            ? Response::allow()
            : Response::deny('شما اجازه حذف این آدرس را ندارید.');
    }
}
