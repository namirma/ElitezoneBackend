<?php

namespace App\Services\Payment\Contracts;

use App\Models\Order;
use App\Models\User;
use App\Services\Payment\PaymentReceipt;

interface PaymentGatewayInterface
{
    /**
     * انجام عملیات پرداخت
     *
     * @return array اطلاعات نتیجه پرداخت (مثل شناسه تراکنش و...)
     *
     * @throws \Exception در صورت خطا
     */
    public function pay(User $user, Order $order): array;

    /**
     * تأیید پرداخت با درگاه پس از بازگشت از بانک (Callback).
     *
     * @param  float  $amount  مبلغ تراکنش (ریال) برای تطبیق سمت سرور
     * @param  string  $authority  توکن/شناسهٔ تأیید بازگشتی از درگاه
     * @return PaymentReceipt|null رسید نتیجهٔ تأیید؛ null یا رسید ناموفق یعنی پرداخت تأیید نشد
     *
     * @throws \Exception در صورت خطای ارتباط با درگاه
     */
    public function verify(float $amount, string $authority): ?PaymentReceipt;

    /**
     * نام درگاه (برای ذخیره در دیتابیس)
     */
    public function getName(): string;

    /**
     * آیا این درگاه واقعاً قابل استفاده است؟
     *
     * `CheckoutController::paymentInfo` فیلدِ `is_active` هر روشِ پرداخت را از
     * همین‌جا می‌خواند. تا پیش از این `is_active` برای هر سه روش عددِ ثابتِ `true`
     * بود، در حالی که درگاهِ کارت بانکی هنوز مجوز نگرفته و `PasargadGateway::pay()`
     * استثنا پرتاب می‌کند — یعنی فرانت دکمه‌ای را فعال نشان می‌داد که کلیک روی آن
     * حتماً شکست می‌خورد.
     *
     * قاعدهٔ `CLAUDE.md` («هرگز متدی از درگاه صدا نزن که interface اعلامش نکرده»)
     * ایجاب می‌کند این متد اینجا اعلام شود، نه فقط روی یک driver.
     */
    public function isConfigured(): bool;
}
