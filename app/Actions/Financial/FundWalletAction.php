<?php

namespace App\Actions\Financial;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class FundWalletAction
{
    /**
     * ایجاد درخواست افزایش موجودی (واریز یا فیش)
     * موجودی کیف پول افزایش نمی‌یابد مگر اینکه وضعیت CONFIRMED باشد.
     *
     * ‏`$status` قرینهٔ همان پارامتر در `DebitWalletAction` است، با پیش‌فرضِ
     * معکوس: فیشِ کاربر PENDING است (ادمین باید وجودِ واریزِ بانکی را تأیید
     * کند)، ولی یک **انتقالِ داخلی** — پولی که سیستم همین حالا از کیفِ دیگری
     * کسر کرده، مثل بازپرداختِ بدهی — فیشی برای تأیید ندارد و باید CONFIRMED
     * باشد. یک ردیفِ PENDING در آن حالت هرگز تأیید نمی‌شود و پولِ کسرشده
     * بی‌صدا نابود می‌شود؛ `LedgerReconciler` هم آن را نمی‌بیند، چون
     * (deposit / pending) عمداً «صفر اثر» مدل شده است.
     */
    public function execute(
        User $user,
        float $amount,
        string $paymentMethod,
        ?string $description = null,
        ?UploadedFile $receiptFile = null,
        array $extraMeta = [],
        string $status = WalletTransaction::STATUS_PENDING
    ): WalletTransaction {
        return DB::transaction(function () use ($user, $amount, $paymentMethod, $description, $receiptFile, $extraMeta, $status) {

            // 1. دریافت یا ساخت کیف پول
            $wallet = $user->wallet()->firstOrCreate([]);
            $wallet = $user->wallet()->where('id', $wallet->id)->lockForUpdate()->first();

            // ‏`array_merge` و نه بازسازیِ از صفر: پیش از این هر کلیدِ دیگری در
            // ‏`$extraMeta` بی‌صدا دور ریخته می‌شد، پس نشانه‌گذاریِ فراخوان
            // (مثلاً `kind => debt_repayment`) فقط روی سمتِ بدهکار می‌ماند و
            // ردیفِ بستانکار قابلِ ردیابی به آن رویداد نبود.
            $meta = array_merge($extraMeta, [
                'payment_method' => $paymentMethod,
                'receipt_info' => $extraMeta['receipt_info'] ?? [],
            ]);

            // 2. مدیریت آپلود فایل (اگر فایل ارسال شده باشد)
            if ($receiptFile) {
                $originalName = $receiptFile->getClientOriginalName();

                // Private disk — financial receipts must not be reachable via a public URL.
                $path = $receiptFile->store(WalletTransaction::PATH_RECEIPTS, 'financial');

                $meta['receipt_path'] = $path;
                $meta['receipt_info']['image_path'] = $path;
                $meta['receipt_info']['original_name'] = $originalName;
            }

            // 3. اثرِ موجودی — فقط برای ردیفِ CONFIRMED، دقیقاً مطابقِ همان قاعده‌ای
            // که `LedgerReconciler::balanceExpression()` بازپخش می‌کند
            // ‏(deposit/confirmed → +amount، deposit/pending → ۰).
            if ($status === WalletTransaction::STATUS_CONFIRMED) {
                $wallet->increment(Wallet::BALANCE, $amount);
            }

            // 4. ایجاد تراکنش
            $transaction = $wallet->transactions()->create([
                WalletTransaction::AMOUNT => $amount,
                WalletTransaction::TYPE => WalletTransaction::TYPE_DEPOSIT,
                WalletTransaction::STATUS => $status,
                WalletTransaction::DESCRIPTION => $description ?? __('wallet.deposit'),
                WalletTransaction::META => $meta,
            ]);

            return $transaction;
        });
    }
}
