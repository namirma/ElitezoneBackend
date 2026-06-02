<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Tests for HasOrderProgress trait accessors.
 *
 * Covers getDashboardProgressAttribute (buyer), getConsultantProgressAttribute,
 * and getSupplierProgressAttribute.
 *
 * DatabaseTransactions: all DB writes are rolled back after each test;
 * the live local database is NOT mutated.
 */
class HasOrderProgressTest extends TestCase
{
    use DatabaseTransactions;

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Create a minimal Order with the given status overrides.
     * final_amount defaults to a small value so isFullPayment = true (no reserve step).
     */
    private function makeOrder(array $overrides = []): Order
    {
        $user = User::factory()->create();

        return Order::create(array_merge([
            Order::PLACER_USER_ID => $user->id,
            Order::ORDER_NUMBER => 'TEST-PROGRESS-'.uniqid(),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
            Order::FINAL_AMOUNT => 100_000, // < threshold → full-payment (no reserve step)
        ], $overrides));
    }

    // =========================================================================
    //  Consultant Progress
    // =========================================================================

    /** مرحله ۱ باید current باشد وقتی status = PROCESSING و فیلدهای مودیان null است */
    public function test_consultant_progress_step1_current_on_processing(): void
    {
        $order = $this->makeOrder([Order::STATUS => Order::STATUS_PROCESSING]);

        $progress = $order->consultant_progress;

        $this->assertFalse($progress['is_cancelled']);
        $this->assertCount(4, $progress['steps']);

        $this->assertSame('کارفرما خرید کرد', $progress['steps'][0]['title']);
        $this->assertSame('current', $progress['steps'][0]['state']);
        $this->assertSame('inactive', $progress['steps'][1]['state']);
        $this->assertSame('inactive', $progress['steps'][2]['state']);
        $this->assertSame('inactive', $progress['steps'][3]['state']);

        // مرحله ۱ باید لینک دانلود فاکتور داشته باشد
        $this->assertCount(1, $progress['steps'][0]['actions']);
        $this->assertSame('دانلود فاکتور کالای خریداری شده', $progress['steps'][0]['actions'][0]['label']);
        $this->assertTrue($progress['steps'][0]['actions'][0]['downloadable']);
    }

    /** WEIGHBRIDGE_DONE → مرحله ۲ current، مرحله ۱ completed */
    public function test_consultant_progress_step2_current_on_weighbridge_done(): void
    {
        $order = $this->makeOrder([Order::STATUS => Order::STATUS_WEIGHBRIDGE_DONE]);

        $progress = $order->consultant_progress;

        $this->assertSame('completed', $progress['steps'][0]['state']);
        $this->assertSame('current', $progress['steps'][1]['state']);
        $this->assertSame('inactive', $progress['steps'][2]['state']);
        $this->assertSame('inactive', $progress['steps'][3]['state']);

        // مرحله ۲ باید «مشاهده کیف پول» و «فاکتور خدمات» داشته باشد
        $labels = array_column($progress['steps'][1]['actions'], 'label');
        $this->assertContains('مشاهده کیف پول', $labels);
        $this->assertContains('فاکتور خدمات خود را ثبت کنید', $labels);
    }

    /** وقتی هر دو فیلد مودیان پر شده → همه مراحل completed */
    public function test_consultant_progress_all_completed_when_hold_released(): void
    {
        $order = $this->makeOrder([
            Order::STATUS => Order::STATUS_DELIVERED,
            Order::CONSULTANT_MOADIAN_REF => 'TAX-REF-001',
            Order::CONSULTANT_HOLD_RELEASED_AT => now(),
        ]);

        $progress = $order->consultant_progress;

        foreach ($progress['steps'] as $step) {
            $this->assertSame('completed', $step['state'],
                "Step «{$step['title']}» should be completed");
        }
    }

    /** سفارش لغو شده → مرحله اول cancelled، بقیه inactive */
    public function test_consultant_progress_cancelled_order(): void
    {
        $order = $this->makeOrder([Order::STATUS => Order::STATUS_CANCELLED]);

        $progress = $order->consultant_progress;

        $this->assertTrue($progress['is_cancelled']);
        $this->assertSame('cancelled', $progress['steps'][0]['state']);
        $this->assertSame('inactive', $progress['steps'][1]['state']);
        $this->assertSame('inactive', $progress['steps'][2]['state']);
        $this->assertSame('inactive', $progress['steps'][3]['state']);

        // مرحله cancelled باید actions خالی داشته باشد (کنسول actions نداریم)
        $this->assertEmpty($progress['steps'][0]['actions']);
    }

    // =========================================================================
    //  Supplier Progress — Full Payment (4 steps, no reserve)
    // =========================================================================

    /** PROCESSING → مرحله ۱ current (full payment, ۴ مرحله) */
    public function test_supplier_progress_step1_current_on_processing(): void
    {
        $order = $this->makeOrder([Order::STATUS => Order::STATUS_PROCESSING]);

        $progress = $order->supplier_progress;

        $this->assertFalse($progress['is_cancelled']);
        $this->assertCount(4, $progress['steps']); // full payment = no reserve step

        $this->assertSame('سفارش دریافت شد', $progress['steps'][0]['title']);
        $this->assertSame('current', $progress['steps'][0]['state']);
        $this->assertSame('inactive', $progress['steps'][1]['state']);

        // باید لینک دستور بارگیری وجود داشته باشد
        $this->assertCount(1, $progress['steps'][0]['actions']);
        $this->assertSame('دانلود دستور بارگیری', $progress['steps'][0]['actions'][0]['label']);
        $this->assertTrue($progress['steps'][0]['actions'][0]['downloadable']);
    }

    /** WEIGHBRIDGE_DONE + no moadian → مرحله ۲ current با لینک «ثبت فاکتور مودیان» */
    public function test_supplier_progress_step2_shows_moadian_action_when_not_submitted(): void
    {
        $order = $this->makeOrder([
            Order::STATUS => Order::STATUS_WEIGHBRIDGE_DONE,
            Order::SUPPLIER_MOADIAN_REF => null,
        ]);

        $progress = $order->supplier_progress;

        $step2 = $progress['steps'][1]; // index 1 = باسکول تأیید شد (no reserve step)
        $this->assertSame('باسکول تأیید شد', $step2['title']);
        $this->assertSame('current', $step2['state']);

        $labels = array_column($step2['actions'], 'label');
        $this->assertContains('دانلود مجوز خروج', $labels);
        $this->assertContains('ثبت فاکتور مودیان', $labels);
    }

    /** WEIGHBRIDGE_DONE + moadian ثبت شده → لینک «ثبت فاکتور مودیان» نباید وجود داشته باشد */
    public function test_supplier_progress_step2_hides_moadian_action_when_submitted(): void
    {
        $order = $this->makeOrder([
            Order::STATUS => Order::STATUS_WEIGHBRIDGE_DONE,
            Order::SUPPLIER_MOADIAN_REF => 'SUP-TAX-001',
        ]);

        $progress = $order->supplier_progress;

        $step2 = $progress['steps'][1];
        $labels = array_column($step2['actions'], 'label');

        $this->assertContains('دانلود مجوز خروج', $labels);
        $this->assertNotContains('ثبت فاکتور مودیان', $labels);
    }

    /** DELIVERED → آخرین مرحله باید completed باشد (نه current) */
    public function test_supplier_progress_last_step_completed_on_delivered(): void
    {
        $order = $this->makeOrder([Order::STATUS => Order::STATUS_DELIVERED]);

        $progress = $order->supplier_progress;

        $lastStep = $progress['steps'][count($progress['steps']) - 1];
        $this->assertSame('مبلغ تسویه شد', $lastStep['title']);
        $this->assertSame('completed', $lastStep['state']);

        // سایر مراحل هم باید completed باشند
        $nonLast = array_slice($progress['steps'], 0, -1);
        foreach ($nonLast as $step) {
            $this->assertSame('completed', $step['state'],
                "Step «{$step['title']}» should be completed before delivered");
        }
    }

    // =========================================================================
    //  Supplier Progress — Reservation (5 steps, with reserve step)
    // =========================================================================

    /** سفارش رزروی → ۵ مرحله؛ در status=RESERVED، مرحله رزرو current است */
    public function test_supplier_progress_5_steps_for_reservation_order(): void
    {
        $order = $this->makeOrder([
            Order::STATUS => Order::STATUS_RESERVED,
            Order::FINAL_AMOUNT => 3_000_000_000, // > threshold
            Order::PAYMENT_TO_RESERVE => 1_950_000_000, // != final_amount → reservation
        ]);

        $progress = $order->supplier_progress;

        $this->assertCount(5, $progress['steps']);
        $this->assertSame('رزرو سفارش ثبت شد', $progress['steps'][0]['title']);
        $this->assertSame('current', $progress['steps'][0]['state']);

        // لینک دانلود گزارش رزرو
        $this->assertSame('دانلود گزارش رزرو', $progress['steps'][0]['actions'][0]['label']);
        $this->assertTrue($progress['steps'][0]['actions'][0]['downloadable']);
    }

    /** سفارش رزروی لغو شده → ۵ مرحله؛ مرحله اول cancelled */
    public function test_supplier_progress_cancelled_reservation_order(): void
    {
        $order = $this->makeOrder([
            Order::STATUS => Order::STATUS_CANCELLED,
            Order::FINAL_AMOUNT => 3_000_000_000,
            Order::PAYMENT_TO_RESERVE => 1_950_000_000,
        ]);

        $progress = $order->supplier_progress;

        $this->assertTrue($progress['is_cancelled']);
        $this->assertCount(5, $progress['steps']);
        $this->assertSame('cancelled', $progress['steps'][0]['state']);

        foreach (array_slice($progress['steps'], 1) as $step) {
            $this->assertSame('inactive', $step['state']);
        }
    }
}
