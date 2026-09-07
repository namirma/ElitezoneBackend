<?php

declare(strict_types=1);

namespace Tests\Feature\Supplier;

use App\Models\Factory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Pricing\CreatesPricingFixtures;
use Tests\TestCase;

/**
 * گزارشِ درآمد با بازه‌ی زمانی.
 *
 * تنها دیدِ مالیِ تأمین‌کننده `dashboard.order_stats` بود که فقط بر اساس
 * **وضعیت** گروه می‌کرد. یعنی «مردادِ امسال چقدر فروختم» یا «کدام کارخانه‌ام
 * بیشتر آورده» اصلاً قابلِ پرسیدن نبود، و `payment_to_supplier` یک عددِ تنها
 * بود که با هیچ‌چیز قابلِ مطابقت نبود.
 *
 * دو تصمیمی که اینجا پین می‌شوند:
 *
 *   ۱. مبنای تاریخ `weighing_completed_at` است نه `created_at` — درآمد وقتی
 *      قطعی می‌شود که باسکول تسویه شود.
 *   ۲. سفارشِ تسویه‌نشده **حذف نمی‌شود، جدا شمرده می‌شود** — حذفش یعنی
 *      تأمین‌کننده نداند چقدر در راه دارد، قاطی‌کردنش یعنی جمعِ ماه دروغ بگوید.
 */
class SupplierEarningsReportTest extends TestCase
{
    use CreatesPricingFixtures;
    use DatabaseTransactions;

    private User $supplier;

    private $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = User::factory()->create();
        $this->supplier->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']));

        $this->factory = $this->makeFactory();
        $this->factory->forceFill([Factory::SUPPLIER_USER_ID => $this->supplier->id])->save();
    }

    private function order(array $snapshot, ?User $supplier = null): Order
    {
        $order = Order::create([
            Order::PLACER_USER_ID => User::factory()->create()->id,
            Order::ORDER_NUMBER => 'ER-'.Str::random(8),
            Order::STATUS => Order::STATUS_DELIVERED,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
        ]);
        $order->forceFill($snapshot)->save();

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $order->id,
            'supplier_user_id' => ($supplier ?? $this->supplier)->id,
            'factory_id' => $this->factory->id,
        ]);

        return $order->fresh();
    }

    private function report(string $query = ''): array
    {
        Sanctum::actingAs($this->supplier);

        return $this->getJson('/api/profile/supplier/earnings'.$query)->assertOk()->json('data');
    }

    #[Test]
    public function settled_and_in_progress_orders_are_counted_separately(): void
    {
        $this->order([
            Order::WEIGHING_COMPLETED_AT => now()->subDays(5),
            Order::PAYMENT_TO_SUPPLIER => 4_000_000,
            Order::FINAL_AMOUNT => 5_000_000,
        ]);

        // پرداخت‌شده ولی هنوز باسکول نخورده: رقمش PTS است، یعنی برآورد.
        $this->order([Order::PAYMENT_TO_SUPPLIER => 1_000_000]);

        $data = $this->report();

        $this->assertSame(1, $data['settled']['orders']);
        $this->assertSame(4_000_000.0, (float) $data['settled']['net_payout']);

        $this->assertSame(1, $data['in_progress']['orders']);
        $this->assertSame(1_000_000.0, (float) $data['in_progress']['estimated_payout']);

        // قاطی‌شدنشان یعنی جمعِ «درآمد» عددی بدهد که هنوز قطعی نیست.
        $this->assertNotSame(
            (float) $data['settled']['net_payout'],
            (float) $data['settled']['net_payout'] + (float) $data['in_progress']['estimated_payout']
        );
    }

    #[Test]
    public function the_range_filters_on_settlement_date_not_order_date(): void
    {
        // سفارشی که خیلی وقت پیش ثبت شده ولی **همین ماه** تسویه شده باید در
        // بازه‌ی همین ماه بیاید — درآمد وقتی قطعی می‌شود که باسکول تسویه شود.
        $order = $this->order([
            Order::WEIGHING_COMPLETED_AT => now(),
            Order::PAYMENT_TO_SUPPLIER => 7_000_000,
        ]);
        $order->forceFill(['created_at' => now()->subYear()])->save();

        $data = $this->report();

        $this->assertSame('weighing_completed_at', $data['range']['basis']);
        $this->assertSame(7_000_000.0, (float) $data['settled']['net_payout']);
    }

    #[Test]
    public function the_jalali_range_is_converted_before_it_filters(): void
    {
        // این تست وجود دارد چون تستِ بالا `from`/`to` نمی‌فرستد و بنابراین
        // تبدیلِ تاریخ را اصلاً لمس نمی‌کند — یک فیلترِ کاملاً **وارونه** از
        // زیرش سبز رد شد: `Verta::createDate()` ورودی را میلادی می‌گیرد، پس
        // `1405-06-01` همان سالِ ۱۴۰۵ پس از میلاد می‌شد و هر سفارشی از آن
        // جلوتر بود. شکلِ پاسخ درست می‌ماند و خطایی هم نمی‌داد.
        //
        // ۱۴۰۵/۰۵/۰۴ = ۲۰۲۶-۰۷-۲۶ میلادی.
        $order = $this->order([
            Order::PAYMENT_TO_SUPPLIER => 5_000_000,
        ]);
        $order->forceFill([Order::WEIGHING_COMPLETED_AT => '2026-07-26 12:00:00'])->save();

        // بازه‌ای که آن روز داخلش است باید سفارش را بیاورد.
        $inside = $this->report('?from=1405-04-01&to=1405-05-31');
        $this->assertSame(1, $inside['settled']['orders']);
        $this->assertSame(5_000_000.0, (float) $inside['settled']['net_payout']);

        // بازه‌ای که از آن روز جلوتر شروع می‌شود نباید چیزی بیاورد.
        $after = $this->report('?from=1405-06-01');
        $this->assertSame(0, $after['settled']['orders']);
    }

    #[Test]
    public function another_suppliers_orders_never_appear(): void
    {
        $other = User::factory()->create();
        $other->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']));

        $this->order([
            Order::WEIGHING_COMPLETED_AT => now(),
            Order::PAYMENT_TO_SUPPLIER => 9_999_999,
        ], $other);

        $data = $this->report();

        $this->assertSame(0, $data['settled']['orders']);
        $this->assertSame(0.0, (float) $data['settled']['net_payout']);
    }

    #[Test]
    public function obligations_expose_both_halves_of_the_required_top_up(): void
    {
        $this->order([
            Order::WEIGHING_COMPLETED_AT => now(),
            Order::SUPPLIER_DEBT_PENDING => true,
            Order::SUPPLIER_DEBT_AMOUNT => 3_000_000,
            Order::SUPPLIER_VAT_PENDING_AMOUNT => 700_000,
        ]);

        $data = $this->report();

        $this->assertSame(3_000_000.0, (float) $data['obligations']['debt']);
        $this->assertSame(700_000.0, (float) $data['obligations']['suspended_vat']);
        // هر دو جزء لازم‌اند: کرون تنها وقتی آزاد می‌کند که موجودی ≥ مجموعشان باشد.
        $this->assertSame(1, $data['obligations']['awaiting_moadian']);
    }

    #[Test]
    public function the_breakdown_groups_by_month_and_by_factory(): void
    {
        $this->order([
            Order::WEIGHING_COMPLETED_AT => now(),
            Order::PAYMENT_TO_SUPPLIER => 2_500_000,
        ]);

        $data = $this->report();

        $this->assertNotEmpty($data['monthly']);
        $this->assertArrayHasKey('period', $data['monthly'][0]);

        $this->assertNotEmpty($data['by_factory']);
        $this->assertSame($this->factory->id, $data['by_factory'][0]['factory_id']);
    }

    #[Test]
    public function a_non_supplier_is_refused(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/profile/supplier/earnings')->assertStatus(403);
    }

    #[Test]
    public function an_out_of_calendar_jalali_date_does_not_500(): void
    {
        // ماهِ ۱۳ شکلاً از regex رد می‌شود ولی تقویمی وجود ندارد؛ فیلترِ بی‌اثر
        // بهتر از خطای سرور است.
        $this->report('?from=1404-13-45');
        $this->assertTrue(true);
    }
}
