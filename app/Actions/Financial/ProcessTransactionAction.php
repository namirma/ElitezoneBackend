<?php

namespace App\Actions\Financial;

use App\Events\Financial\TransactionProcessed;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Exception;
use Illuminate\Support\Facades\DB;

class ProcessTransactionAction
{
    /**
     * پردازش نهایی تراکنش (تایید یا رد) توسط ادمین
     *
     * @param  string  $newStatus  وضعیت جدید (CONFIRMED یا REJECTED)
     * @param  string|null  $note  یادداشت ادمین
     * @param  User  $admin  کاربری که عملیات را انجام می‌دهد
     */
    public function execute(WalletTransaction $transaction, string $newStatus, ?string $note, User $admin): WalletTransaction
    {
        // ۱. اعتبارسنجی اولیه: تراکنش باید در وضعیت "انتظار" باشد
        if ($transaction->status !== WalletTransaction::STATUS_PENDING) {
            throw new Exception(__('admin.wallet.already_processed'));
        }

        // ۲. اعتبارسنجی وضعیت ورودی
        if (! in_array($newStatus, [WalletTransaction::STATUS_CONFIRMED, WalletTransaction::STATUS_REJECTED])) {
            throw new Exception('وضعیت جدید نامعتبر است.');
        }

        // ۳. اجرای عملیات در یک تراکنش دیتابیس (اتمیک)
        return DB::transaction(function () use ($transaction, $newStatus, $note, $admin) {

            // قفل کردن کیف پول کاربر برای جلوگیری از تداخل (Race Condition)
            $wallet = Wallet::where('id', $transaction->wallet_id)->lockForUpdate()->first();

            // الف) سناریوی تایید واریز: پول به حساب کاربر اضافه می‌شود
            if ($transaction->type === WalletTransaction::TYPE_DEPOSIT && $newStatus === WalletTransaction::STATUS_CONFIRMED) {
                $wallet->increment(Wallet::BALANCE, $transaction->amount);
            }

            // ب) سناریوی رد برداشت: پول مسدود شده باید به حساب کاربر برگردد
            // (چون وقتی کاربر درخواست برداشت می‌دهد، پول همان لحظه کسر می‌شود)
            elseif ($transaction->type === WalletTransaction::TYPE_WITHDRAW && $newStatus === WalletTransaction::STATUS_REJECTED) {
                $wallet->increment(Wallet::BALANCE, $transaction->amount);
            }

            // ج) سایر حالات: نیاز به تغییر موجودی نیست (فقط تغییر وضعیت)

            // ۴. بروزرسانی تراکنش
            $transaction->update([
                WalletTransaction::STATUS => $newStatus,
                WalletTransaction::DESCRIPTION => $transaction->description.($note ? " | یادداشت ادمین: {$note}" : ''),
            ]);

            // ۵. ثبت لاگ مدیریتی (برای پیگیری اینکه کدام ادمین این کار را کرد)
            activity()
                ->performedOn($transaction)
                ->causedBy($admin)
                ->withProperties([
                    'old_status' => WalletTransaction::STATUS_PENDING,
                    'new_status' => $newStatus,
                    'amount' => $transaction->amount,
                    'note' => $note,
                ])
                ->log('وضعیت تراکنش توسط ادمین تغییر یافت.');

            // ۶. خبر دادن به سیستم (ارسال پیامک و...)
            TransactionProcessed::dispatch($transaction);

            return $transaction;
        });
    }
}
