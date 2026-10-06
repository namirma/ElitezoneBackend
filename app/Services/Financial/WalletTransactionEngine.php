<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Constants\FinancialCodes;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletTransactionEngine
{
    // ─────────────────────────────────────────────────────────────────────────
    // Typed public entry points — wallet code is expressed in the method name.
    //
    // userId resolution:
    //   WO (buyer)   → order->payerUserId()  (payer_user_id, falling back to placer)
    //   WS (supplier)→ $supplierId (required; lives on items, not the order row)
    //   WSSG (saze)  → config('financial.system_user_id')
    //   WEY (consult)→ $eliteYARId (required; two possible order columns)
    //   WVAT (vat)   → config('financial.vat_user_id', system_user_id)
    // ─────────────────────────────────────────────────────────────────────────

    // WO از `payerUserId()` می‌آید و نه از `placer_user_id` (فاز ۶). برای هر
    // سفارشِ معمولی و هر سفارشِ پیش از فاز ۶ این دو یکی هستند؛ فرقشان فقط در
    // مسیرِ «الیت‌یار برای کارفرما» ظاهر می‌شود، جایی که پول باید از کیف پولِ
    // کارفرما برداشته شود و استرداد هم به همان‌جا برگردد.
    public function debitBuyer(Order $order, float $amount, string $status, ?string $description = null): void
    {
        $this->process('-', $amount, FinancialCodes::WALLET_OWNER, $status, $order, $description, $order->payerUserId());
    }

    public function creditBuyer(Order $order, float $amount, string $status, ?string $description = null): void
    {
        $this->process('+', $amount, FinancialCodes::WALLET_OWNER, $status, $order, $description, $order->payerUserId());
    }

    public function debitSupplier(Order $order, float $amount, string $status, int $supplierId, ?string $description = null): void
    {
        $this->process('-', $amount, FinancialCodes::WALLET_SUPPLIER, $status, $order, $description, $supplierId);
    }

    public function creditSupplier(Order $order, float $amount, string $status, int $supplierId, ?string $description = null): void
    {
        $this->process('+', $amount, FinancialCodes::WALLET_SUPPLIER, $status, $order, $description, $supplierId);
    }

    public function debitSystem(Order $order, float $amount, string $status, ?string $description = null): void
    {
        $this->process('-', $amount, FinancialCodes::WALLET_SAZE, $status, $order, $description, (int) config('financial.system_user_id', 1));
    }

    public function creditSystem(Order $order, float $amount, string $status, ?string $description = null): void
    {
        $this->process('+', $amount, FinancialCodes::WALLET_SAZE, $status, $order, $description, (int) config('financial.system_user_id', 1));
    }

    public function debitEliteYAR(Order $order, float $amount, string $status, int $eliteYARId, ?string $description = null): void
    {
        $this->process('-', $amount, FinancialCodes::WALLET_ELITE_YAR, $status, $order, $description, $eliteYARId);
    }

    public function creditEliteYAR(Order $order, float $amount, string $status, int $eliteYARId, ?string $description = null): void
    {
        $this->process('+', $amount, FinancialCodes::WALLET_ELITE_YAR, $status, $order, $description, $eliteYARId);
    }

    /** کیف پول کوپن‌های تخفیف (WCPN) — سهمِ شرکت از کوپن (مدیریت، دورِ پنجم). */
    public function debitCoupon(Order $order, float $amount, string $status, ?string $description = null): void
    {
        $this->process('-', $amount, FinancialCodes::WALLET_COUPON, $status, $order, $description, app(CouponWallet::class)->requireUserId());
    }

    public function creditCoupon(Order $order, float $amount, string $status, ?string $description = null): void
    {
        $this->process('+', $amount, FinancialCodes::WALLET_COUPON, $status, $order, $description, app(CouponWallet::class)->requireUserId());
    }

    public function debitVat(Order $order, float $amount, string $status, ?string $description = null): void
    {
        $vatUserId = (int) config('financial.vat_user_id', config('financial.system_user_id', 1));
        $this->process('-', $amount, FinancialCodes::WALLET_VAT, $status, $order, $description, $vatUserId);
    }

    public function creditVat(Order $order, float $amount, string $status, ?string $description = null): void
    {
        $vatUserId = (int) config('financial.vat_user_id', config('financial.system_user_id', 1));
        $this->process('+', $amount, FinancialCodes::WALLET_VAT, $status, $order, $description, $vatUserId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal implementation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * پردازش تراکنش کیف پول با مکانیزم قفل (Pessimistic Locking)
     */
    private function process(
        string $operation,
        float $amount,
        string $walletCode,
        string $status,
        Order $order,
        ?string $description = null,
        ?int $targetUserId = null
    ): void {
        // گاردِ علامت — پیش از هر چیز. گاردهای «موجودی کافی» پایین‌تر با `<` مقایسه
        // می‌کنند، پس یک مبلغِ منفی از آن‌ها رد می‌شد و معنای عملیات را برعکس می‌کرد:
        // credit با مبلغ منفی موجودی را *کم* می‌کرد (بدون هیچ چک کفایت موجودی) و
        // debit با مبلغ منفی پول *خلق* می‌کرد. همه‌ی فراخوان‌های امروزی خودشان
        // `<= 0` را فیلتر می‌کنند، ولی این invariant جای خودش در موتور است نه در
        // فراخوان‌ها — یک فراخوانِ جدیدِ بی‌گارد نباید بتواند لجر را نامتوازن کند.
        if (! is_finite($amount) || $amount <= 0) {
            throw new \InvalidArgumentException(
                "WalletTransactionEngine: amount must be a positive finite number, got {$amount} (wallet {$walletCode}, order {$order->id})"
            );
        }

        // اجرای کل عملیات در یک تراکنش برای حفظ یکپارچگی داده‌ها
        DB::transaction(function () use ($operation, $amount, $walletCode, $status, $order, $description, $targetUserId) {

            // 1. تعیین کاربر هدف
            $userId = $targetUserId ?? $this->resolveUserId($walletCode, $order);
            $this->assertWalletOwnerExists($walletCode, $userId);

            $wallet = Wallet::firstOrCreate(['user_id' => $userId]);
            $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();

            // 3. تعیین نوع عملیات
            $type = ($operation === '+') ? 'deposit' : 'withdraw';

            $isHold = ($status === FinancialCodes::STATUS_HOLD || $status === 'hold');

            // موجودی‌ها ستون decimal هستند و Eloquent آن‌ها را رشته برمی‌گرداند؛
            // جمع/تفریق با bcmath انجام می‌شود تا با قاعده‌ی «ریاضیِ پول bcmath است»
            // هم‌راستا باشد و هیچ مرحله‌ی میانی به float تبدیل نشود.
            $balance = (string) $wallet->balance;
            $hold = (string) $wallet->balance_hold;
            $delta = Money::s($amount);

            if ($operation === '+') {
                if ($isHold) {
                    $wallet->balance_hold = Money::add($hold, $delta);
                } else {
                    $wallet->balance = Money::add($balance, $delta);
                }
            } else {
                if ($isHold) {
                    if (Money::lt($hold, $delta)) {
                        throw new InsufficientBalanceException($amount, (float) $hold);
                    }
                    $wallet->balance_hold = Money::sub($hold, $delta);
                } else {
                    if (Money::lt($balance, $delta)) {
                        throw new InsufficientBalanceException($amount, (float) $balance);
                    }
                    $wallet->balance = Money::sub($balance, $delta);
                }
            }

            $wallet->save();

            // 5. ثبت تراکنش در جدول تاریخچه
            WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'order_id' => $order->id,
                'amount' => $amount,
                'type' => $type,
                'status' => $status,
                'description' => $description,
                // WalletTransaction casts `meta` to array — pass a plain array and let
                // Eloquent encode it once. json_encode() here would double-encode it.
                'meta' => ['code' => $walletCode, 'op' => $operation],
            ]);
        });
    }

    /**
     * Release an escrow hold back to available balance, append-only.
     *
     * Atomically moves `balance_hold → balance` AND records a distinct
     * `TYPE_HOLD_RELEASE` WalletTransaction. Unlike mutating the original hold
     * row's status, this keeps the ledger immutable: the hold (placement) and the
     * release are two timestamped events that net to zero, so the hold history is
     * always reconstructable for audit.
     *
     * @param  WalletTransaction|null  $sourceHold  the original hold row, linked in meta for traceability.
     */
    /**
     * موجودیِ در دسترس → هولد، **درونِ همان کیف پول**. قرینه‌ی `releaseHold`.
     *
     * برای «پول مالِ توست ولی تا ارائه‌ی سند نمی‌توانی برداری» — دقیقاً همان
     * چیزی که مالیاتِ تأمین‌کننده است. پیش از این چنین ابزاری نبود و مالیات با
     * یک debit به کیف پولِ امانیِ جداگانه منتقل می‌شد، یعنی پول از دارایی
     * تأمین‌کننده خارج می‌شد به‌جای اینکه فقط قفل شود.
     */
    public function placeHold(
        int $userId,
        float $amount,
        Order $order,
        ?string $description = null,
    ): WalletTransaction {
        if (! is_finite($amount) || $amount <= 0) {
            throw new \InvalidArgumentException(
                "WalletTransactionEngine::placeHold — amount must be a positive finite number, got {$amount} (order {$order->id})"
            );
        }

        return DB::transaction(function () use ($userId, $amount, $order, $description) {
            $wallet = Wallet::firstOrCreate(['user_id' => $userId]);
            $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();

            // `Money::s()` روی شاخه‌ی L13 وجود ندارد؛ هر سه متد پایین خودشان
            // `int|float|string` می‌پذیرند، پس فایل روی هر دو شاخه یکی می‌ماند.
            $balance = (string) $wallet->balance;

            if (Money::lt($balance, $amount)) {
                throw new InsufficientBalanceException($amount, (float) $balance);
            }

            $wallet->balance = Money::sub($balance, $amount);
            $wallet->balance_hold = Money::add((string) $wallet->balance_hold, $amount);
            $wallet->save();

            return WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'order_id' => $order->id,
                'amount' => $amount,
                'type' => WalletTransaction::TYPE_FEE_HOLD,
                'status' => FinancialCodes::STATUS_HOLD,
                'description' => $description,
                'meta' => ['op' => 'hold_place'],
            ]);
        });
    }

    public function releaseHold(
        int $userId,
        float $amount,
        Order $order,
        ?string $description = null,
        ?WalletTransaction $sourceHold = null,
    ): WalletTransaction {
        if (! is_finite($amount) || $amount <= 0) {
            throw new \InvalidArgumentException(
                "WalletTransactionEngine::releaseHold — amount must be a positive finite number, got {$amount} (order {$order->id})"
            );
        }

        return DB::transaction(function () use ($userId, $amount, $order, $description, $sourceHold) {
            $wallet = Wallet::firstOrCreate(['user_id' => $userId]);
            $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();

            $hold = (string) $wallet->balance_hold;
            $delta = Money::s($amount);

            if (Money::lt($hold, $delta)) {
                throw new InsufficientBalanceException($amount, (float) $hold);
            }

            // bcmath — همان قاعده‌ی process(): هیچ مرحله‌ی میانیِ float روی موجودی.
            $wallet->balance_hold = Money::sub($hold, $delta);
            $wallet->balance = Money::add((string) $wallet->balance, $delta);
            $wallet->save();

            return WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'order_id' => $order->id,
                'amount' => $amount,
                'type' => WalletTransaction::TYPE_HOLD_RELEASE,
                'status' => FinancialCodes::STATUS_AVAILABLE,
                'description' => $description,
                'meta' => [
                    'op' => 'hold_release',
                    'source_hold_id' => $sourceHold?->id,
                ],
            ]);
        });
    }

    /**
     * تشخیص آیدی کاربر بر اساس کد کیف پول
     *
     * Note: callers that know the target user ID up-front should always pass
     * $targetUserId explicitly to process() — this fallback is a safety-net.
     *
     * WALLET_ELITE_YAR → elite_yar_user_id (the eliteYAR/commission earner on the order)
     * WALLET_VAT        → financial.vat_user_id (separate VAT escrow user; P5)
     */
    /**
     * مالکِ کیف پول باید واقعاً وجود داشته باشد، وگرنه اینجا متوقف شو.
     *
     * چرا لازم است: `Wallet::firstOrCreate(['user_id' => $id])` کورکورانه به
     * شناسه‌ی تنظیم‌شده اعتماد می‌کرد. اگر `financial.system_user_id` (یا
     * `vat_user_id`) به کاربری اشاره کند که وجود ندارد — که پیش می‌آید، چون
     * `.env` شناسه‌ی عددی نگه می‌دارد و با هر بار seed شدنِ دیتابیس شناسه‌ها
     * جابه‌جا می‌شوند — insert با نقض کلید خارجی می‌شکست، آن هم **در وسط
     * تراکنشِ پرداخت**. نتیجه برای مشتری: «خطا در پردازش پرداخت» و برای
     * توسعه‌دهنده: یک FK violation خام در لاگ. عملاً هیچ پرداختی در سیستم
     * انجام نمی‌شد و هیچ‌کس نمی‌دانست چرا.
     *
     * حالا پیام می‌گوید کدام کلیدِ کانفیگ خراب است و چه باید کرد.
     */
    private function assertWalletOwnerExists(string $code, int $userId): void
    {
        if ($userId > 0 && User::whereKey($userId)->exists()) {
            return;
        }

        $configKey = match ($code) {
            FinancialCodes::WALLET_SAZE => 'financial.system_user_id (env FINANCIAL_SYSTEM_USER_ID)',
            FinancialCodes::WALLET_VAT => 'financial.vat_user_id (env FINANCIAL_VAT_USER_ID)',
            default => null,
        };

        $detail = $configKey !== null
            ? "wallet {$code} points at user #{$userId} via {$configKey}, but that user does not exist. "
              .'Run `php artisan db:seed --class=PlatformWalletUserSeeder` or fix the env value.'
            : "wallet {$code} resolved to user #{$userId}, which does not exist.";

        Log::channel('financial')->critical('Wallet owner does not exist — financial operation aborted', [
            'wallet_code' => $code,
            'user_id' => $userId,
            'config_key' => $configKey,
        ]);

        throw new \RuntimeException($detail);
    }

    private function resolveUserId(string $code, Order $order): int
    {
        return match ($code) {
            FinancialCodes::WALLET_OWNER => $order->payerUserId(),

            // Supplier user is stored on order items, not the order itself;
            // callers must always pass $targetUserId explicitly for WALLET_SUPPLIER.
            FinancialCodes::WALLET_SUPPLIER => (int) ($order->supplier_user_id
                ?? throw new \RuntimeException("WALLET_SUPPLIER: targetUserId must be supplied explicitly (order {$order->id})")),

            FinancialCodes::WALLET_SAZE => (int) config('financial.system_user_id', 1),

            // EliteYAR commission wallet — the commission earner is elite_yar_user_id
            // (NOT beneficiary_user_id, which is the goods recipient). Callers normally
            // pass $targetUserId explicitly; this fallback must still resolve the correct
            // party or it would credit/debit the wrong wallet.
            FinancialCodes::WALLET_ELITE_YAR => (int) ($order->elite_yar_user_id
                ?? throw new \RuntimeException("WALLET_ELITE_YAR: order {$order->id} has no eliteYAR (elite_yar_user_id is null)")),

            // VAT escrow wallet — uses dedicated vat_user_id when configured (P5),
            // falls back to system_user_id so the system stays correct before
            // the env var is deployed.
            FinancialCodes::WALLET_VAT => (int) config('financial.vat_user_id', config('financial.system_user_id', 1)),

            default => throw new \RuntimeException("کد کیف پول ناشناخته: $code"),
        };
    }
}
