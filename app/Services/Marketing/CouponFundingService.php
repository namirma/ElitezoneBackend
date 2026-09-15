<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Constants\FinancialCodes;
use App\Exceptions\BusinessException;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Coupon;
use App\Models\CouponFunder;
use App\Models\CouponFundingLeg;
use App\Models\Order;
use App\Models\Wallet;
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

        $legs = $this->split($coupon->fundingRows(), $discount);

        foreach ($legs as ['funder' => $funder, 'amount' => $amount]) {
            if ($amount <= 0) {
                continue;
            }

            $name = $funder->displayName();
            $share = $this->percentLabel($funder);

            switch ($funder->{CouponFunder::FUNDER_TYPE}) {
                case CouponFunder::TYPE_SUPPLIER:
                    if ((int) $funder->{CouponFunder::FUNDER_USER_ID} !== (int) $supplierId) {
                        throw new BusinessException(__('coupons.funder_supplier_mismatch', ['funder' => $name]), 422);
                    }
                    $this->assertWalletCovers($funder, $amount, $name, $share);
                    break;

                case CouponFunder::TYPE_ELITE_YAR:
                    if ($eliteYARId === null || (int) $funder->{CouponFunder::FUNDER_USER_ID} !== (int) $eliteYARId) {
                        throw new BusinessException(__('coupons.funder_elite_yar_mismatch', ['funder' => $name]), 422);
                    }
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
                    if ($amount > $caps['company']) {
                        throw new BusinessException(__('coupons.company_cap_exceeded', [
                            'amount' => number_format($amount),
                            'cap' => number_format($caps['company']),
                        ]), 422);
                    }
            }
        }

        return $legs;
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
                    default => $this->walletEngine->debitSystem($order, $amount, FinancialCodes::STATUS_AVAILABLE, $description),
                };
            } catch (InsufficientBalanceException) {
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
                default => $this->walletEngine->creditSystem($order, $amount, FinancialCodes::STATUS_AVAILABLE, $description),
            };

            $leg->forceFill([CouponFundingLeg::REVERSED_AT => now()])->save();
        }

        return true;
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
