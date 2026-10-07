<?php

namespace App\Exceptions;

use App\Services\Financial\CouponWalletLowAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * کوپن رد شد چون «کیف پول کوپن‌های تخفیف» سهمِ شرکت را نمی‌پوشاند — مدیریت، دورِ ششم، پرسشِ ۵:
 * «کوپن رد شود و به مدیرِ مالی هم خبر داده شود که کیف پول کم است.»
 *
 * هشدار در `render` است، نه هنگامِ پرتاب: رد در وسطِ تراکنشِ ثبتِ سفارش رخ می‌دهد و اعلانی که آن‌جا
 * نوشته شود همراهِ همان تراکنش rollback می‌شود. `render` فقط وقتی صدا می‌شود که استثنا از همه‌ی
 * تراکنش‌ها بیرون آمده. پیش‌نمایشِ سبد که خودش استثنا را می‌گیرد `alertFinance()` را مستقیم صدا می‌زند.
 */
class CouponWalletShortException extends BusinessException
{
    public function __construct(string $message, private readonly float $needed = 0.0)
    {
        parent::__construct($message, 422);
    }

    public function alertFinance(): void
    {
        app(CouponWalletLowAlert::class)->notify($this->needed);
    }

    public function render(Request $request): JsonResponse
    {
        $this->alertFinance();

        return parent::render($request);
    }
}
