<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Models\DebtEntry;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * تنها نویسنده‌ی `users.debt_balance` — فاز ۹، بند ۳.۱۲ و ۴.۷ سند.
 *
 * قاعده‌ی مخزن: **هرگز `debt_balance` را مستقیم ننویسید.** هر تغییر باید یک
 * ردیفِ `debt_entries` هم بگذارد، وگرنه مانده و دفتر از هم جدا می‌افتند و هیچ
 * ابزاری نمی‌تواند بگوید کدام درست است — همان دلیلی که `WalletTransactionEngine`
 * برای کیف پول‌ها وجود دارد.
 *
 * حساب پول با `Money` (bcmath) انجام می‌شود، نه float خام: مانده یک ستونِ
 * decimal است و Eloquent آن را رشته برمی‌گرداند.
 */
class DebtEngine
{
    /**
     * ثبت بدهی تازه. مانده و دفتر در یک تراکنش و زیرِ قفلِ ردیفِ کاربر.
     *
     * @param  string  $type  یکی از ثابت‌های `DebtEntry::TYPE_*`
     */
    public function incur(
        int $userId,
        float $amount,
        string $type,
        ?Order $order = null,
        ?string $description = null,
        ?int $shipmentId = null,
    ): DebtEntry {
        if (! is_finite($amount) || $amount <= 0) {
            throw new \InvalidArgumentException(
                "DebtEngine::incur — amount must be a positive finite number, got {$amount} (user {$userId})"
            );
        }

        return $this->write($userId, $amount, $type, $order, $description, $shipmentId);
    }

    /**
     * بازپرداخت. ردیفِ **تازه** با مبلغ منفی می‌نویسد، ردیفِ قبلی را دست نمی‌زند.
     *
     * مبلغ به مانده‌ی موجود clamp می‌شود: بازپرداختِ بیش از بدهی نباید مانده را
     * منفی کند، چون منفی یعنی «شرکت به کاربر بدهکار است» و آن ادعای دیگری است
     * که جای خودش را دارد (کیف پول).
     */
    public function repay(
        int $userId,
        float $amount,
        ?Order $order = null,
        ?string $description = null,
    ): ?DebtEntry {
        if (! is_finite($amount) || $amount <= 0) {
            throw new \InvalidArgumentException(
                "DebtEngine::repay — amount must be a positive finite number, got {$amount} (user {$userId})"
            );
        }

        return DB::transaction(function () use ($userId, $amount, $order, $description) {
            $current = $this->lockedBalance($userId);

            $applied = Money::lt($current, $amount) ? (float) $current : $amount;

            if ($applied <= 0) {
                return null;
            }

            return $this->write(
                $userId, -$applied, DebtEntry::TYPE_REPAYMENT, $order, $description, null, locked: true
            );
        });
    }

    /** مانده‌ی بدهی کاربر (ریال). */
    public function balanceFor(int $userId): float
    {
        return (float) (User::whereKey($userId)->value(User::DEBT_BALANCE) ?? 0);
    }

    /**
     * آیا این مبلغ بدهی درون سقف اعتبار جا می‌شود؟
     *
     * سند (بند ۳.۱۲): `debt_after ≤ credit_limit` ⇒ سفارش متوقف نمی‌شود؛ در غیر
     * این صورت بدهی ثبت می‌شود **و** سفارش تا پرداخت متوقف می‌ماند. یعنی این
     * متد تصمیمِ «متوقف کن یا نه» را می‌دهد، نه تصمیمِ «ثبت کن یا نه».
     */
    public function fitsWithinCreditLimit(int $userId, float $additionalDebt): bool
    {
        $user = User::whereKey($userId)->first([User::ID, User::DEBT_BALANCE, User::CREDIT_LIMIT]);

        if (! $user) {
            return false;
        }

        $after = Money::add((string) ($user->{User::DEBT_BALANCE} ?? 0), $additionalDebt);

        return ! Money::lt((string) ($user->{User::CREDIT_LIMIT} ?? 0), $after);
    }

    /** بدهکار است؟ گاردِ سفارش تازه و برداشت از کیف پول به این تکیه می‌کند. */
    public function isInDebt(int $userId): bool
    {
        return $this->balanceFor($userId) > 0;
    }

    /**
     * @param  float  $amount  مثبت = ایجاد بدهی، منفی = بازپرداخت
     */
    private function write(
        int $userId,
        float $amount,
        string $type,
        ?Order $order,
        ?string $description,
        ?int $shipmentId,
        bool $locked = false,
    ): DebtEntry {
        $persist = function () use ($userId, $amount, $type, $order, $description, $shipmentId) {
            $current = $this->lockedBalance($userId);

            // مانده هرگز منفی نمی‌شود — `repay()` قبلاً clamp کرده، ولی
            // `adjustment` دستیِ بک‌آفیس هم از همین مسیر می‌گذرد.
            $next = Money::add($current, $amount);
            if (Money::lt($next, '0')) {
                $next = '0';
            }

            User::whereKey($userId)->update([User::DEBT_BALANCE => $next]);

            return DebtEntry::create([
                DebtEntry::USER_ID => $userId,
                DebtEntry::ORDER_ID => $order?->id,
                DebtEntry::SHIPMENT_ID => $shipmentId,
                DebtEntry::TYPE => $type,
                DebtEntry::AMOUNT => $amount,
                DebtEntry::DESCRIPTION => $description,
                DebtEntry::CREATED_BY => auth()->id(),
                DebtEntry::META => ['balance_after' => $next],
            ]);
        };

        return $locked ? $persist() : DB::transaction($persist);
    }

    /**
     * مانده زیرِ قفلِ ردیف. قفل روی `users` گرفته می‌شود چون مانده آنجاست —
     * بدون آن، دو بدهیِ هم‌زمانِ یک کاربر هر دو یک مانده‌ی قدیمی می‌خواندند و
     * دومی اولی را پاک می‌کرد (همان کلاسِ باگی که سقفِ الیت‌یار داشت).
     */
    private function lockedBalance(int $userId): string
    {
        $balance = User::whereKey($userId)->lockForUpdate()->value(User::DEBT_BALANCE);

        if ($balance === null) {
            throw new \RuntimeException("DebtEngine: user {$userId} does not exist.");
        }

        return (string) $balance;
    }
}
