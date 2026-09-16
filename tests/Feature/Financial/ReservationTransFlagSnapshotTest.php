<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Actions\Financial\ProcessReservationCompletionAction;
use App\Actions\Financial\ProcessReservationPaymentAction;
use App\Events\Financial\TransactionProcessed;
use App\Events\Order\OrderPaid;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\SettlementCalculatorService;
use App\Services\Invoice\InvoiceGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * قراردادِ −TC/−VAT تأمین‌کننده در مسیرِ رزرو — بازبینی ۲۰۲۶-۰۹-۱۴ (مورد ۲).
 *
 * ‏`orders.trans_*` ستون‌های `NOT NULL DEFAULT true` هستند و مسیرِ رزرو پیش از
 * تکمیل آن‌ها را نمی‌نوشت؛ فالبکِ `??` تکمیل به پروفایل هرگز فایر نمی‌شد. با کرایه‌ی
 * ۱۰ میلیونی خریدار حدود یک میلیون بیشتر می‌پرداخت، PTS فرمولِ غلط می‌گرفت و همان
 * `true` برای باسکول اسنپ‌شات می‌شد.
 */
class ReservationTransFlagSnapshotTest extends TestCase
{
    use DatabaseTransactions;

    private const FREIGHT = 10_000_000.0;

    private SupplierProfile $profile;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Event::fake([OrderPaid::class, TransactionProcessed::class]);
        $this->mock(InvoiceGenerator::class)->shouldIgnoreMissing();

        $system = User::factory()->create();
        config(['financial.system_user_id' => $system->id, 'financial.vat_user_id' => $system->id]);
        Wallet::create([Wallet::USER_ID => $system->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $buyer = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $buyer->id, Wallet::BALANCE => 100_000_000, Wallet::BALANCE_HOLD => 0]);

        $supplier = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $supplier->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);
        $this->profile = SupplierProfile::create([SupplierProfile::USER_ID => $supplier->id]);
        $this->profile->forceFill([SupplierProfile::TRANS_COMMISSION => false, SupplierProfile::TRANS_VAT => false])->save();

        $this->order = Order::create([
            Order::PLACER_USER_ID => $buyer->id,
            Order::PAYER_USER_ID => $buyer->id,
            Order::ORDER_NUMBER => 'TRANSFLAG-'.Str::random(10),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::FINAL_AMOUNT => 21_000_000,
            Order::PAYMENT_STATUS => Order::PAYMENT_PENDING,
            Order::SHIPPING_COST => self::FREIGHT,
            Order::WEIGHT_BEFORE_SCALE => 10,
        ]);
        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $this->order->id,
            OrderItem::SUPPLIER_USER_ID => $supplier->id,
            OrderItem::UNIT_PRICE => 1_000_000,
            OrderItem::TOTAL_PRICE => 10_000_000,
            OrderItem::QUANTITY => 10,
        ]);
        $this->order->forceFill([Order::OFFICIAL_PRICE => 1_000_000, Order::VAT_RATE => 10])->save();

        app(ProcessReservationPaymentAction::class)->execute($this->order, 5_000_000, now()->addHours(2), graceHours: 0);
        $this->order->refresh();
    }

    /** @return array{poo: float, pts: float} */
    private function formula(Order $order, bool $transCommission, bool $transVat): array
    {
        $calc = app(SettlementCalculatorService::class);
        $r = $calc->getCommissionRatesForTc('pioneer', (float) $order->{Order::COMMISSION_RATE});

        return [
            'poo' => $calc->calculatePOO(self::FREIGHT, 1_000_000, 10, $r['ow_final_rate'], 10, $transCommission, $transVat),
            'pts' => $calc->calculatePTS(self::FREIGHT, 1_000_000, 10, $r['ow_final_rate'], $r['ssg_final_rate'], $r['co_final_rate'], 10, $transCommission, $transVat),
        ];
    }

    private function assertContractApplied(): void
    {
        $this->order->refresh();
        $this->assertSame(Order::PAYMENT_PAID, $this->order->payment_status);
        $this->assertFalse($this->order->{Order::TRANS_COMMISSION}, 'باسکول باید قراردادِ −TC را ببیند.');
        $this->assertFalse($this->order->{Order::TRANS_VAT}, 'باسکول باید قراردادِ −VAT را ببیند.');

        $contract = $this->formula($this->order, false, false);
        $default = $this->formula($this->order, true, true);
        $this->assertGreaterThan($contract['poo'] + 900_000, $default['poo'], 'سناریو باید دو فرمول را واقعاً از هم جدا کند.');

        $this->assertEqualsWithDelta($contract['poo'], (float) $this->order->{Order::PAYMENT_OF_OWNER}, 1.0);
        $this->assertEqualsWithDelta($contract['pts'], (float) $this->order->{Order::PAYMENT_TO_SUPPLIER}, 1.0);
    }

    #[Test]
    public function the_reservation_payment_snapshots_the_suppliers_formula_flags(): void
    {
        $this->assertSame(Order::STATUS_RESERVED, $this->order->status);
        $this->assertFalse($this->order->{Order::TRANS_COMMISSION});
        $this->assertFalse($this->order->{Order::TRANS_VAT});
    }

    #[Test]
    public function completion_settles_on_the_contract_formula_not_the_column_default(): void
    {
        app(ProcessReservationCompletionAction::class)->execute($this->order);

        $this->assertContractApplied();
    }

    #[Test]
    public function a_contract_edit_after_the_reservation_does_not_reach_the_completion(): void
    {
        $this->profile->forceFill([SupplierProfile::TRANS_COMMISSION => true, SupplierProfile::TRANS_VAT => true])->save();

        app(ProcessReservationCompletionAction::class)->execute($this->order);

        $this->assertContractApplied();
    }

    #[Test]
    public function the_backfill_fixes_open_reservations_and_leaves_paid_orders_alone(): void
    {
        $migration = require database_path('migrations/2026_09_16_100000_snapshot_trans_flags_on_open_reservations.php');

        // A reservation placed before the fix: the flags hold the column default.
        $this->order->forceFill([Order::TRANS_COMMISSION => true, Order::TRANS_VAT => true])->save();
        $migration->up();

        $this->order->refresh();
        $this->assertFalse($this->order->{Order::TRANS_COMMISSION});
        $this->assertFalse($this->order->{Order::TRANS_VAT});
        $this->assertContractAppliedAfterCompletion();

        // A paid order's `true` cannot be told apart from a real contract — never rewritten.
        $this->order->forceFill([Order::TRANS_COMMISSION => true, Order::TRANS_VAT => true])->save();
        $migration->up();

        $this->order->refresh();
        $this->assertTrue($this->order->{Order::TRANS_COMMISSION});
        $this->assertTrue($this->order->{Order::TRANS_VAT});
    }

    private function assertContractAppliedAfterCompletion(): void
    {
        app(ProcessReservationCompletionAction::class)->execute($this->order);

        $this->assertContractApplied();
    }
}
