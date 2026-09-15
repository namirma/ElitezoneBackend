<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Exceptions\BusinessException;
use App\Models\Coupon;
use App\Models\CouponFunder;
use App\Models\User;
use App\Services\Notifier;
use Illuminate\Support\Facades\DB;

/**
 * پیشنهاد و پاسخِ تأمین‌کننده‌های یک کوپن — `docs/specs/COUPON_FUNDING_2026-09-15.md` §۳.۱ و §۳.۲.
 *
 * پول این‌جا جابه‌جا نمی‌شود؛ آن کارِ `CouponFundingService` است. این کلاس فقط
 * «چه کسی پذیرفته» را نگه می‌دارد و به طرفین خبر می‌دهد.
 */
class CouponFunderService
{
    /**
     * جایگزینیِ کاملِ ردیف‌ها (قراردادِ `syncOrderUnits`): ردیفی که (نوع، کاربر، درصد)ش
     * عوض نشده وضعیتش را نگه می‌دارد؛ ردیفِ تازه pending است مگر ردیفِ خودِ سازنده
     * (تأمین‌کننده/الیت‌یار برای خودش) یا ردیفِ شرکت وقتی ادمین می‌سازد.
     *
     * @param  list<array{funder_type: string, funder_user_id: int|null, share_percent: float}>  $rows
     */
    public function sync(Coupon $coupon, array $rows, User $actor, bool $actorIsAdmin): void
    {
        if ($coupon->{Coupon::USED_AT} !== null) {
            throw new BusinessException(__('coupons.used_locked'), 422);
        }

        DB::transaction(function () use ($coupon, $rows, $actor, $actorIsAdmin) {
            $existing = $coupon->funders()->get()->keyBy(
                fn (CouponFunder $f) => $f->{CouponFunder::FUNDER_TYPE}.':'.($f->{CouponFunder::FUNDER_USER_ID} ?? '')
            );

            $keep = [];
            $notify = [];

            foreach ($rows as $row) {
                $key = $row['funder_type'].':'.($row['funder_user_id'] ?? '');
                $current = $existing->get($key);

                if ($current && abs((float) $current->{CouponFunder::SHARE_PERCENT} - $row['share_percent']) < 0.001) {
                    $keep[] = $current->id;

                    continue;
                }

                $autoAccept = $row['funder_type'] === CouponFunder::TYPE_COMPANY
                    ? $actorIsAdmin
                    : (int) $row['funder_user_id'] === (int) $actor->id;

                $funder = $coupon->funders()->updateOrCreate(
                    [
                        CouponFunder::FUNDER_TYPE => $row['funder_type'],
                        CouponFunder::FUNDER_USER_ID => $row['funder_user_id'],
                    ],
                    [
                        CouponFunder::SHARE_PERCENT => $row['share_percent'],
                        CouponFunder::STATUS => $autoAccept ? CouponFunder::STATUS_ACCEPTED : CouponFunder::STATUS_PENDING,
                        CouponFunder::RESPONDED_AT => $autoAccept ? now() : null,
                    ]
                );

                $keep[] = $funder->id;

                if (! $autoAccept && $row['funder_user_id'] !== null) {
                    $notify[] = $funder;
                }
            }

            $coupon->funders()->whereNotIn(CouponFunder::ID, $keep)->delete();

            // پیامک + زنگوله به هر تأمین‌کننده‌ی تازه‌پیشنهادشده — بعد از commit، تا
            // لیسنرِ صف ردیفِ commit‌نشده نبیند (همان قاعده‌ی رویدادهای دامنه).
            DB::afterCommit(function () use ($notify, $coupon, $actor) {
                foreach ($notify as $funder) {
                    $this->notifyProposed($funder, $coupon, $actor);
                }
            });
        });
    }

    /** پذیرش/ردِ یک ردیف توسط صاحبش (یا ادمین برای ردیفِ شرکت). */
    public function respond(CouponFunder $funder, bool $accept): void
    {
        if (! $funder->isPending()) {
            throw new BusinessException(__('coupons.funder_already_responded'), 409);
        }

        $funder->forceFill([
            CouponFunder::STATUS => $accept ? CouponFunder::STATUS_ACCEPTED : CouponFunder::STATUS_DECLINED,
            CouponFunder::RESPONDED_AT => now(),
        ])->save();

        $coupon = $funder->coupon;
        $creator = $coupon->creator;

        // سازنده‌ی بی‌نام (کوپنِ قدیمی) خبری نمی‌گیرد؛ کسی که خودش پاسخ داده هم نه.
        if (! $creator || (int) $creator->id === (int) ($funder->{CouponFunder::FUNDER_USER_ID} ?? 0)) {
            return;
        }

        $key = $accept ? 'coupon_funding_accepted' : 'coupon_funding_declined';

        Notifier::template(
            $creator,
            __('notifications.'.$key.'_subject'),
            $key,
            [
                'funder' => $funder->displayName(),
                'percent' => $this->percent($funder),
                'code' => $coupon->code,
            ],
        );
    }

    private function notifyProposed(CouponFunder $funder, Coupon $coupon, User $proposer): void
    {
        $user = $funder->funder;

        if (! $user) {
            return;
        }

        // نامِ جعلی نه: پیشنهاددهنده‌ی بی‌پروفایل با شماره‌اش معرفی می‌شود.
        $proposerName = $proposer->profile ? trim((string) $proposer->full_name) : '';

        Notifier::template(
            $user,
            __('notifications.coupon_funding_requested_subject'),
            'coupon_funding_requested',
            [
                'proposer' => $proposerName !== '' && $proposerName !== 'کاربر ناشناس' ? $proposerName : (string) $proposer->mobile,
                'percent' => $this->percent($funder),
                'code' => $coupon->code,
            ],
        );
    }

    private function percent(CouponFunder $funder): string
    {
        return rtrim(rtrim(number_format((float) $funder->{CouponFunder::SHARE_PERCENT}, 2, '.', ''), '0'), '.');
    }
}
