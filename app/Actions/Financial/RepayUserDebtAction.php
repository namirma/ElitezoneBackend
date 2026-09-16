<?php

declare(strict_types=1);

namespace App\Actions\Financial;

use App\Actions\Order\RequestOverweightApprovalAction;
use App\Exceptions\BusinessException;
use App\Exceptions\InsufficientBalanceException;
use App\Models\DebtEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReturnDamage;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Financial\DebtEngine;
use App\Services\Financial\SupplierDebtHandler;
use App\Services\Shipping\ReturnDamageService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * `POST /api/profile/debts/repay` — هر بدهی از **جریانِ خودش** تسویه می‌شود.
 *
 * ⚠️ **بدهی یک عدد نیست؛ هر نوعش طرفِ مقابلِ دیگری دارد.** نسخه‌ی قبلی همه را
 * با یک لِگ می‌بست (کیف پولِ بدهکار → سازه → `repay`) و این برای سه نوع غلط بود:
 *
 *   • `weighbridge_deficit` — پرچم‌های سفارش باز می‌ماند و `CheckSupplierBalancesJob`
 *     همان طلب را دوباره از تأمین‌کننده برمی‌داشت: بدهیِ ۲ میلیونی، ۴ میلیون.
 *   • `return_damage` — پول باید به **تأمین‌کننده** برسد نه سازه، و خسارت تسویه‌نشده
 *     می‌ماند؛ پرداختِ بعدیِ خسارت خریدار را بارِ دوم می‌دوشید.
 *   • `overweight` — سازه کلِ مابه‌التفاوت را در باسکول گرفته بود، پس `creditSystem`
 *     دوباره پول می‌ساخت؛ و بارِ متوقف (`awaiting_weight_correction`) آزاد نمی‌شد.
 *
 * فقط `adjustment` و بدهیِ بی‌سفارش لِگِ عمومی را می‌خورند. نوعِ ناشناخته ۴۲۲ است،
 * نه حدس.
 *
 * بدهی‌های سفارش‌محور **کامل یا هیچ** تسویه می‌شوند (جریانِ هیچ‌کدام مبلغِ جزئی
 * نمی‌پذیرد): با `amount` جزئی، قدیمی‌ترین‌هایی که جا می‌شوند پرداخت و بقیه رد
 * می‌شوند. همه در یک تراکنش — کیف پولِ ناکافی در هر قدم کلِ درخواست را برمی‌گرداند.
 */
class RepayUserDebtAction
{
    public function __construct(
        private readonly DebtEngine $debtEngine,
        private readonly DebitWalletAction $debit,
        private readonly FundWalletAction $fund,
        private readonly ReturnDamageService $damages,
        private readonly RequestOverweightApprovalAction $overweight,
        private readonly SupplierDebtHandler $supplierDebts,
    ) {}

    /** @return float مبلغی که واقعاً پرداخت شد */
    public function execute(User $user, float $amount): float
    {
        $userId = (int) $user->id;

        return DB::transaction(function () use ($user, $userId, $amount) {
            $budget = min($amount, $this->debtEngine->balanceFor($userId));
            $paid = 0.0;

            foreach ($this->openDebts($userId) as $debt) {
                $left = $budget - $paid;

                if ($left <= 0.0) {
                    break;
                }

                $paid += $debt['order_id'] === null
                    ? $this->repayWithLegs($user, min($debt['amount'], $left, $this->debtEngine->balanceFor($userId)), null)
                    : $this->settleOrderDebt($user, $debt['order_id'], $debt['types'], $left);
            }

            if ($paid <= 0.0) {
                throw new BusinessException(__('orders.debt_repay_amount_too_small'), 422);
            }

            return $paid;
        });
    }

    /**
     * @param  list<string>  $types  نوع‌های ردیف‌های مثبتِ این سفارش
     * @return float مبلغِ پرداخت‌شده (صفر = در بودجه جا نشد)
     */
    private function settleOrderDebt(User $user, int $orderId, array $types, float $budget): float
    {
        $userId = (int) $user->id;

        /** @var Order|null $order */
        $order = Order::whereKey($orderId)->first();

        if ($order === null) {
            throw $this->unroutable(null);
        }

        $spent = 0.0;

        // خسارت‌ها اول: هر کدام به تأمین‌کننده می‌رسد و محموله را برای حملِ مجدد باز می‌کند.
        if (in_array(DebtEntry::TYPE_RETURN_DAMAGE, $types, true) && $order->payerUserId() === $userId) {
            $open = ReturnDamage::query()
                ->where(ReturnDamage::ORDER_ID, $orderId)
                ->whereNotNull(ReturnDamage::BUYER_APPROVED_AT)
                ->whereNull(ReturnDamage::SETTLED_AT)
                ->whereNull(ReturnDamage::REJECTED_AT)
                ->orderBy(ReturnDamage::ID)
                ->get();

            foreach ($open as $damage) {
                $cost = (float) $damage->{ReturnDamage::AMOUNT};

                if (Money::lt($budget - $spent, $cost)) {
                    return $spent;
                }

                $this->damages->settle($damage);
                $spent += $cost;
            }
        }

        $outstanding = $this->debtEngine->outstandingForOrder($userId, $orderId);
        $left = $budget - $spent;

        if ($outstanding <= 0.0 || $left <= 0.0) {
            return $spent;
        }

        $others = array_values(array_diff($types, [DebtEntry::TYPE_RETURN_DAMAGE]));

        if (in_array(DebtEntry::TYPE_WEIGHBRIDGE_DEFICIT, $others, true)) {
            $debt = (float) $order->{Order::SUPPLIER_DEBT_AMOUNT};
            $supplierId = (int) $order->items()->value(OrderItem::SUPPLIER_USER_ID);

            if (! $order->{Order::SUPPLIER_DEBT_PENDING} || $supplierId !== $userId || $debt <= 0.0) {
                throw $this->unroutable($order);
            }

            if (Money::lt($left, $debt)) {
                return $spent;
            }

            // ‏`settlePending` با موجودیِ کم فقط false می‌دهد (برای کران «اجرای بعدی»
            // است)؛ اینجا کاربر منتظر است، پس همان ۴۰۲ِ بقیه‌ی مسیرها.
            $balance = (float) (Wallet::where(Wallet::USER_ID, $userId)->value(Wallet::BALANCE) ?? 0);

            if (Money::lt($balance, $debt)) {
                throw new InsufficientBalanceException($debt, $balance);
            }

            if (! $this->supplierDebts->settlePending($order)) {
                throw $this->unroutable($order);
            }

            return $spent + $debt;
        }

        if (in_array(DebtEntry::TYPE_OVERWEIGHT, $others, true)) {
            if ($order->payerUserId() !== $userId) {
                throw $this->unroutable($order);
            }

            if (Money::lt($left, $outstanding)) {
                return $spent;
            }

            $this->overweight->settleShortfall($order);

            return $spent + $outstanding;
        }

        if ($others === [DebtEntry::TYPE_ADJUSTMENT]) {
            return $spent + $this->repayWithLegs($user, min($outstanding, $left), $order);
        }

        throw $this->unroutable($order);
    }

    /**
     * لِگِ عمومی — فقط برای بدهیِ بی‌سفارش و تعدیلِ دستی.
     *
     * ترتیب اجباری: اول کسر از کیف پول، بعد بستن دفتر؛ عکسش یعنی بدهی صفر شود و
     * بعد کسر با موجودیِ ناکافی throw کند. `creditSystem` هم می‌خورد چون تعدیل
     * یعنی شرکت پول را جلو انداخته بود. `DebitWalletAction`/`FundWalletAction` و نه
     * `WalletTransactionEngine`، چون ردیف ممکن است سفارش نداشته باشد.
     */
    private function repayWithLegs(User $user, float $amount, ?Order $order): float
    {
        if ($amount <= 0.0) {
            return 0.0;
        }

        $this->debit->execute($user, $amount, __('orders.debt_repay_description'), [
            'kind' => 'debt_repayment',
        ]);

        // ⚠️ `STATUS_CONFIRMED` صریح: پیش‌فرضِ `FundWalletAction` یک `deposit/pending`
        // می‌ساخت که موجودی را تکان نمی‌داد — پول از سیستم ناپدید می‌شد.
        $systemUser = User::whereKey((int) config('financial.system_user_id', 1))->first();

        if ($systemUser !== null) {
            $this->fund->execute(
                $systemUser, $amount, WalletTransaction::METHOD_BANK_TRANSFER,
                __('orders.debt_repay_description'), null, ['kind' => 'debt_repayment'],
                WalletTransaction::STATUS_CONFIRMED
            );
        }

        $this->debtEngine->repay((int) $user->id, $amount, $order, __('orders.debt_repay_description'));

        return $amount;
    }

    /**
     * بدهی‌های باز، گروه‌شده بر اساسِ سفارش و **قدیمی‌ترین اول**.
     *
     * @return list<array{order_id: int|null, amount: float, types: list<string>}>
     */
    private function openDebts(int $userId): array
    {
        return DebtEntry::query()
            ->where(DebtEntry::USER_ID, $userId)
            ->orderBy(DebtEntry::ID)
            ->get([DebtEntry::ID, DebtEntry::ORDER_ID, DebtEntry::TYPE, DebtEntry::AMOUNT])
            ->groupBy(fn (DebtEntry $entry) => (string) $entry->{DebtEntry::ORDER_ID})
            ->map(fn ($rows, $key) => [
                'order_id' => (string) $key === '' ? null : (int) $key,
                'amount' => (float) $rows->reduce(
                    fn (string $sum, DebtEntry $entry) => Money::add($sum, (string) $entry->{DebtEntry::AMOUNT}), '0'
                ),
                'types' => $rows->filter(fn (DebtEntry $entry) => (float) $entry->{DebtEntry::AMOUNT} > 0)
                    ->pluck(DebtEntry::TYPE)->unique()->values()->all(),
            ])
            ->filter(fn (array $debt) => $debt['amount'] > 0)
            ->values()
            ->all();
    }

    private function unroutable(?Order $order): BusinessException
    {
        return new BusinessException(
            __('orders.debt_route_unavailable', ['order' => $order === null ? '—' : $order->order_number]),
            422
        );
    }
}
