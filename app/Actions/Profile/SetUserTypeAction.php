<?php

namespace App\Actions\Profile;

use App\Http\Resources\Profile\UserResource;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the "set user type" flow:
 *   1. Record agreement signature (SignAgreementAction)
 *   2. Resolve and set the UserType ID
 *   3. Sync Laratrust roles and profile records (SyncUserRolesAction)
 *
 * Extracted from ProfileController::setUserType so the controller becomes
 * a thin request/response adapter.
 */
final class SetUserTypeAction
{
    public function __construct(
        protected SignAgreementAction $signAgreement,
        protected SyncUserRolesAction $syncRoles,
    ) {}

    public function execute(User $user, Collection $newDisplayRoles, Request $request): JsonResponse
    {
        return DB::transaction(function () use ($user, $newDisplayRoles, $request): JsonResponse {

            // 1. Agreement
            $primaryRole = $newDisplayRoles->first() ?? '';
            $this->signAgreement->execute($user, $primaryRole, $request);

            // 2. Resolve UserType
            $userTypes = UserType::query()
                ->whereIn(UserType::SLUG, ['elite_yar', 'employer', 'supplier'])
                ->pluck(UserType::ID, UserType::SLUG);

            $eliteYarTypeId = $userTypes->get('elite_yar');
            $employerTypeId = $userTypes->get('employer');
            $supplierTypeId = $userTypes->get('supplier');

            if (! $eliteYarTypeId || ! $employerTypeId || ! $supplierTypeId) {
                Log::error('Missing required UserType slugs in database. Check UserTypeSeeder.');

                return error(__('profile.usertype_missing'), [], 500);
            }

            $targetTypeId = match (true) {
                $newDisplayRoles->contains('elite_yar') => $eliteYarTypeId,
                $newDisplayRoles->contains('employer') => $employerTypeId,
                $newDisplayRoles->contains('supplier') => $supplierTypeId,
                default => null,
            };

            if (is_null($targetTypeId)) {
                return error(__('profile.invalid_role_selection'), [], 422);
            }

            if ($user->{User::TYPE_ID} !== $targetTypeId) {
                $user->forceFill([User::TYPE_ID => $targetTypeId])->save();
            }

            // 3. Sync roles & profiles
            $this->syncRoles->execute($user, $newDisplayRoles);

            // 4. Return refreshed user
            $fresh = $user->fresh([
                User::RELATION_ROLES,
                User::RELATION_TYPE,
                User::RELATION_EMPLOYER,
                User::RELATION_SUPPLIER,
                User::RELATION_ENGINEER,
                User::RELATION_CONTRACTOR,
                User::RELATION_PROFILE,
                User::RELATION_BADGE,
                User::RELATION_EDUIX_RECORDS,
                User::RELATION_JOB_EXPERIENCES,
            ]);

            return success(new UserResource($fresh), __('profile.type_set_successfully'));
        });
    }
}
