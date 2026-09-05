<?php

namespace App\Actions\Financial;

use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class FundWalletAction
{
    /**
     * ایجاد درخواست افزایش موجودی (واریز یا فیش).
     *
     * پیش‌فرض `PENDING` است — یک **درخواستِ** واریز که تا تأییدِ ادمین موجودی را
     * تکان نمی‌دهد. این برای فیشِ بانکی درست است.
     *
     * ⚠️ ولی `$status` تا امروز اصلاً پارامتر نبود، و همین یک باگِ مالیِ واقعی
     * ساخت: `UserDebtController::repay()` این اکشن را برای برگرداندنِ پول به
     * کیف پولِ سازه صدا می‌زد — با این تصور که «اعتبار» می‌دهد. نتیجه:
     * کیف پولِ بدهکار **فوراً** کسر می‌شد (`DebitWalletAction` با
     * `STATUS_CONFIRMED`) ولی کیف پولِ سازه فقط یک ردیفِ `deposit/pending`
     * می‌گرفت و موجودی‌اش تکان نمی‌خورد. بدهی هم بسته می‌شد. یعنی پول از سیستم
     * **ناپدید** می‌شد.
     *
     * و `financial:doctor` هم نمی‌دیدش: `LedgerReconciler` برای `deposit/pending`
     * سهمِ صفر در نظر می‌گیرد، پس **هر دو** کیف پول با دفترِ خودشان می‌خواندند.
     * ناترازی بین‌کیف‌پولی بود نه درون‌کیف‌پولی.
     *
     * حالا قرینه‌ی `DebitWalletAction` است: همان پارامترِ `$status` با همان
     * معنا، و وقتی `CONFIRMED` باشد موجودی هم واقعاً بالا می‌رود.
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

            $meta = [
                'payment_method' => $paymentMethod,
                'receipt_info' => $extraMeta['receipt_info'] ?? [],
            ];

            // 2. مدیریت آپلود فایل (اگر فایل ارسال شده باشد)
            if ($receiptFile) {
                $originalName = $receiptFile->getClientOriginalName();

                // Private disk — financial receipts must not be reachable via a public URL.
                $path = $receiptFile->store(WalletTransaction::PATH_RECEIPTS, 'financial');

                $meta['receipt_path'] = $path;
                $meta['receipt_info']['image_path'] = $path;
                $meta['receipt_info']['original_name'] = $originalName;
            }

            // ۳. موجودی فقط با وضعیتِ تأییدشده بالا می‌رود — همان قاعده‌ای که
            // `LedgerReconciler` هنگام replay فرض می‌کند (`deposit/pending` سهمِ
            // صفر دارد). پس نوشتنِ ردیف و بالا بردنِ موجودی باید **با هم** و بر
            // اساس یک شرط انجام شوند، وگرنه دفتر و مانده از هم جدا می‌افتند.
            if ($status === WalletTransaction::STATUS_CONFIRMED) {
                $wallet->increment('balance', $amount);
            }

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
