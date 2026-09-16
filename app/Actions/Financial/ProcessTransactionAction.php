<?php

namespace App\Actions\Financial;

use App\Events\Financial\TransactionProcessed;
use App\Exceptions\BusinessException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Exception;
use Illuminate\Support\Facades\DB;

class ProcessTransactionAction
{
    public function __construct(private readonly SettleWithdrawalAction $settleWithdrawal) {}

    /**
     * پردازش نهایی تراکنش (تایید یا رد) توسط ادمین
     *
     * @param  string  $newStatus  وضعیت جدید (CONFIRMED یا REJECTED)
     * @param  string|null  $note  یادداشت ادمین
     * @param  User  $admin  کاربری که عملیات را انجام می‌دهد
     */
    public function execute(WalletTransaction $transaction, string $newStatus, ?string $note, User $admin, ?float $verifiedAmount = null): WalletTransaction
    {
        // ۱. اعتبارسنجی اولیه: تراکنش باید در وضعیت "انتظار" باشد
        if ($transaction->status !== WalletTransaction::STATUS_PENDING) {
            throw new Exception(__('admin.wallet.already_processed'));
        }

        // ۲. اعتبارسنجی وضعیت ورودی
        if (! in_array($newStatus, [WalletTransaction::STATUS_CONFIRMED, WalletTransaction::STATUS_REJECTED])) {
            throw new Exception(__('orders.invalid_transaction_status'));
        }

        // ۲.۵ تأیید یک «برداشت» = شلیک تسویه‌ی بانکی پادیوم. این تماس HTTP خارجی است و
        // باید بیرون از DB::transaction انجام شود (هرگز قفل دیتابیس روی تماس بانکی نگه
        // داشته نمی‌شود). SettleWithdrawalAction ماشین‌حالت قفل/HTTP/قفل خودش را دارد و در
        // صورت موفقیت تراکنش را CONFIRMED می‌کند. «رد کردنِ» برداشت همچنان از مسیر اتمیک
        // پایین (بازگشت وجه به کیف پول) عبور می‌کند.
        if ($transaction->type === WalletTransaction::TYPE_WITHDRAW
            && $newStatus === WalletTransaction::STATUS_CONFIRMED) {
            return $this->settleWithdrawal->execute($transaction, $admin, $note);
        }

        // ۳. اجرای عملیات در یک تراکنش دیتابیس (اتمیک)
        return DB::transaction(function () use ($transaction, $newStatus, $note, $admin, $verifiedAmount) {

            // Re-fetch + re-check the transaction status UNDER a row lock. The pre-check
            // above runs on a possibly-stale model outside any lock, so two concurrent or
            // retried admin confirmations could both pass it and credit the wallet twice.
            // Admin routes have no idempotency middleware, so this guard is the only thing
            // preventing a double-credit (mirrors ProcessPaymentCallbackAction::settle).
            $locked = WalletTransaction::where('id', $transaction->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== WalletTransaction::STATUS_PENDING) {
                throw new Exception(__('admin.wallet.already_processed'));
            }

            // ⚠️ برداشتی که تسویه‌ی بانکی‌اش در جریان است قابل رد نیست.
            //
            // ردیف تا پایانِ فاز C در `SettleWithdrawalAction` عمداً PENDING می‌ماند، پس
            // گاردِ بالا جلوی ردِ هم‌زمان را نمی‌گیرد: ادمینِ دوم وجه را به کیف پول
            // برمی‌گرداند در حالی که پادیوم همان پول را به شبا می‌فرستد. همان قاعده‌ی
            // claim و TTLِ مسیرِ تأیید (`hasLiveClaim`) اینجا هم گارد است — زیرِ قفل.
            if ($locked->type === WalletTransaction::TYPE_WITHDRAW
                && $newStatus === WalletTransaction::STATUS_REJECTED
                && $this->settleWithdrawal->hasLiveClaim($locked)) {
                throw new BusinessException(__('wallet.settlement.reject_while_in_progress'), 409);
            }

            // قفل کردن کیف پول کاربر برای جلوگیری از تداخل (Race Condition)
            $wallet = Wallet::where('id', $locked->wallet_id)->lockForUpdate()->first();

            $amountAdjusted = false;

            // الف) سناریوی تایید واریز: پول به حساب کاربر اضافه می‌شود.
            if ($locked->type === WalletTransaction::TYPE_DEPOSIT && $newStatus === WalletTransaction::STATUS_CONFIRMED) {
                // اگر ادمین مبلغِ واقعیِ رسید را تأیید کرده (verified_amount)، همان ملاک است —
                // نه مبلغِ خوداظهارِ کاربر. در نبودِ آن، رفتارِ قبلی حفظ می‌شود.
                $creditAmount = $locked->amount;
                if ($verifiedAmount !== null && $verifiedAmount > 0) {
                    $amountAdjusted = ((float) $verifiedAmount !== (float) $locked->amount);
                    $creditAmount = $verifiedAmount;
                }

                $wallet->increment(Wallet::BALANCE, $creditAmount);
            }

            // ب) سناریوی رد برداشت: پول مسدود شده باید به حساب کاربر برگردد
            // (چون وقتی کاربر درخواست برداشت می‌دهد، پول همان لحظه کسر می‌شود)
            elseif ($locked->type === WalletTransaction::TYPE_WITHDRAW && $newStatus === WalletTransaction::STATUS_REJECTED) {
                $wallet->increment(Wallet::BALANCE, $locked->amount);
            }

            // ج) سایر حالات: نیاز به تغییر موجودی نیست (فقط تغییر وضعیت)

            // ۴. بروزرسانی تراکنش — اگر مبلغ توسط ادمین اصلاح شده، همان را هم ثبت کن.
            $updatePayload = [
                WalletTransaction::STATUS => $newStatus,
                WalletTransaction::DESCRIPTION => $locked->description.($note ? " | یادداشت ادمین: {$note}" : ''),
            ];
            if ($amountAdjusted) {
                $original = $locked->amount;
                $updatePayload[WalletTransaction::AMOUNT] = $verifiedAmount;
                $updatePayload[WalletTransaction::DESCRIPTION] .= " | مبلغ توسط ادمین از {$original} به {$verifiedAmount} اصلاح شد";
            }
            $locked->update($updatePayload);

            // ۵. ثبت لاگ مدیریتی (برای پیگیری اینکه کدام ادمین این کار را کرد)
            activity()
                ->performedOn($locked)
                ->causedBy($admin)
                ->withProperties([
                    'old_status' => WalletTransaction::STATUS_PENDING,
                    'new_status' => $newStatus,
                    'amount' => $locked->amount,
                    'note' => $note,
                ])
                ->log('وضعیت تراکنش توسط ادمین تغییر یافت.');

            // ۶. خبر دادن به سیستم (ارسال پیامک و...)
            TransactionProcessed::dispatch($locked);

            return $locked;
        });
    }
}
