<?php

namespace App\Services\Payment\Gateways;

use App\Actions\Financial\ProcessPreScalePaymentAction;
use App\Exceptions\Wallet\InsufficientBalanceException;
use App\Models\Order;
use App\Models\User;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use Exception;

class WalletGateway implements PaymentGatewayInterface
{
    public function getName(): string
    {
        return 'wallet';
    }

    public function pay(User $user, Order $order): array
    {
        // ۱. دریافت کیف پول کاربر با قفل دیتابیس (برای جلوگیری از تراکنش‌های همزمان)
        $wallet = $user->wallet()->lockForUpdate()->first();

        if (!$wallet) {
            throw new Exception(__('wallet.errors.not_found'));
        }

        // ۲. بررسی موجودی کیف پول خریدار
        if ($wallet->balance < $order->final_amount) {
            throw new InsufficientBalanceException(__('wallet.errors.insufficient_balance'));
        }

        app(ProcessPreScalePaymentAction::class)->execute($order);

        // ۴. بازگشت رسید موفقیت‌آمیز (چون تراکنش‌های کیف پول Reference بانکی ندارند، یک شناسه یونیک تولید می‌کنیم)
        return [
            'transaction_id' => null,
            'reference_id'   => 'WALLET-' . uniqid(),
        ];
    }
}
