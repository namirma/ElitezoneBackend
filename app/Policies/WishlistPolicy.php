<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

class WishlistPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return null;
    }

    public function view(User $user, Wishlist $wishlist): Response
    {
        return $wishlist->{Wishlist::USER_ID} === $user->id
            ? Response::allow()
            : Response::deny('شما اجازه مشاهده این لیست را ندارید.');
    }

    public function update(User $user, Wishlist $wishlist): Response
    {
        return $wishlist->{Wishlist::USER_ID} === $user->id
            ? Response::allow()
            : Response::deny('شما اجازه ویرایش این لیست را ندارید.');
    }

    public function delete(User $user, Wishlist $wishlist): Response
    {
        return $wishlist->{Wishlist::USER_ID} === $user->id
            ? Response::allow()
            : Response::deny('شما اجازه حذف این لیست را ندارید.');
    }
}
