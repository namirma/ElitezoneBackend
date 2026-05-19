<?php

namespace App\Services\Payment\Gateways;

use App\Models\Order;
use App\Models\User;
use App\Services\Payment\Contracts\PaymentGatewayInterface;

class ShaparakGateway implements PaymentGatewayInterface
{
    public function getName(): string
    {
        return 'shaparak';
    }

    public function pay(User $user, Order $order): array
    {
        // اینجا معمولاً درخواست به بانک زده می‌شود و توکن گرفته می‌شود
        // اما چون در اکشن PayOrder هستیم، فرض بر این است که پرداخت تایید شده
        // یا اینکه اینجا فقط لاگ می‌کنیم.

        return [
            'transaction_id' => null,
            'reference_id' => 'SH-'.rand(10000, 99999), // شماره پیگیری فرضی
        ];
    }
}
