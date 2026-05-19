<?php

namespace App\Actions\Profile;

use App\Models\ContractorProfile;
use App\Models\EmployerProfile;
use App\Models\EngineerProfile;
use App\Models\SupplierProfile;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Adds / removes Laratrust roles and creates / deletes the corresponding
 * role-profile records based on the user's new role selection.
 *
 * Extracted from ProfileController::setUserType so it is independently
 * testable and single-purpose.
 */
final class SyncUserRolesAction
{
    /**
     * @param  User  $user  The authenticated user to sync
     * @param  Collection  $newDisplayRoles  e.g. collect(['employer']) or collect(['expert'])
     */
    public function execute(User $user, Collection $newDisplayRoles): void
    {
        $currentRoles = $user->roles->pluck('name');

        // --- Employer ---
        if ($newDisplayRoles->contains('employer')) {
            if (! $currentRoles->contains('employer')) {
                $user->addRole('employer');
            }
            $user->employer()->firstOrCreate([EmployerProfile::USER_ID => $user->id]);
        } else {
            $user->removeRole('employer');
            $user->employer()->where(EmployerProfile::USER_ID, $user->id)->delete();
        }

        // --- Supplier ---
        if ($newDisplayRoles->contains('supplier')) {
            if (! $currentRoles->contains('supplier')) {
                $user->addRole('supplier');
            }
            $user->supplier()->firstOrCreate([SupplierProfile::USER_ID => $user->id]);
        } else {
            $user->removeRole('supplier');
            $user->supplier()->where(SupplierProfile::USER_ID, $user->id)->delete();
        }

        // --- Expert (engineer / contractor) — only clean-up on removal ---
        if (! $newDisplayRoles->contains('expert')) {
            $user->removeRoles(['engineer', 'contractor']);
            $user->engineer()->where(EngineerProfile::USER_ID, $user->id)->delete();
            $user->contractor()->where(ContractorProfile::USER_ID, $user->id)->delete();
            $user->eduixRecords()->delete();
            $user->jobExperiences()->delete();
        }
    }
}
