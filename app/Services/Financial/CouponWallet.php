<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Constants\FinancialCodes;
use App\Exceptions\BusinessException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * «کیف پول کوپن‌های تخفیف» (WCPN) — پاسخِ مدیریت به پرسش‌های ۲ تا ۴ِ دورِ پنجم (۱۴۰۵/۰۷/۱۴).
 *
 * «میزانِ کلی و منبعِ مالیِ تخفیفِ کوپن در بک‌آفیس توسطِ شرکت جداگانه مشخص شده و در کیف پولِ
 * جدیدی به نامِ کیف پولِ کوپن‌های تخفیف قرار می‌گیرد. مادام که این کیف پول منابع دارد، تخفیف به
 * خریدارانِ دارای کوپنِ فعال داده می‌شود.» — یعنی سهمِ **شرکت** از هر کوپن فقط از این کیف پول
 * پرداخت می‌شود (نه از WSSG) و موجودیِ آن خودِ «بودجه»ی کلِ کوپن‌هاست.
 *
 * مالک: یک حسابِ سیستمیِ غیرقابلِ ورود با موبایلِ رزروشده‌ی `0990000003` (همان الگوی
 * `PlatformWalletUserSeeder`). شناسه از کانفیگ (`financial.coupon_wallet_user_id`) و در نبودش از
 * همین موبایل پیدا می‌شود — شناسه‌ها با هر seed جابه‌جا می‌شوند، موبایل نه.
 */
class CouponWallet
{
    public const RESERVED_MOBILE = '0990000003';

    public function userId(): ?int
    {
        $configured = config('financial.coupon_wallet_user_id');
        if ($configured !== null && $configured !== '' && User::whereKey((int) $configured)->exists()) {
            return (int) $configured;
        }

        $id = User::where(User::MOBILE, self::RESERVED_MOBILE)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /** شناسه‌ی مالک یا ۴۲۲ِ روشن — کوپنِ شرکتی بی‌کیف‌پولِ پیکربندی‌شده تأمین نمی‌شود. */
    public function requireUserId(): int
    {
        return $this->userId() ?? throw new BusinessException(__('coupons.coupon_wallet_missing'), 422);
    }

    public function balance(): float
    {
        $userId = $this->userId();

        return $userId === null ? 0.0 : (float) (Wallet::where(Wallet::USER_ID, $userId)->value(Wallet::BALANCE) ?? 0);
    }

    /**
     * شارژِ کیف پولِ کوپن از کیف پولِ سازه (WSSG → WCPN). منبع و توضیح در ردِ ممیزی و meta.
     *
     * @return array{balance: string}
     */
    public function fund(User $admin, float $amount, string $note): array
    {
        return $this->move($admin, $amount, $note, toCoupon: true);
    }

    /** برگشتِ مانده‌ی کیف پولِ کوپن به سازه (WCPN → WSSG). */
    public function defund(User $admin, float $amount, string $note): array
    {
        return $this->move($admin, $amount, $note, toCoupon: false);
    }

    /** @return array{balance: string} */
    private function move(User $admin, float $amount, string $note, bool $toCoupon): array
    {
        if (! is_finite($amount) || $amount <= 0) {
            throw new BusinessException(__('coupons.coupon_wallet_amount_invalid'), 422);
        }

        $couponUserId = $this->requireUserId();
        $systemUserId = (int) config('financial.system_user_id', 1);
        [$fromUser, $toUser] = $toCoupon ? [$systemUserId, $couponUserId] : [$couponUserId, $systemUserId];
        [$fromCode, $toCode] = $toCoupon
            ? [FinancialCodes::WALLET_SAZE, FinancialCodes::WALLET_COUPON]
            : [FinancialCodes::WALLET_COUPON, FinancialCodes::WALLET_SAZE];

        DB::transaction(function () use ($admin, $amount, $note, $fromUser, $toUser, $fromCode, $toCode, $toCoupon) {
            // قفل به ترتیبِ شناسه — دو انتقالِ هم‌زمانِ مخالف‌جهت بن‌بست نسازند.
            foreach ([$fromUser, $toUser] as $id) {
                Wallet::firstOrCreate([Wallet::USER_ID => $id]);
            }
            $wallets = Wallet::whereIn(Wallet::USER_ID, [$fromUser, $toUser])
                ->orderBy('id')->lockForUpdate()->get()->keyBy(Wallet::USER_ID);
            $from = $wallets[$fromUser];
            $to = $wallets[$toUser];

            if (Money::lt((string) $from->balance, Money::s($amount))) {
                throw new BusinessException(__($toCoupon ? 'coupons.coupon_wallet_system_insufficient' : 'coupons.coupon_wallet_insufficient', [
                    'amount' => number_format($amount),
                    'balance' => number_format((float) $from->balance),
                ]), 422);
            }

            $from->balance = Money::sub((string) $from->balance, Money::s($amount));
            $to->balance = Money::add((string) $to->balance, Money::s($amount));
            $from->save();
            $to->save();

            $description = $toCoupon
                ? __('coupons.coupon_wallet_funded_description', ['note' => $note])
                : __('coupons.coupon_wallet_defunded_description', ['note' => $note]);

            foreach ([[$from, WalletTransaction::TYPE_WITHDRAW, $fromCode, '-'], [$to, WalletTransaction::TYPE_DEPOSIT, $toCode, '+']] as [$wallet, $type, $code, $op]) {
                WalletTransaction::create([
                    'wallet_id' => $wallet->id,
                    'order_id' => null,
                    'amount' => $amount,
                    'type' => $type,
                    'status' => WalletTransaction::STATUS_CONFIRMED,
                    'description' => $description,
                    'meta' => ['code' => $code, 'op' => $op, 'kind' => 'coupon_wallet_transfer', 'note' => $note, 'admin_id' => $admin->id],
                ]);
            }

            activity()
                ->causedBy($admin)
                ->withProperties(['amount' => $amount, 'note' => $note, 'direction' => $toCoupon ? 'fund' : 'defund'])
                ->log($toCoupon ? 'کیف پول کوپن‌های تخفیف شارژ شد.' : 'از کیف پول کوپن‌های تخفیف به کیف پول سازه برگشت داده شد.');
        });

        // رشته، مثلِ `show` و بقیه‌ی مبالغِ API — یک فیلد، یک شکل.
        return ['balance' => (string) $this->balance()];
    }
}
