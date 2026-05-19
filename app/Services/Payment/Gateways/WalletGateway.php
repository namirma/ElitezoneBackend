<?php

namespace App\Services\Payment\Gateways;

use App\Actions\Financial\ProcessPreScalePaymentAction;
use App\Exceptions\Wallet\InsufficientBalanceException;
use App\Exceptions\Wallet\WalletNotFoundException;
use App\Models\Order;
use App\Models\User;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use Illuminate\Support\Str;

class WalletGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly ProcessPreScalePaymentAction $processPaymentAction
    ) {}

    public function getName(): string
    {
        return 'wallet';
    }

    public function pay(User $user, Order $order): array
    {
        // ۱. اعتبارسنجی مبلغ سفارش
        $amount = (float) $order->final_amount;
        if ($amount <= 0) {
            throw new \InvalidArgumentException(__('orders.errors.invalid_amount'));
        }

        // ۲. دریافت کیف پول با قفل دیتابیس برای جلوگیری از Race Condition
        $wallet = $user->wallet()->lockForUpdate()->first();

        if (! $wallet) {
            throw new WalletNotFoundException(__('wallet.errors.not_found'));
        }

        // ۳. بررسی موجودی
        if ((float) $wallet->balance < $amount) {
            throw new InsufficientBalanceException(__('wallet.errors.insufficient_balance'));
        }

        // ۴. اجرای فلوی مالی کامل (کسر از خریدار، تقسیم بین سازه و سوپلایر)
        // این Action خودش idempotent هست (در صورتی که فیکس‌های گزارش قبلی اعمال بشه)
        $this->processPaymentAction->execute($order);

        // ۵. بازگشت اطلاعات تراکنش
        return [
            'transaction_id' => null, // کیف پول تراکنش گیت‌وی خارجی نداره
            'reference_id' => 'WALLET-'.Str::upper(Str::random(16)),
            'gateway' => $this->getName(),
            'paid_at' => now()->toIso8601String(),
        ];
    }
}
