<?php

namespace App\Policies;

use App\Models\Factory;
use App\Models\ShippingRate;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

class ShippingRatePolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return null;
    }

    public function update(User $user, ShippingRate $shippingRate): Response
    {
        return $this->owns($user, $shippingRate)
            ? Response::allow()
            : Response::deny(__('auth.policy.factory_access_denied'));
    }

    public function delete(User $user, ShippingRate $shippingRate): Response
    {
        return $this->owns($user, $shippingRate)
            ? Response::allow()
            : Response::deny(__('auth.policy.factory_access_denied'));
    }

    private function owns(User $user, ShippingRate $shippingRate): bool
    {
        $supplierUserId = $shippingRate->factoryLocation?->factory?->{Factory::SUPPLIER_USER_ID};

        return $supplierUserId !== null && (int) $supplierUserId === (int) $user->id;
    }
}
