<?php

declare(strict_types=1);

namespace App\Services\Payment;

/**
 * نتیجه‌ی درخت تصمیم پرداخت — بند ۳.۳ سند بازطراحی.
 *
 * سه شاخه، و **گزینه‌ی رزرو فقط در شاخه‌ی سوم دیده می‌شود**. این نکته‌ی
 * ظریفِ بند ۳.۳ است: تا وقتی خریدار می‌تواند کل مبلغ را بپردازد، رزرو اصلاً
 * پیشنهاد نمی‌شود — رزرو راهِ گریز از ناتوانیِ پرداخت است، نه یک انتخابِ
 * دلخواه که هر کسی بتواند با آن موجودی را قفل کند.
 */
final class PaymentBranch
{
    /** الف — کل مبلغ از کیف پول. */
    public const WALLET = 'wallet';

    /** ب — درگاه برای کسری، سپس تسویه از کیف پول. */
    public const GATEWAY = 'gateway';

    /** ج — فقط رزرو. */
    public const RESERVE = 'reserve';

    public function __construct(
        public readonly string $branch,
        /** مبلغ نهایی سفارش پس از کوپن (ریال). */
        public readonly float $finalAmount,
        /** موجودی فعلی کیف پول (ریال). */
        public readonly float $walletBalance,
        /** چقدر از کیف پول برداشته می‌شود. */
        public readonly float $walletAmount,
        /** چقدر باید از درگاه شارژ شود — همیشه اول به کیف پول می‌رود (بند ۳.۳). */
        public readonly float $gatewayAmount,
        /** مبلغ بیعانه؛ فقط در شاخه‌ی رزرو معنا دارد. */
        public readonly float $reserveAmount,
    ) {}

    /** گزینه‌ی رزرو فقط در شاخه‌ی ج نمایش داده می‌شود. */
    public function reserveAvailable(): bool
    {
        return $this->branch === self::RESERVE;
    }

    public function toArray(): array
    {
        return [
            'branch' => $this->branch,
            'final_amount' => (string) $this->finalAmount,
            'wallet_balance' => (string) $this->walletBalance,
            'wallet_amount' => (string) $this->walletAmount,
            'gateway_amount' => (string) $this->gatewayAmount,
            'reserve_amount' => (string) $this->reserveAmount,
            'reserve_available' => $this->reserveAvailable(),
        ];
    }
}
