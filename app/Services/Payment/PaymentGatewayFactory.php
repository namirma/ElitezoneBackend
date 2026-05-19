<?php

namespace App\Services\Payment;

use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\Gateways\ShaparakGateway;
use App\Services\Payment\Gateways\WalletGateway;
use Exception;

class PaymentGatewayFactory
{
    public function make(string $gatewayName): PaymentGatewayInterface
    {
        return match ($gatewayName) {
            'wallet' => app(WalletGateway::class),
            'shaparak' => app(ShaparakGateway::class),
            // اضافه کردن درگاه‌های جدید فقط همین‌جا انجام می‌شود
            default => throw new Exception(__('orders.errors.gateway_not_implemented')),
        };
    }
}
