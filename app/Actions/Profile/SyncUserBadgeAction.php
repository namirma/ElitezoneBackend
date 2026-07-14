<?php

namespace App\Actions\Profile;

use App\Models\Badge;
use App\Models\EngineerProfile;
use App\Models\User;
use App\Services\Saze90BadgeService;
use App\Support\PhoneNumber;
use Illuminate\Support\Collection;

/**
 * Resolves a user's badges (saze90 tier / membership + pioneer) and persists the parts
 * other features rely on.
 *
 * Sources:
 *  - saze90.com `get-certificate?mobile=` → a collaboration tier (gold/silver/bronze) for
 *    users who bought a package, or any other non-false code → generic «کاربر سازه ۹۰»
 *    membership. Read via Saze90BadgeService (fail-safe). Any non-null result means the
 *    person IS a saze90 member (`is_saze90_user = true`).
 *  - Pioneer (پیشگام) → granted internally to members for their **first year** after
 *    registration. Time-based, NOT from saze90.
 *    NOTE: the frontend brief says "first N registrants" instead — kept as first-year per
 *    the owner's earlier instruction; flagged for confirmation.
 *
 * Persistence (deliberately conservative):
 *  - `users.badge_id` ← ONLY the commission tiers (gold/silver/bronze). A null badge_id
 *    already maps to pioneer/default rates in SettlementCalculator. Never cleared on a null
 *    result (a transient saze90 outage must not silently downgrade a eliteYar).
 *  - `engineer_profiles.is_saze90_user` ← backend-determined (read-only for the frontend).
 *  - `badge_user` pivot ← the managed badge set (tier|saze90 + pioneer) for "my badges".
 */
class SyncUserBadgeAction
{
    /** Codes SettlementCalculator treats as commission tiers (everything else = default/pioneer). */
    private const TIER_CODES = ['gold', 'silver', 'bronze'];

    /** All codes this action owns in the badge_user pivot (so it can replace, not accumulate). */
    private const MANAGED_CODES = ['gold', 'silver', 'bronze', 'saze90', 'pioneer'];

    public function __construct(private readonly Saze90BadgeService $saze90) {}

    /**
     * @return array{badge: ?string, is_pioneer: bool, is_saze90_user: bool, badges: Collection<int,Badge>}
     */
    public function execute(User $user): array
    {
        $mobile = $user->{User::MOBILE} ? PhoneNumber::to09((string) $user->{User::MOBILE}) : '';
        $sazeCode = $mobile ? $this->saze90->getCertificate($mobile) : null;

        $isSaze90User = $sazeCode !== null;                                  // any saze90 record
        $isTier = $sazeCode !== null && in_array($sazeCode, self::TIER_CODES, true);
        $isPioneer = $user->created_at !== null && $user->created_at->gt(now()->subYear());

        // The user's badge codes: the tier (if a package buyer) OR the generic saze90 badge,
        // plus pioneer when applicable.
        $codes = [];
        if ($isTier) {
            $codes[] = $sazeCode;
        } elseif ($isSaze90User) {
            $codes[] = 'saze90';
        }
        if ($isPioneer) {
            $codes[] = 'pioneer';
        }

        /** @var Collection<int,Badge> $badges */
        $badges = empty($codes)
            ? new Collection
            : Badge::whereIn(Badge::CODE, $codes)->get();

        // 1) Commission tier → users.badge_id (never cleared on a null/non-tier result).
        if ($isTier) {
            $tierBadge = $badges->firstWhere(Badge::CODE, $sazeCode);
            if ($tierBadge && (int) $user->{User::BADGE_ID} !== (int) $tierBadge->id) {
                $user->forceFill([User::BADGE_ID => $tierBadge->id])->save();
            }
        }

        // 2) is_saze90_user → persist on the engineer profile when one exists (read-only field).
        $engineer = $user->engineer;
        if ($engineer && (bool) $engineer->{EngineerProfile::IS_SAZE90_USER} !== $isSaze90User) {
            $engineer->forceFill([EngineerProfile::IS_SAZE90_USER => $isSaze90User])->save();
        }

        // 3) badge_user pivot → make this action's managed badges equal the current set,
        //    leaving any externally-managed badges untouched. This runs on every getProfile,
        //    so only WRITE when something actually changed (otherwise just two cheap reads).
        $managedIds = Badge::whereIn(Badge::CODE, self::MANAGED_CODES)->pluck(Badge::ID);
        $desiredIds = $badges->pluck(Badge::ID);
        $currentManaged = $user->badges()->whereIn('badges.id', $managedIds)->pluck('badges.id');

        $toDetach = $currentManaged->diff($desiredIds)->all();
        $toAttach = $desiredIds->diff($currentManaged)->all();
        if ($toDetach) {
            $user->badges()->detach($toDetach);
        }
        if ($toAttach) {
            $user->badges()->attach($toAttach);
        }

        return [
            'badge' => $isTier ? $sazeCode : ($isSaze90User ? 'saze90' : null),
            'is_pioneer' => $isPioneer,
            'is_saze90_user' => $isSaze90User,
            'badges' => $badges,
        ];
    }
}
