<?php

namespace App\Policies;

use App\Models\Factory;
use App\Models\ProductPrice;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

class ProductPricePolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return null;
    }

    public function update(User $user, ProductPrice $price): Response
    {
        return $this->owns($user, $price)
            ? Response::allow()
            : Response::deny(__('auth.policy.factory_access_denied'));
    }

    public function delete(User $user, ProductPrice $price): Response
    {
        return $this->owns($user, $price)
            ? Response::allow()
            : Response::deny(__('auth.policy.factory_access_denied'));
    }

    private function owns(User $user, ProductPrice $price): bool
    {
        $supplierUserId = $price->factory?->{Factory::SUPPLIER_USER_ID};

        return $supplierUserId !== null && (int) $supplierUserId === (int) $user->id;
    }
}
