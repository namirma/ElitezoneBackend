<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\User;
use App\Models\Wallet;
use App\Services\Sales\SalesSettings;
use App\Support\Money;

/**
 * درخت تصمیم پرداخت — بند ۳.۳. **منبع واحد برای بک‌اند و فرانت.**
 *
 * تصمیم فقط سه ورودی دارد: مبلغ نهایی (پس از کوپن)، موجودی کیف پول، و سقف یک
 * تراکنش درگاه:
 *
 *   Final ≤ Wallet                      → الف: کسر از کیف پول، بدون گزینه‌ی رزرو
 *   Final ≤ Wallet + Shaparak           → ب: درگاه برای مابه‌التفاوت، بدون رزرو
 *   Final > Wallet + Shaparak           → ج: فقط رزرو
 *
 * ⚠️ معیار، **توان پرداخت همین کاربر** است و نه یک آستانه‌ی مبلغیِ سراسری.
 * مدل قبلی `financial.reservation_threshold` را با اندازه‌ی سفارش می‌سنجید،
 * یعنی سفارشِ بزرگ برای میلیاردرِ سایت هم اجباراً رزرو می‌شد و سفارشِ کوچک
 * برای کاربرِ بی‌پول هم رزرو نمی‌شد. حالا همان سفارش برای دو کاربر دو شاخه‌ی
 * متفاوت می‌گیرد، که همان چیزی است که بند ۱ سند وعده داده.
 *
 * پول درگاه **همیشه اول به کیف پول** می‌رود و سفارش از کیف پول تسویه می‌شود
 * (بند ۳.۳)، پس `gateway_amount` یک شارژ است نه پرداختِ مستقیمِ سفارش.
 *
 * پوششِ تست: tests/Feature/Payment/PaymentBranchResolverTest.php
 */
final class PaymentBranchResolver
{
    public function __construct(
        private readonly SalesSettings $settings,
    ) {}

    public function resolve(?User $user, float $finalAmount): PaymentBranch
    {
        $finalAmount = max(0.0, $finalAmount);
        $wallet = $this->walletBalance($user);
        $shaparak = $this->settings->shaparakValue();

        // مقایسه‌ها با Money انجام می‌شوند نه float خام (قید ۱۴.۱): روی مقادیرِ
        // میلیاردی، خطای IEEE-754 می‌تواند یک برابریِ دقیق را به «یک ریال
        // بیشتر» تبدیل کند و شاخه را عوض کند. مرزها دقیقاً `≤` هستند.
        if (Money::cmp($finalAmount, $wallet) <= 0) {
            return new PaymentBranch(
                branch: PaymentBranch::WALLET,
                finalAmount: $finalAmount,
                walletBalance: $wallet,
                walletAmount: $finalAmount,
                gatewayAmount: 0.0,
                reserveAmount: 0.0,
            );
        }

        if (Money::cmp($finalAmount, Money::add($wallet, $shaparak)) <= 0) {
            return new PaymentBranch(
                branch: PaymentBranch::GATEWAY,
                finalAmount: $finalAmount,
                walletBalance: $wallet,
                // کیف پول تا ته خرج می‌شود و بقیه از درگاه شارژ می‌شود.
                walletAmount: $wallet,
                gatewayAmount: Money::toFloat(Money::sub($finalAmount, $wallet)),
                reserveAmount: 0.0,
            );
        }

        // شاخه‌ی ج — بیعانه خودش از سقف درگاه بیشتر نیست (`reserveValue()` در
        // لحظه‌ی خواندن clamp می‌شود)، پس همیشه قابل پرداخت است: یا از کیف پول،
        // یا با یک شارژِ درگاهِ زیرِ سقف.
        $reserve = $this->settings->reserveValue();

        return new PaymentBranch(
            branch: PaymentBranch::RESERVE,
            finalAmount: $finalAmount,
            walletBalance: $wallet,
            walletAmount: min($wallet, $reserve),
            gatewayAmount: max(0.0, Money::toFloat(Money::sub($reserve, $wallet))),
            reserveAmount: $reserve,
        );
    }

    /**
     * موجودی از خودِ رابطه خوانده می‌شود و نه از خاصیتِ جادوییِ `$user->wallet`.
     *
     * دو دلیل: larastan 3 روی شاخه‌ی L13 آن خاصیت را نمی‌شناسد، و مهم‌تر اینکه
     * تصمیمِ پرداخت باید روی موجودیِ **تازه** گرفته شود — نه روی رابطه‌ای که
     * شاید چند کوئری قبل‌تر لود شده باشد.
     */
    private function walletBalance(?User $user): float
    {
        if ($user === null) {
            return 0.0;
        }

        return (float) ($user->wallet()->value(Wallet::BALANCE) ?? 0);
    }
}
