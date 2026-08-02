<?php

namespace App\Services\Payment\Gateways;

use App\Models\Order;
use App\Models\User;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\PaymentReceipt;
use Illuminate\Support\Facades\Log;

class ShaparakGateway implements PaymentGatewayInterface
{
    public function getName(): string
    {
        return 'shaparak';
    }

    /**
     * هیچ‌وقت پیکربندی‌شده نیست: این driver داربستِ باقی‌مانده از مسیرِ شاپرکی است
     * که هرگز وصل نشد — `verify()` همیشه رسیدِ ناموفق می‌دهد و `pay()` شماره‌ی
     * پیگیریِ ساختگی برمی‌گرداند. تا وقتی اینتگریشنِ واقعی نیامده، اعلام کردنِ
     * «فعال» یعنی فرستادنِ کاربر به مسیری که قطعاً شکست می‌خورد.
     */
    public function isConfigured(): bool
    {
        return false;
    }

    public function pay(User $user, Order $order): array
    {
        // اینجا معمولاً درخواست به بانک زده می‌شود و توکن گرفته می‌شود
        // اما چون در اکشن PayOrder هستیم، فرض بر این است که پرداخت تایید شده
        // یا اینکه اینجا فقط لاگ می‌کنیم.

        return [
            'transaction_id' => null,
            'reference_id' => 'SH-'.random_int(10000, 99999), // شماره پیگیری فرضی
        ];
    }

    /**
     * تأیید پرداخت با بانک.
     *
     * TODO: اینتگریشن واقعی درگاه (پاسارگاد/شاپرک) هنوز پیاده‌سازی نشده — طبق سند
     * «پروسه خرید» منتظر دریافت مجوز درگاه پاسارگاد هستیم. تا آن زمان هیچ پرداختی
     * قابل تأیید نیست، پس به‌جای ایجاد Fatal Error (متد ناموجود)، رسید ناموفق
     * برمی‌گردانیم تا کال‌بک به‌درستی تراکنش را failed کند و هرگز اعتبار جعلی
     * به کیف پول کاربر اضافه نشود.
     */
    public function verify(float $amount, string $authority): ?PaymentReceipt
    {
        Log::warning('ShaparakGateway::verify called but no real gateway is configured yet.', [
            'amount' => $amount,
            'authority' => $authority,
        ]);

        return PaymentReceipt::failed();
    }
}
