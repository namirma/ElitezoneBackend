<?php

namespace App\Services\Payment\Contracts;

use App\Models\Order;
use App\Models\User;

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
     * نام درگاه (برای ذخیره در دیتابیس)
     */
    public function getName(): string;
}
