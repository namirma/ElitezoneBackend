<?php

namespace App\Services\Payment;

/**
 * نتیجه‌ی تأیید (verify) یک پرداخت توسط درگاه.
 *
 * این value object قرارداد مشترکی است که ProcessPaymentCallbackAction روی آن
 * تکیه می‌کند: isSuccessful() و getReferenceId(). پیش از این هیچ درگاهی این متدها
 * را نداشت و فراخوانی verify() در کال‌بک باعث Fatal Error می‌شد.
 */
class PaymentReceipt
{
    public function __construct(
        private readonly bool $successful,
        private readonly ?string $referenceId = null,
    ) {}

    /** رسید موفق همراه با شماره پیگیری بانک. */
    public static function success(string $referenceId): self
    {
        return new self(true, $referenceId);
    }

    /** رسید ناموفق (پرداخت تأیید نشد). */
    public static function failed(): self
    {
        return new self(false, null);
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function getReferenceId(): ?string
    {
        return $this->referenceId;
    }
}
