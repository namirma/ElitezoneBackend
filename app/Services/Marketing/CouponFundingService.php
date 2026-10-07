<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Constants\FinancialCodes;
use App\Exceptions\BusinessException;
use App\Exceptions\CouponWalletShortException;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Coupon;
use App\Models\CouponFunder;
use App\Models\CouponFundingLeg;
use App\Models\Order;
use App\Models\Wallet;
use App\Services\Financial\CouponWallet;
use App\Services\Financial\WalletTransactionEngine;
use Illuminate\Support\Collection;

/**
 * تأمینِ تخفیفِ کوپن از سه منبع — `docs/specs/COUPON_FUNDING_2026-09-15.md` §۳.۳ و §۳.۴.
 *
 * دو ورودی و یک قرینه:
 *  - `plan()`   — لحظه‌ی ثبت سفارش: تقسیمِ تخفیف به لِگ‌ها + گاردهای تطابق، سقف و
 *                 موجودی. **چیزی نمی‌نویسد**؛ هر تخطی یک `BusinessException(422)` با
 *                 پیامِ دقیقِ فارسی است که هرگز خریدار را مقصر نمی‌گوید.
 *  - `debitFunders()` — لحظه‌ی پرداخت: همان تقسیم، این‌بار از کیف پولِ هر تأمین‌کننده
 *                 کم می‌شود و یک ردیفِ `coupon_funding_legs` می‌ماند.
 *  - `reverseLegs()`  — استرداد: هر لِگ به همان کیف پول برمی‌گردد.
 *
 * تقسیم یک‌جا نوشته شده (`split()`) تا برنامه و برداشت نتوانند دو جواب بدهند.
 */
class CouponFundingService
{
    public function __construct(
        private readonly WalletTransactionEngine $walletEngine,
    ) {}

    /**
     * @param  array{company: float, elite_yar: float}  $caps  سقفِ خالصِ شرکت و الیت‌یار در این سفارش
     * @return list<array{funder: CouponFunder, amount: float}>
     *
     * @throws BusinessException
     */
    public function plan(Coupon $coupon, float $discount, array $caps, ?int $supplierId, ?int $eliteYARId): array
    {
        if (! $coupon->isFundingReady()) {
            throw new BusinessException(__('coupons.funding_not_ready'), 422);
        }

        $this->assertFundersMatch($coupon, $supplierId, $eliteYARId);

        $legs = $this->split($coupon->fundingRows(), $discount);

        foreach ($legs as ['funder' => $funder, 'amount' => $amount]) {
            if ($amount <= 0) {
                continue;
            }

            $name = $funder->displayName();
            $share = $this->percentLabel($funder);

            switch ($funder->{CouponFunder::FUNDER_TYPE}) {
                case CouponFunder::TYPE_SUPPLIER:
                    $this->assertWalletCovers($funder, $amount, $name, $share);
                    break;

                case CouponFunder::TYPE_ELITE_YAR:
                    if ($amount > $caps['elite_yar']) {
                        throw new BusinessException(__('coupons.elite_yar_cap_exceeded', [
                            'funder' => $name,
                            'amount' => number_format($amount),
                            'cap' => number_format($caps['elite_yar']),
                        ]), 422);
                    }
                    $this->assertWalletCovers($funder, $amount, $name, $share);
                    break;

                default:
                    $this->assertCompanyCanFund($coupon, $amount, (float) $caps['company']);
            }
        }

        return $legs;
    }

    /**
     * سهمِ شرکت — پاسخِ مدیریت به پرسشِ ۱۰ (۱۴۰۵/۰۷/۱۳، گزینه‌ی ب): سقفِ «≤ سهمِ شرکت از همین
     * سفارش» برداشته شد (با آن کوپنِ ۵٪ِ بازگشتِ مشتری عملاً غیرقابل‌استفاده بود)؛ به‌جایش
     * **بودجه‌ی کمپین** کنترلِ هزینه است. دو گارد می‌ماند:
     *  ۱. کمپین: سهمِ شرکت از این سفارش ≤ باقی‌مانده‌ی بودجه.
     *  ۲. پولِ واقعی: آنچه از استخرِ همین سفارش (POO − PTS) بیشتر است از **موجودیِ** کیف پولِ
     *     سازه (WSSG) می‌رود؛ اگر نباشد همین‌جا با پیامِ روشن رد می‌شود، نه هنگامِ پرداخت با
     *     «موجودی کافی نیست»ی که خریدار آن را از آنِ خودش می‌پندارد.
     */
    private function assertCompanyCanFund(Coupon $coupon, float $amount, float $poolShare): void
    {
        if ($coupon->hasBudgetCap() && $amount > $coupon->budgetRemaining()) {
            throw new BusinessException(__('coupons.budget_exhausted_for_order', [
                'amount' => number_format($amount),
                'remaining' => number_format($coupon->budgetRemaining()),
            ]), 422);
        }

        // مدیریت، دورِ پنجم (۱۴۰۵/۰۷/۱۴): سهمِ شرکت از کوپن **فقط** از «کیف پول کوپن‌های تخفیف»؛
        // «مادام که این کیف پول دارای منابع است» — نه از WSSG و نه از استخرِ همین سفارش.
        // `$poolShare` دیگر نقشی ندارد و فقط برای سازگاریِ امضای `plan()` مانده است.
        unset($poolShare);
        $wallet = app(CouponWallet::class);
        $wallet->requireUserId();

        if ($wallet->balance() < $amount) {
            // دورِ ششم، پرسشِ ۵: رد + خبر به مدیرانِ مالی (در `render`، پس از rollback).
            throw new CouponWalletShortException(__('coupons.coupon_wallet_empty', [
                'amount' => number_format($amount),
            ]), $amount);
        }
    }

    /** سهمِ شرکت از یک تخفیف، با همان تقسیمِ `split()` — برای کم/زیاد کردنِ بودجه‌ی کمپین. */
    public function companyAmountFor(Coupon $coupon, float $discount): float
    {
        $company = 0.0;
        foreach ($this->split($coupon->fundingRows(), $discount) as ['funder' => $funder, 'amount' => $amount]) {
            if ($funder->{CouponFunder::FUNDER_TYPE} === CouponFunder::TYPE_COMPANY) {
                $company += $amount;
            }
        }

        return $company;
    }

    /**
     * هویتِ تأمین‌کننده‌ها با این سفارش می‌خواند؟ — بخشِ **بدونِ عدد**ِ `plan()`.
     *
     * جدا شده تا پیش‌نمایش (`CartCheckoutTotals`, `payment-options`) همان چیزی را
     * بگوید که ثبتِ سفارش می‌گوید: کوپنی که الیت‌یارِ دیگری تأمینش می‌کند روی سبدِ
     * بی‌الیت‌یار «۰ ریال قابل پرداخت» نشان می‌داد و تازه لحظه‌ی پرداخت رد می‌شد
     * (QA 2026-09-15). سقف و موجودی همچنان فقط در `plan()` سنجیده می‌شوند —
     * به اعدادِ سفارش نیاز دارند.
     *
     * @throws BusinessException
     */
    public function assertFundersMatch(Coupon $coupon, ?int $supplierId, ?int $eliteYARId): void
    {
        foreach ($coupon->fundingRows() as $funder) {
            $name = $funder->displayName();

            switch ($funder->{CouponFunder::FUNDER_TYPE}) {
                case CouponFunder::TYPE_SUPPLIER:
                    if ((int) $funder->{CouponFunder::FUNDER_USER_ID} !== (int) $supplierId) {
                        throw new BusinessException(__('coupons.funder_supplier_mismatch', ['funder' => $name]), 422);
                    }
                    break;

                case CouponFunder::TYPE_ELITE_YAR:
                    if ($eliteYARId === null || (int) $funder->{CouponFunder::FUNDER_USER_ID} !== (int) $eliteYARId) {
                        throw new BusinessException(__('coupons.funder_elite_yar_mismatch', ['funder' => $name]), 422);
                    }
                    break;
            }
        }
    }

    /**
     * لِگ‌های تأمین را از کیف پولِ هر تأمین‌کننده کم می‌کند. باید بعد از لِگِ PTS
     * و داخلِ همان تراکنش با سفارشِ قفل‌شده صدا زده شود (§۳.۴). سفارشِ بی‌کوپن → هیچ.
     */
    public function debitFunders(Order $order, float $discount): void
    {
        // `Order::coupon()` جنریک ندارد و larastan آن را `Model` می‌بیند؛ `instanceof`
        // هم گاردِ واقعیِ null است هم تنگ‌سازیِ تایپ.
        $coupon = $order->coupon;

        if (! $coupon instanceof Coupon || $discount <= 0) {
            return;
        }

        foreach ($this->split($coupon->fundingRows(), $discount) as ['funder' => $funder, 'amount' => $amount]) {
            if ($amount <= 0) {
                continue;
            }

            $description = __('coupons.funding_leg_description', [
                'share' => $this->percentLabel($funder),
                'code' => $coupon->code,
                'order' => $order->order_number,
            ]);

            try {
                match ($funder->{CouponFunder::FUNDER_TYPE}) {
                    CouponFunder::TYPE_SUPPLIER => $this->walletEngine->debitSupplier(
                        $order, $amount, FinancialCodes::STATUS_AVAILABLE,
                        (int) $funder->{CouponFunder::FUNDER_USER_ID}, $description
                    ),
                    CouponFunder::TYPE_ELITE_YAR => $this->walletEngine->debitEliteYAR(
                        $order, $amount, FinancialCodes::STATUS_AVAILABLE,
                        (int) $funder->{CouponFunder::FUNDER_USER_ID}, $description
                    ),
                    default => $this->walletEngine->debitCoupon($order, $amount, FinancialCodes::STATUS_AVAILABLE, $description),
                };
            } catch (InsufficientBalanceException) {
                // سهمِ شرکت از «کیف پول کوپن‌های تخفیف» است (مدیریت، دورِ پنجم)؛ اگر بینِ ثبت و
                // پرداخت خالی شد، پیام همان کیف پول را بگوید، نه «کیف پول شرکت سازه».
                if ($funder->isCompany()) {
                    throw new CouponWalletShortException(__('coupons.coupon_wallet_empty', [
                        'amount' => number_format($amount),
                    ]), $amount);
                }

                // پیامِ موتور «موجودی کیف پول کافی نیست» است و `CheckoutController` آن
                // را به خریدار نشان می‌دهد — در حالی که کیف پولِ **تأمین‌کننده** خالی است.
                throw new BusinessException(__('coupons.funder_wallet_insufficient', [
                    'funder' => $funder->displayName(),
                    'share' => $this->percentLabel($funder),
                    'amount' => number_format($amount),
                ]), 422);
            }

            CouponFundingLeg::create([
                CouponFundingLeg::ORDER_ID => $order->id,
                CouponFundingLeg::COUPON_ID => $coupon->id,
                CouponFundingLeg::COUPON_FUNDER_ID => $funder->exists ? $funder->id : null,
                CouponFundingLeg::FUNDER_TYPE => $funder->{CouponFunder::FUNDER_TYPE},
                CouponFundingLeg::FUNDER_USER_ID => $funder->isCompany() ? null : (int) $funder->{CouponFunder::FUNDER_USER_ID},
                CouponFundingLeg::SHARE_PERCENT => $funder->{CouponFunder::SHARE_PERCENT},
                CouponFundingLeg::AMOUNT => $amount,
                CouponFundingLeg::WALLET_CODE => $funder->isCompany() ? FinancialCodes::WALLET_COUPON : null,
            ]);
        }
    }

    /**
     * برگشتِ لِگ‌های یک سفارش به همان کیف پول‌ها (استرداد). `false` یعنی این سفارش
     * لِگی ندارد (پیش از این قابلیت پرداخت شده) و فراخوان باید مسیرِ قدیمی را برود.
     */
    public function reverseLegs(Order $order): bool
    {
        $legs = CouponFundingLeg::where(CouponFundingLeg::ORDER_ID, $order->id)
            ->whereNull(CouponFundingLeg::REVERSED_AT)
            ->get();

        if ($legs->isEmpty()) {
            return CouponFundingLeg::where(CouponFundingLeg::ORDER_ID, $order->id)->exists();
        }

        $coupon = $order->coupon;
        $code = $coupon instanceof Coupon ? (string) $coupon->code : '-';

        foreach ($legs as $leg) {
            $amount = (float) $leg->{CouponFundingLeg::AMOUNT};
            $description = __('coupons.funding_leg_reversed_description', ['code' => $code, 'order' => $order->order_number]);

            $this->walletEngine->debitBuyer($order, $amount, FinancialCodes::STATUS_AVAILABLE, $description);

            match ($leg->{CouponFundingLeg::FUNDER_TYPE}) {
                CouponFunder::TYPE_SUPPLIER => $this->walletEngine->creditSupplier(
                    $order, $amount, FinancialCodes::STATUS_AVAILABLE, (int) $leg->{CouponFundingLeg::FUNDER_USER_ID}, $description
                ),
                CouponFunder::TYPE_ELITE_YAR => $this->walletEngine->creditEliteYAR(
                    $order, $amount, FinancialCodes::STATUS_AVAILABLE, (int) $leg->{CouponFundingLeg::FUNDER_USER_ID}, $description
                ),
                default => $leg->isFromCouponWallet()
                    ? $this->walletEngine->creditCoupon($order, $amount, FinancialCodes::STATUS_AVAILABLE, $description)
                    : $this->walletEngine->creditSystem($order, $amount, FinancialCodes::STATUS_AVAILABLE, $description),
            };

            $leg->forceFill([CouponFundingLeg::REVERSED_AT => now()])->save();

            // استردادِ سفارشِ کمپینی: سهمِ شرکت به بودجه برمی‌گردد (پولش هم به WSSG برگشت).
            if ($coupon instanceof Coupon && $coupon->isCampaign()
                && $leg->{CouponFundingLeg::FUNDER_TYPE} === CouponFunder::TYPE_COMPANY) {
                $coupon->forceFill([
                    Coupon::BUDGET_USED => max(0.0, (float) ($coupon->{Coupon::BUDGET_USED} ?? 0) - $amount),
                ])->save();
            }
        }

        return true;
    }

    // ── باسکول: اختلافِ تخفیفِ کوپنِ درصدی (`WeighbridgeReconciler::recalculateCoupon`) ──

    /**
     * سهمِ هر تأمین‌کننده از اختلافِ تخفیف در باسکول.
     *
     * به نسبتِ لِگ‌های پرداختِ **همین سفارش** — نه ردیف‌های فعلیِ کوپن — با همان
     * قاعده‌ی `split()` (آخری باقی‌مانده را می‌گیرد، پس جمعِ لِگ‌ها دقیقاً تخفیفِ تازه
     * می‌شود). سفارشی که پیش از تأمینِ سه‌منبعی پرداخت شده لِگ ندارد: کلِ تخفیف را
     * سازه داده بود (لِگِ قدیمیِ WSSG→WO)، پس اختلاف یک سهمِ شرکت است.
     *
     * @return list<array{leg: CouponFundingLeg|null, type: string, user_id: int|null, delta: float}>
     *                                                                                                delta منفی = برگشت به تأمین‌کننده، مثبت = برداشت از او
     */
    public function weighbridgeShares(Order $order, float $credited, float $newDiscount): array
    {
        $legs = CouponFundingLeg::where(CouponFundingLeg::ORDER_ID, $order->id)
            ->whereNull(CouponFundingLeg::REVERSED_AT)
            ->orderBy(CouponFundingLeg::ID)
            ->get();

        $old = (float) $legs->sum(fn (CouponFundingLeg $leg) => (float) $leg->{CouponFundingLeg::AMOUNT});
        $total = round($newDiscount);

        if ($old <= 0) {
            return [['leg' => null, 'type' => CouponFunder::TYPE_COMPANY, 'user_id' => null, 'delta' => $total - round($credited)]];
        }

        $last = $legs->count() - 1;
        $allocated = 0.0;
        $shares = [];

        foreach ($legs->values() as $i => $leg) {
            $current = (float) $leg->{CouponFundingLeg::AMOUNT};
            $amount = $i === $last ? $total - $allocated : floor($total * $current / $old);
            $allocated += $amount;
            $userId = $leg->{CouponFundingLeg::FUNDER_USER_ID};

            $shares[] = [
                'leg' => $leg,
                'type' => (string) $leg->{CouponFundingLeg::FUNDER_TYPE},
                'user_id' => $userId !== null ? (int) $userId : null,
                'delta' => $amount - $current,
            ];
        }

        return $shares;
    }

    /**
     * افزایشِ تخفیف فقط وقتی اعمال می‌شود که کیف پولِ هر تأمین‌کننده‌ی کاربری سهمِ
     * اضافه‌اش را پوشش دهد — وگرنه یک کیف پولِ خالیِ الیت‌یار کلِ باسکول را rollback
     * می‌کرد. سهمِ شرکت چک نمی‌شود: از WSSG و پس از دریافتِ مابه‌التفاوت برداشته می‌شود.
     *
     * @param  list<array{leg: CouponFundingLeg|null, type: string, user_id: int|null, delta: float}>  $shares
     * @param  array<int, float>  $incoming  پولی که همین تسویه پیش از برداشت به کیف کاربر می‌ریزد (ΔPTS)
     */
    public function canFundIncrease(array $shares, array $incoming = []): bool
    {
        foreach ($shares as $share) {
            if ($share['delta'] <= 0) {
                continue;
            }

            // سهمِ شرکت از کیف پولِ کوپن‌ها (مدیریت، دورِ پنجم): تخفیفِ اضافه فقط تا موجودیِ آن.
            if ($share['user_id'] === null) {
                if ($share['leg'] instanceof CouponFundingLeg && $share['leg']->isFromCouponWallet()
                    && app(CouponWallet::class)->balance() < $share['delta']) {
                    return false;
                }

                continue;
            }

            $balance = (float) (Wallet::where(Wallet::USER_ID, $share['user_id'])->value(Wallet::BALANCE) ?? 0);

            if ($balance + ($incoming[$share['user_id']] ?? 0.0) < $share['delta']) {
                return false;
            }
        }

        return true;
    }

    /**
     * لِگ‌های تأمین را با اختلافِ باسکول هم‌راستا می‌کند: delta منفی به کیف پولِ همان
     * تأمین‌کننده برمی‌گردد، مثبت از آن برداشته می‌شود. مبلغِ هر لِگ به‌روز می‌شود تا
     * `reverseLegs()` در استردادِ بعدی دقیقاً همان را برگرداند. لِگِ خریدار با فراخوان است.
     *
     * @param  list<array{leg: CouponFundingLeg|null, type: string, user_id: int|null, delta: float}>  $shares
     */
    public function applyWeighbridgeShares(Order $order, array $shares, string $description): void
    {
        foreach ($shares as ['leg' => $leg, 'type' => $type, 'user_id' => $userId, 'delta' => $delta]) {
            if ($delta == 0.0) {
                continue;
            }

            $amount = abs($delta);

            if ($delta < 0) {
                match ($type) {
                    CouponFunder::TYPE_SUPPLIER => $this->walletEngine->creditSupplier($order, $amount, FinancialCodes::STATUS_AVAILABLE, (int) $userId, $description),
                    CouponFunder::TYPE_ELITE_YAR => $this->walletEngine->creditEliteYAR($order, $amount, FinancialCodes::STATUS_AVAILABLE, (int) $userId, $description),
                    default => ($leg instanceof CouponFundingLeg && $leg->isFromCouponWallet())
                        ? $this->walletEngine->creditCoupon($order, $amount, FinancialCodes::STATUS_AVAILABLE, $description)
                        : $this->walletEngine->creditSystem($order, $amount, FinancialCodes::STATUS_AVAILABLE, $description),
                };
            } else {
                match ($type) {
                    CouponFunder::TYPE_SUPPLIER => $this->walletEngine->debitSupplier($order, $amount, FinancialCodes::STATUS_AVAILABLE, (int) $userId, $description),
                    CouponFunder::TYPE_ELITE_YAR => $this->walletEngine->debitEliteYAR($order, $amount, FinancialCodes::STATUS_AVAILABLE, (int) $userId, $description),
                    default => ($leg instanceof CouponFundingLeg && $leg->isFromCouponWallet())
                        ? $this->walletEngine->debitCoupon($order, $amount, FinancialCodes::STATUS_AVAILABLE, $description)
                        : $this->walletEngine->debitSystem($order, $amount, FinancialCodes::STATUS_AVAILABLE, $description),
                };
            }

            $leg?->forceFill([CouponFundingLeg::AMOUNT => (float) $leg->{CouponFundingLeg::AMOUNT} + $delta])->save();
        }
    }

    public function weighbridgeDescription(Order $order, string $key): string
    {
        $coupon = $order->coupon;

        return __($key, [
            'code' => $coupon instanceof Coupon ? (string) $coupon->code : '-',
            'order' => $order->order_number,
        ]);
    }

    /**
     * تقسیمِ تخفیف به نسبتِ `share_percent`، ریالِ صحیح. سهمِ **آخر** باقی‌مانده را
     * می‌گیرد تا جمع ساختاری دقیق باشد — همان قاعده‌ی `ShippingCostCalculator`.
     *
     * @param  Collection<int, CouponFunder>  $rows
     * @return list<array{funder: CouponFunder, amount: float}>
     */
    private function split(Collection $rows, float $discount): array
    {
        $total = round($discount);
        $rows = $rows->values();
        $last = $rows->count() - 1;
        $allocated = 0.0;
        $legs = [];

        foreach ($rows as $i => $funder) {
            $amount = $i === $last
                ? $total - $allocated
                : floor($total * (float) $funder->{CouponFunder::SHARE_PERCENT} / 100);

            $allocated += $amount;
            $legs[] = ['funder' => $funder, 'amount' => max(0.0, $amount)];
        }

        return $legs;
    }

    private function assertWalletCovers(CouponFunder $funder, float $amount, string $name, string $share): void
    {
        $balance = (float) (Wallet::where(Wallet::USER_ID, $funder->{CouponFunder::FUNDER_USER_ID})->value(Wallet::BALANCE) ?? 0);

        if ($balance < $amount) {
            throw new BusinessException(__('coupons.funder_wallet_insufficient', [
                'funder' => $name,
                'share' => $share,
                'amount' => number_format($amount),
            ]), 422);
        }
    }

    private function percentLabel(CouponFunder $funder): string
    {
        return rtrim(rtrim(number_format((float) $funder->{CouponFunder::SHARE_PERCENT}, 2, '.', ''), '0'), '.');
    }
}
