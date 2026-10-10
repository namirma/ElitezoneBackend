<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Jobs\Notification\SendSmsJob;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * مدیریت، دورِ ششم، پرسشِ ۵ (۱۴۰۵/۰۷/۱۵): وقتی کوپنی به‌خاطرِ کمبودِ «کیف پول کوپن‌های تخفیف» رد شد،
 * به مدیرانِ مالی (ادمین‌های فعالِ دارای مجوزِ `manage-financial`) پیامک + اعلان. یک بار در هر
 * `financial.coupon_wallet_alert_hours` ساعت — هر خریدارِ ردشده هشدارِ تازه نمی‌سازد.
 */
class CouponWalletLowAlert
{
    public const THROTTLE_KEY = 'coupon_wallet_low_alert';

    public function __construct(private readonly CouponWallet $wallet) {}

    public function notify(float $needed): void
    {
        $hours = max(1, (int) config('financial.coupon_wallet_alert_hours', 6));

        if (! Cache::add(self::THROTTLE_KEY, true, now()->addHours($hours))) {
            return;
        }

        $params = [
            'amount' => enToFa(number_format($needed)),
            'balance' => enToFa(number_format($this->wallet->balance())),
        ];

        $admins = User::query()
            ->whereHasRole('admin')
            ->whereHasPermission('manage-financial')
            ->where(fn ($q) => $q->whereNull(User::IS_ACTIVE)->orWhere(User::IS_ACTIVE, true))
            ->get();

        foreach ($admins as $admin) {
            $personal = $params + ['first_name' => SmsTemplate::firstNameFor((string) $admin->{User::MOBILE})];

            SendSmsJob::template((string) $admin->{User::MOBILE}, 'coupon_wallet_low_admin', $personal);

            UserNotification::create([
                UserNotification::USER_ID => $admin->id,
                UserNotification::SUBJECT => __('coupons.coupon_wallet_low_subject'),
                UserNotification::BODY => (string) SmsTemplate::text('coupon_wallet_low_admin', $personal, (string) $admin->{User::MOBILE}),
            ]);
        }

        activity()
            ->withProperties(['needed' => $needed, 'balance' => $this->wallet->balance(), 'notified_admin_ids' => $admins->pluck('id')->all()])
            ->log('هشدارِ کمبودِ موجودیِ کیف پول کوپن‌های تخفیف به مدیرانِ مالی ارسال شد.');

        Log::channel('financial')->warning('Coupon wallet too low for a coupon; finance admins alerted', [
            'needed' => $needed,
            'admins' => $admins->count(),
        ]);
    }
}
