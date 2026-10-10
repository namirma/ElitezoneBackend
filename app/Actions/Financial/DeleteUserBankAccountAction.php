<?php

namespace App\Actions\Financial;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Models\UserBankAccount;
use App\Models\UserNotification;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * حذفِ حسابِ بانکیِ یک کاربر — فقط از بک‌آفیس (علی ۱۴۰۵/۰۷/۱۹: کاربر به هیچ راهی
 * حسابِ ثبت‌شده‌اش را حذف نمی‌کند).
 *
 * - **نرم**: برداشت‌های گذشته `meta.bank_account_id` را نگه می‌دارند؛ ردیف می‌ماند.
 * - **نه وقتی برداشتی به این حساب در انتظار است** — پولش هنوز به این شبا در راه است
 *   و ادمینِ مالی باید اول آن را تأیید یا رد کند.
 * - **پیش‌فرض جابه‌جا نمی‌شود**: اگر این حساب پیش‌فرض بود، کاربر بی‌پیش‌فرض می‌ماند
 *   تا خودش انتخاب کند؛ سیستم مقصدِ پولِ کسی را بی‌صدا عوض نمی‌کند.
 * - ردِ ممیزی روی کاربر + اعلانِ درون‌برنامه‌ای به او (پیامک نه: متنِ پیامک با مدیریت است).
 */
final class DeleteUserBankAccountAction
{
    public function execute(UserBankAccount $account, User $admin, string $reason): void
    {
        $userId = (int) $account->{UserBankAccount::USER_ID};

        // همان قفلِ `RequestWithdrawalAction`: بینِ چکِ «برداشتِ در انتظار» و حذف،
        // برداشتِ تازه‌ای به این حساب ثبت نمی‌شود.
        Cache::lock(RequestWithdrawalAction::lockKey($userId), 10)->block(10, function () use ($account, $admin, $reason, $userId) {
            DB::transaction(function () use ($account, $admin, $reason, $userId) {
                // حذف‌شده‌ها را scopeِ SoftDeletes کنار می‌گذارد → دو حذفِ هم‌زمان: دومی ۴۰۴.
                $locked = UserBankAccount::query()->lockForUpdate()->findOrFail($account->getKey());

                if ($this->hasPendingWithdrawal($locked, $userId)) {
                    throw new BusinessException(__('bank.admin_delete_pending_withdrawal'), 422);
                }

                $wasDefault = (bool) $locked->{UserBankAccount::IS_DEFAULT};
                if ($wasDefault) {
                    // بی‌صدا: ردِ ممیزیِ پایین `was_default` را دارد؛ لاگِ «ویرایش»ِ خالی فقط نویز بود.
                    $locked->forceFill([UserBankAccount::IS_DEFAULT => false])->saveQuietly();
                }
                $locked->delete();

                $last4 = substr((string) ($locked->{UserBankAccount::SHABA} ?? $locked->{UserBankAccount::CARD_NUMBER}), -4);

                UserNotification::create([
                    UserNotification::USER_ID => $userId,
                    UserNotification::SUBJECT => __('bank.admin_deleted_subject'),
                    UserNotification::BODY => __('bank.admin_deleted_body', [
                        'bank' => $locked->{UserBankAccount::BANK_NAME},
                        'last4' => enToFa($last4),
                    ]),
                ]);

                activity()
                    ->performedOn($locked->user)
                    ->causedBy($admin)
                    ->withProperties([
                        'bank_account_id' => $locked->getKey(),
                        'bank_name' => $locked->{UserBankAccount::BANK_NAME},
                        'last4' => $last4,
                        'was_default' => $wasDefault,
                        'reason' => $reason,
                    ])
                    ->log('حساب بانکی کاربر توسط بک‌آفیس حذف شد.');
            });
        });
    }

    private function hasPendingWithdrawal(UserBankAccount $account, int $userId): bool
    {
        return WalletTransaction::query()
            ->whereIn(WalletTransaction::WALLET_ID, Wallet::query()->where(Wallet::USER_ID, $userId)->select(Wallet::ID))
            ->where(WalletTransaction::TYPE, WalletTransaction::TYPE_WITHDRAW)
            ->where(WalletTransaction::STATUS, WalletTransaction::STATUS_PENDING)
            ->where(WalletTransaction::META.'->bank_account_id', $account->getKey())
            ->exists();
    }
}
