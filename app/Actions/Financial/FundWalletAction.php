<?php

namespace App\Actions\Financial;

use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class FundWalletAction
{
    /**
     * ایجاد درخواست افزایش موجودی (واریز یا فیش)
     * موجودی کیف پول افزایش نمی‌یابد مگر اینکه وضعیت CONFIRMED باشد.
     */
    public function execute(
        User $user,
        float $amount,
        string $paymentMethod,
        ?string $description = null,
        ?UploadedFile $receiptFile = null,
        array $extraMeta = []
    ): WalletTransaction {
        return DB::transaction(function () use ($user, $amount, $paymentMethod, $description, $receiptFile, $extraMeta) {

            // 1. دریافت یا ساخت کیف پول
            $wallet = $user->wallet()->firstOrCreate([]);

            $meta = [
                'payment_method' => $paymentMethod,
                'receipt_info' => $extraMeta['receipt_info'] ?? [],
            ];

            // 2. مدیریت آپلود فایل (اگر فایل ارسال شده باشد)
            if ($receiptFile) {
                $originalName = $receiptFile->getClientOriginalName();

                $path = $receiptFile->store(WalletTransaction::PATH_RECEIPTS, 'public');

                $meta['receipt_path'] = $path;
                $meta['receipt_info']['image_path'] = $path;
                $meta['receipt_info']['original_name'] = $originalName;
            }

            // 3. ایجاد تراکنش (با وضعیت Pending)
            $transaction = $wallet->transactions()->create([
                WalletTransaction::AMOUNT => $amount,
                WalletTransaction::TYPE => WalletTransaction::TYPE_DEPOSIT,
                WalletTransaction::STATUS => WalletTransaction::STATUS_PENDING,
                WalletTransaction::DESCRIPTION => $description ?? __('wallet.deposit'),
                WalletTransaction::META => $meta,
            ]);

            return $transaction;
        });
    }
}
