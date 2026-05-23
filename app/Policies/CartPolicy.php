<?php

namespace App\Policies;

use App\Models\Cart;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

class CartPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return null;
    }

    /** مشاهده / دسترسی به سبد خرید */
    public function view(User $user, Cart $cart): Response
    {
        return $cart->{Cart::USER_ID} === $user->id
            ? Response::allow()
            : Response::deny('شما اجازه دسترسی به این سبد خرید را ندارید.');
    }

    /** افزودن / تغییر / حذف آیتم در سبد خرید */
    public function update(User $user, Cart $cart): Response
    {
        return $this->view($user, $cart);
    }

    /** حذف کامل سبد */
    public function delete(User $user, Cart $cart): Response
    {
        return $this->view($user, $cart);
    }
}
