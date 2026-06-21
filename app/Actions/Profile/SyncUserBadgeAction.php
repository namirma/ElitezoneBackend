<?php

namespace App\Actions\Profile;

use App\Models\Badge;
use App\Models\User;
use App\Services\Saze90BadgeService;
use App\Support\PhoneNumber;

/**
 * Resolves a user's badges and persists the commission-relevant one.
 *
 * Sources:
 *  - saze90.com `get-certificate?mobile=` → the collaboration tier (gold/silver/bronze)
 *    or the «کاربر سازه ۹۰» display code. Read via Saze90BadgeService (fail-safe).
 *  - Pioneer (پیشگام) → granted internally to members for their **first year** after
 *    registration. Time-based, NOT from saze90.
 *
 * Persistence is deliberately conservative:
 *  - `users.badge_id` is written ONLY for the recognised commission tiers
 *    (gold/silver/bronze). A null badge_id already maps to pioneer/default rates in
 *    SettlementCalculator, so there is nothing to write for pioneer.
 *  - We never clear an existing tier on a null result (a transient saze90 outage must not
 *    silently downgrade a consultant's commission rate).
 */
class SyncUserBadgeAction
{
    /** Codes SettlementCalculator treats as commission tiers (everything else = default/pioneer). */
    private const TIER_CODES = ['gold', 'silver', 'bronze'];

    public function __construct(private readonly Saze90BadgeService $saze90) {}

    /**
     * @return array{badge: ?string, is_pioneer: bool, badges: array<int,string>}
     */
    public function execute(User $user): array
    {
        $mobile = $user->{User::MOBILE} ? PhoneNumber::to09((string) $user->{User::MOBILE}) : '';
        $sazeCode = $mobile ? $this->saze90->getCertificate($mobile) : null;

        // Pioneer: first year after registration.
        $isPioneer = $user->created_at !== null && $user->created_at->gt(now()->subYear());

        if ($sazeCode && in_array($sazeCode, self::TIER_CODES, true)) {
            $badge = Badge::where(Badge::CODE, $sazeCode)->first();

            if ($badge) {
                if ((int) $user->{User::BADGE_ID} !== (int) $badge->id) {
                    $user->forceFill([User::BADGE_ID => $badge->id])->save();
                }
                // Replace any previously-synced tier (gold↔silver↔bronze) with the current
                // one so the display pivot never accumulates stale tiers; non-tier badges
                // (if ever added) are left untouched.
                $otherTierIds = Badge::whereIn(Badge::CODE, self::TIER_CODES)
                    ->where(Badge::ID, '!=', $badge->id)
                    ->pluck(Badge::ID)
                    ->all();
                $user->badges()->detach($otherTierIds);
                $user->badges()->syncWithoutDetaching([$badge->id]);
            }
        }

        // Display list the frontend renders (pioneer is shown alongside the saze90 tier).
        $badges = [];
        if ($isPioneer) {
            $badges[] = 'pioneer';
        }
        if ($sazeCode) {
            $badges[] = $sazeCode;
        }

        return [
            'badge' => $sazeCode,                       // saze90 tier/code, or null
            'is_pioneer' => $isPioneer,
            'badges' => array_values(array_unique($badges)),
        ];
    }
}
