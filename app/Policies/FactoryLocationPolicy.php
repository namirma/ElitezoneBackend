<?php

namespace App\Policies;

use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

class FactoryLocationPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return null;
    }

    public function view(User $user, FactoryLocation $factoryLocation): Response
    {
        return $this->owns($user, $factoryLocation)
            ? Response::allow()
            : Response::deny(__('auth.policy.factory_access_denied'));
    }

    public function update(User $user, FactoryLocation $factoryLocation): Response
    {
        return $this->owns($user, $factoryLocation)
            ? Response::allow()
            : Response::deny(__('auth.policy.factory_access_denied'));
    }

    public function delete(User $user, FactoryLocation $factoryLocation): Response
    {
        return $this->owns($user, $factoryLocation)
            ? Response::allow()
            : Response::deny(__('auth.policy.factory_access_denied'));
    }

    private function owns(User $user, FactoryLocation $factoryLocation): bool
    {
        $supplierUserId = $factoryLocation->factory?->{Factory::SUPPLIER_USER_ID};

        return $supplierUserId !== null && (int) $supplierUserId === (int) $user->id;
    }
}
