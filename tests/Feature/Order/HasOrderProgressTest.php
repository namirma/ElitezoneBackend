<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Tests for HasOrderProgress trait accessors.
 *
 * Covers getDashboardProgressAttribute (buyer), getEliteYARProgressAttribute,
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

        $order = Order::create(array_merge([
            Order::PLACER_USER_ID => $user->id,
            Order::ORDER_NUMBER => 'TEST-PROGRESS-'.uniqid(),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
            Order::FINAL_AMOUNT => 100_000, // < threshold → full-payment (no reserve step)
        ], $overrides));

        // Financial snapshot fields are not in $fillable — apply them via forceFill
        // so tests that seed these columns work without loosening mass-assignment.
        if ($overrides) {
            $order->forceFill($overrides)->save();
        }

        return $order;
    }

    // =========================================================================
    //  EliteYAR Progress
    // =========================================================================

    /** مرحله ۱ باید current باشد وقتی status = PROCESSING و فیلدهای مودیان null است */
    public function test_elite_yar_progress_step1_current_on_processing(): void
    {
        $order = $this->makeOrder([Order::STATUS => Order::STATUS_PROCESSING]);

        $progress = $order->elite_yar_progress;

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
    public function test_elite_yar_progress_step2_current_on_weighbridge_done(): void
    {
        $order = $this->makeOrder([Order::STATUS => Order::STATUS_SHIPPED]);

        $progress = $order->elite_yar_progress;

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
    public function test_elite_yar_progress_all_completed_when_hold_released(): void
    {
        $order = $this->makeOrder([
            Order::STATUS => Order::STATUS_DELIVERED,
            Order::ELITE_YAR_MOADIAN_REF => 'TAX-REF-001',
            Order::ELITE_YAR_HOLD_RELEASED_AT => now(),
        ]);

        $progress = $order->elite_yar_progress;

        foreach ($progress['steps'] as $step) {
            $this->assertSame('completed', $step['state'],
                "Step «{$step['title']}» should be completed");
        }
    }

    /**
     * رفتار صحیح: سفارش delivered بدون Doc Q (بدون hold_released_at) → مرحله ۲ current
     *
     * طبق پروسه خرید (بخش «الگوریتم وابستگی واریزها»):
     * «تا زمان صدور فاکتور سامانه مودیان توسط الیت یار در کیف پول وی هولد می‌شود».
     * پس صرف تحویل کالا hold را آزاد نمی‌کند — الیت یار باید خودش Doc Q ثبت کند.
     *
     * نکته: قبل از این fix، delivered → همه مراحل completed نشان داده می‌شد (اشتباه).
     */
    public function test_elite_yar_progress_delivered_without_hold_release_stays_at_step2(): void
    {
        $order = $this->makeOrder([
            Order::STATUS => Order::STATUS_DELIVERED,
            Order::ELITE_YAR_MOADIAN_REF => null,
            Order::ELITE_YAR_HOLD_RELEASED_AT => null,
        ]);

        $progress = $order->elite_yar_progress;

        $this->assertFalse($progress['is_cancelled']);

        // مرحله ۱: کارفرما خرید کرده → completed
        $this->assertSame('completed', $progress['steps'][0]['state'],
            'Step 1 should be completed (purchase happened)');

        // مرحله ۲: کارمزد الیت یار منظور شده ولی Hold است → current (منتظر Doc Q)
        $this->assertSame('current', $progress['steps'][1]['state'],
            'Step 2 must be current: commission is in hold, waiting for eliteYAR to submit Doc Q');

        // مراحل ۳ و ۴ هنوز inactive
        $this->assertSame('inactive', $progress['steps'][2]['state'],
            'Step 3 should be inactive until Doc Q is submitted');
        $this->assertSame('inactive', $progress['steps'][3]['state'],
            'Step 4 should be inactive until hold_released_at is set by ReleaseEliteYARHoldAction');

        // دکمه «ثبت فاکتور» باید نمایش داشته باشد — بدون آن الیت یار نمی‌تواند پولش را آزاد کند
        $step2Labels = array_column($progress['steps'][1]['actions'], 'label');
        $this->assertContains('فاکتور خدمات خود را ثبت کنید', $step2Labels,
            'Submit invoice button must be visible for delivered orders awaiting Doc Q');
    }

    /** completed (مانند delivered) بدون Doc Q → همان رفتار delivered: مرحله ۲ current */
    public function test_elite_yar_progress_completed_without_hold_release_stays_at_step2(): void
    {
        $order = $this->makeOrder([
            Order::STATUS => Order::STATUS_DELIVERED,
            Order::ELITE_YAR_MOADIAN_REF => null,
            Order::ELITE_YAR_HOLD_RELEASED_AT => null,
        ]);

        $progress = $order->elite_yar_progress;

        $this->assertSame('completed', $progress['steps'][0]['state']);
        $this->assertSame('current', $progress['steps'][1]['state'],
            'STATUS_COMPLETED without Doc Q must also stay at step 2');
        $this->assertSame('inactive', $progress['steps'][2]['state']);
        $this->assertSame('inactive', $progress['steps'][3]['state']);
    }

    /**
     * باگ ۲ (رفع‌شده): waybill_issued → مرحله ۲ باید current باشد، نه مرحله ۱
     * قبلاً waybill_issued در elseif مرحله ۱ بود و به ۱ برمی‌گشت.
     */
    public function test_elite_yar_progress_waybill_issued_stays_at_step2(): void
    {
        $order = $this->makeOrder([Order::STATUS => Order::STATUS_SHIPPED]);

        $progress = $order->elite_yar_progress;

        $this->assertSame('completed', $progress['steps'][0]['state'],
            'Bug 2 regression: step 1 should be completed when waybill_issued');
        $this->assertSame('current', $progress['steps'][1]['state'],
            'Bug 2 regression: step 2 should be current for waybill_issued, not step 1');
        $this->assertSame('inactive', $progress['steps'][2]['state']);
        $this->assertSame('inactive', $progress['steps'][3]['state']);
    }

    /** shipped → همان رفتار waybill_issued — مرحله ۲ current */
    public function test_elite_yar_progress_shipped_stays_at_step2(): void
    {
        $order = $this->makeOrder([Order::STATUS => Order::STATUS_SHIPPED]);

        $progress = $order->elite_yar_progress;

        $this->assertSame('completed', $progress['steps'][0]['state']);
        $this->assertSame('current', $progress['steps'][1]['state']);
    }

    /**
     * مرحله ۲ — دکمه «ثبت فاکتور» همیشه نشان داده می‌شود
     * (اندپوینت idempotent است، ثبت مجدد بی‌خطر است)
     */
    public function test_elite_yar_progress_step2_always_shows_submit_button(): void
    {
        $order = $this->makeOrder([Order::STATUS => Order::STATUS_SHIPPED]);

        $progress = $order->elite_yar_progress;

        $step2Labels = array_column($progress['steps'][1]['actions'], 'label');
        $this->assertContains('مشاهده کیف پول', $step2Labels);
        $this->assertContains('فاکتور خدمات خود را ثبت کنید', $step2Labels);
    }

    /** سفارش لغو شده → مرحله اول cancelled، بقیه inactive */
    public function test_elite_yar_progress_cancelled_order(): void
    {
        $order = $this->makeOrder([Order::STATUS => Order::STATUS_CANCELLED]);

        $progress = $order->elite_yar_progress;

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
            Order::STATUS => Order::STATUS_SHIPPED,
            Order::SUPPLIER_MOADIAN_REF => null,
        ]);

        $progress = $order->supplier_progress;

        $step2 = $progress['steps'][1]; // index 1 = بارنامه صادر و کالا ارسال شد (no reserve step)
        $this->assertSame('بارنامه صادر و کالا ارسال شد', $step2['title']);
        $this->assertSame('current', $step2['state']);

        $labels = array_column($step2['actions'], 'label');
        $this->assertContains('دانلود مجوز خروج', $labels);
        $this->assertContains('ثبت فاکتور مودیان', $labels);
    }

    /** WEIGHBRIDGE_DONE + moadian ثبت شده → لینک «ثبت فاکتور مودیان» نباید وجود داشته باشد */
    public function test_supplier_progress_step2_hides_moadian_action_when_submitted(): void
    {
        $order = $this->makeOrder([
            Order::STATUS => Order::STATUS_SHIPPED,
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
        $this->assertSame('فاکتور مودیان ثبت شد', $lastStep['title']);

        // مرحله‌ی آخر ماشه‌اش `supplier_moadian_ref` است، نه `delivered` — تحویلِ
        // کالا هنوز فاکتور مودیانِ تأمین‌کننده را صادر نکرده، پس این دایره باید
        // خاکستری بماند و «تحویل و تسویه شد» دایره‌ی جاری باشد.
        $this->assertSame('inactive', $lastStep['state']);

        $settled = $progress['steps'][count($progress['steps']) - 2];
        $this->assertSame('تحویل و تسویه شد', $settled['title']);
        $this->assertSame('current', $settled['state']);
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
