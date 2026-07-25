<?php

namespace Tests\Feature\Financial;

use App\DataTransferObjects\Order\WeighbridgeReconcileResult;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\WeighbridgeVatRouter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Verifies the eliteYAR lifetime commission cap
 * (WeighbridgeVatRouter::eliteYARLifetimeCeiling).
 *
 * Spec ("پروسه خرید"): the total lifetime commission paid to a gold/silver/bronze
 * eliteYAR may not exceed `cap_multiplier × the invoice they paid for their badge`.
 * A eliteYAR with no recorded badge purchase (users.badge_purchase_amount = NULL)
 * is uncapped.
 *
 * route() returns the actual eliteYARGross allocated (after the cap), so we assert
 * on that return value directly.
 *
 * DatabaseTransactions: all writes roll back; the live DB is never mutated.
 */
class EliteYARLifetimeCapTest extends TestCase
{
    use DatabaseTransactions;

    private WeighbridgeVatRouter $router;

    private User $eliteYAR;

    private User $supplier;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        // Saze (system) wallet — funded so debitSystem() never under-runs.
        $sys = User::factory()->create();
        config(['financial.system_user_id' => $sys->id]);
        Wallet::create([Wallet::USER_ID => $sys->id, Wallet::BALANCE => 1_000_000_000, Wallet::BALANCE_HOLD => 0]);

        // Dedicated VAT escrow wallet (separate so system isn't double-charged).
        $vat = User::factory()->create();
        config(['financial.vat_user_id' => $vat->id]);
        Wallet::create([Wallet::USER_ID => $vat->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        // Ensure the cap multiplier is the spec default (2×) regardless of env.
        // (کلید واقعی elite_yar_lifetime_cap_multiplier است؛ کلید قدیمیِ consultant_*
        //  دیگر وجود ندارد و این override را بی‌اثر می‌کرد.)
        config(['financial.elite_yar_lifetime_cap_multiplier' => 2.0]);

        $buyer = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $buyer->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $this->eliteYAR = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $this->eliteYAR->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $this->supplier = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $this->supplier->id, Wallet::BALANCE => 1_000_000_000, Wallet::BALANCE_HOLD => 0]);

        $this->order = Order::create([
            Order::PLACER_USER_ID => $buyer->id,
            Order::ELITE_YAR_USER_ID => $this->eliteYAR->id,
            Order::ORDER_NUMBER => 'CAP-'.now()->format('YmdHis').rand(100, 999),
            Order::STATUS => Order::STATUS_SHIPPED,
            Order::FINAL_AMOUNT => 200_000_000,
            Order::PAYMENT_STATUS => 'paid',
        ]);

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $this->order->id,
            OrderItem::SUPPLIER_USER_ID => $this->supplier->id,
        ]);

        $this->router = app(WeighbridgeVatRouter::class);
    }

    /**
     * Commission pool = newPOO − newPTS. With gold rates the eliteYAR ratio is
     * co/(co+ssg) = 0.375 / 0.6 = 0.625, so candidateGross = pool × 0.625.
     */
    private function resultWithPool(): WeighbridgeReconcileResult
    {
        // pool = 200M − 100M = 100M  →  candidateGross = 62.5M (uncapped)
        return new WeighbridgeReconcileResult(
            billingWeight: 1000.0,
            newPOO: 200_000_000.0,
            newPTS: 100_000_000.0,
            diffPOO: 0.0,
            diffPTS: 0.0,
            rates: ['co_final_rate' => 0.375, 'ssg_final_rate' => 0.225],
            vat: 10.0,
        );
    }

    #[Test]
    public function consultant_with_no_badge_purchase_is_uncapped(): void
    {
        $this->eliteYAR->update([User::BADGE_PURCHASE_AMOUNT => null]);

        $gross = $this->router->route($this->order->fresh(), $this->resultWithPool());

        // Full uncapped allocation: 100M pool × 0.625
        $this->assertEqualsWithDelta(62_500_000, $gross, 1.0);
    }

    #[Test]
    public function consultant_gross_is_clamped_to_two_times_badge_purchase(): void
    {
        // Badge invoice 10M → ceiling = 2 × 10M = 20M, well below the 62.5M candidate.
        $this->eliteYAR->update([User::BADGE_PURCHASE_AMOUNT => 10_000_000]);

        $gross = $this->router->route($this->order->fresh(), $this->resultWithPool());

        $this->assertEqualsWithDelta(20_000_000, $gross, 1.0);
    }

    #[Test]
    public function prior_payouts_consume_the_lifetime_headroom(): void
    {
        // Ceiling 20M, but 15M already paid on a prior settled order → only 5M headroom.
        $this->eliteYAR->update([User::BADGE_PURCHASE_AMOUNT => 10_000_000]);

        Order::create([
            Order::PLACER_USER_ID => $this->order->placer_user_id,
            Order::ELITE_YAR_USER_ID => $this->eliteYAR->id,
            Order::ORDER_NUMBER => 'PRIOR-'.now()->format('YmdHis').rand(100, 999),
            Order::STATUS => Order::STATUS_SHIPPED,
            Order::PAYMENT_STATUS => 'paid',
        ])->forceFill([
            Order::WEIGHING_COMPLETED_AT => now()->subDay(),
            Order::PAYMENT_TO_ELITE_YAR => 15_000_000,
        ])->save();

        $gross = $this->router->route($this->order->fresh(), $this->resultWithPool());

        $this->assertEqualsWithDelta(5_000_000, $gross, 1.0);
    }

    /**
     * سقف باید سریال‌سازی شود.
     *
     * هر تسویهٔ باسکول فقط ردیفِ Order خودش را قفل می‌کند، پس دو سفارشِ هم‌زمانِ
     * یک الیت‌یار هر دو همان `alreadyPaid` را می‌خواندند و هر دو از سقف عبور
     * می‌کردند. رفع: قفلِ ردیفِ users پیش از جمع‌زدنِ پرداخت‌های قبلی.
     *
     * تستِ هم‌زمانیِ واقعی در یک پروسه ممکن نیست، پس خودِ قرارداد را pin می‌کنیم:
     * کوئریِ سقف باید FOR UPDATE داشته باشد و *پیش از* کوئریِ جمع اجرا شود.
     */
    #[Test]
    public function the_lifetime_ceiling_is_read_under_a_row_lock(): void
    {
        $this->eliteYAR->update([User::BADGE_PURCHASE_AMOUNT => 10_000_000]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->router->route($this->order->fresh(), $this->resultWithPool());

        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $lockIndex = null;
        $sumIndex = null;

        foreach ($queries as $i => $sql) {
            $normalized = strtolower($sql);

            if ($lockIndex === null
                && str_contains($normalized, 'badge_purchase_amount')
                && str_contains($normalized, 'for update')) {
                $lockIndex = $i;
            }

            if ($sumIndex === null && str_contains($normalized, 'sum("payment_to_elite_yar")')) {
                $sumIndex = $i;
            }

            if ($sumIndex === null && str_contains($normalized, 'sum(`payment_to_elite_yar`)')) {
                $sumIndex = $i;
            }
        }

        $this->assertNotNull($lockIndex, 'کوئریِ badge_purchase_amount باید FOR UPDATE داشته باشد.');
        $this->assertNotNull($sumIndex, 'کوئریِ جمعِ payment_to_elite_yar پیدا نشد.');
        $this->assertLessThan(
            $sumIndex,
            $lockIndex,
            'قفل باید پیش از جمع‌زدنِ پرداخت‌های قبلی گرفته شود، وگرنه سریال‌سازی نمی‌کند.',
        );
    }
}
