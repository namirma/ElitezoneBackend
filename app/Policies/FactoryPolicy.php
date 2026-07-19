<?php

namespace App\Policies;

use App\Models\Factory;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

class FactoryPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return null;
    }

    public function view(User $user, Factory $factory): Response
    {
        return $this->owns($user, $factory)
            ? Response::allow()
            : Response::deny(__('auth.policy.factory_access_denied'));
    }

    public function update(User $user, Factory $factory): Response
    {
        return $this->owns($user, $factory)
            ? Response::allow()
            : Response::deny(__('auth.policy.factory_access_denied'));
    }

    public function delete(User $user, Factory $factory): Response
    {
        return $this->owns($user, $factory)
            ? Response::allow()
            : Response::deny(__('auth.policy.factory_access_denied'));
    }

    private function owns(User $user, Factory $factory): bool
    {
        return $factory->{Factory::SUPPLIER_USER_ID} !== null
            && (int) $factory->{Factory::SUPPLIER_USER_ID} === (int) $user->id;
    }
}
