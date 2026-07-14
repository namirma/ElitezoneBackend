<?php

namespace App\Services;

use App\Models\User;

class PricingRoleResolver
{
    public function resolve(User $user): string
    {
        $user = $user->fresh(['roles', 'employer']);

        $hasRole = function (string $name) use ($user): bool {
            if (method_exists($user, 'hasRole')) {
                return $user->hasRole($name);
            }

            return method_exists($user, 'roles')
                ? $user->roles()->where('name', $name)->exists()
                : false;
        };

        $isEngineer = $hasRole('engineer');
        $isContractor = $hasRole('contractor');
        $isEmployer = $hasRole('employer');

        if ($isEngineer || $isContractor) {
            return 'engineer_with_consultant';
        }
        if ($isEmployer) {
            $hasEliteYar = (bool) optional($user->employer)->has_consultant;

            return $hasEliteYar ? 'engineer_with_consultant' : 'employer_without_consultant';
        }

        return 'employer_without_consultant';
    }
}
