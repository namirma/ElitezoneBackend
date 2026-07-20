<?php

use App\Models\UserType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time backfill: grant the `employer` Laratrust role to every user whose
 * type_id = employer but who is missing the role in role_user.
 *
 * Root cause: EmployerController::store() did not call addRole() before the
 * fix in commit 77565f2. Users who set their type before that commit never
 * received the role, so role-gated endpoints (GET /profile/consultants, etc.)
 * returned 403 for them.
 *
 * The forward fix (EmployerController::store()) covers new saves; this
 * migration covers everyone already in the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        $employerTypeId = UserType::where('slug', 'employer')->value('id');

        if (! $employerTypeId) {
            return;
        }

        $roleId = DB::table('roles')->where('name', 'employer')->value('id');

        if (! $roleId) {
            return;
        }

        $alreadyGranted = DB::table('role_user')
            ->where('role_id', $roleId)
            ->pluck('user_id')
            ->all();

        $toBackfill = DB::table('users')
            ->where('type_id', $employerTypeId)
            ->whereNotIn('id', $alreadyGranted)
            ->pluck('id');

        $rows = $toBackfill->map(fn ($userId) => [
            'role_id' => $roleId,
            'user_id' => $userId,
            'user_type' => 'App\\Models\\User',
        ])->all();

        if (! empty($rows)) {
            DB::table('role_user')->insert($rows);
        }
    }

    public function down(): void
    {
        // Not reversible: we cannot know which role assignments pre-existed
        // vs. which ones this migration added. Leave as-is on rollback.
    }
};
