<?php

namespace Tests\Feature\Supplier;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Invoice\InvoiceGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * پنلِ تأمین‌کننده باید پولِ **خودش** را نشان دهد، نه پولِ خریدار را.
 *
 * `final_amount` جمعِ چیزی است که خریدار می‌پردازد — کالا + کمیسیون + مالیات +
 * کرایه. تا پیش از این تنها رقمی بود که تأمین‌کننده در صفِ کاری و داشبوردش
 * می‌دید، پس پنل مبلغی را «درآمدِ من» جا می‌زد که هرگز به او نمی‌رسد؛ و
 * `payment_to_supplier` — همان که واقعاً به کیف پولش واریز می‌شود — در هیچ
 * اندپوینتِ کاربرمحوری برنمی‌گشت.
 *
 * ستونِ `payment_to_supplier` همیشه «مبلغِ جاری» است: PTS در پرداخت، و پس از
 * باسکول با PTSM بازنویسی می‌شود (ProcessWeighbridgeResultAction).
 */
class SupplierSettlementVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    private function makeSupplier(): User
    {
        $user = User::factory()->create();
        $user->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']));

        return $user;
    }

    /** سفارشی که تأمین‌کننده‌اش $supplier است و خریدارش کسِ دیگر. */
    private function makeOrder(User $supplier, float $finalAmount, ?float $pts, ?User $buyer = null): Order
    {
        $order = Order::create([
            Order::PLACER_USER_ID => ($buyer ?? User::factory()->create())->id,
            Order::ORDER_NUMBER => 'SET-'.Str::random(10),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::SUBTOTAL => $finalAmount,
            Order::VAT_AMOUNT => 0,
            Order::SHIPPING_COST => 0,
            Order::FINAL_AMOUNT => $finalAmount,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
        ]);

        if ($pts !== null) {
            // اسنپ‌شات‌های مالی در $fillable نیستند — فقط forceFill می‌نویسد.
            $order->forceFill([Order::PAYMENT_TO_SUPPLIER => $pts])->save();
        }

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $order->id,
            OrderItem::SUPPLIER_USER_ID => $supplier->id,
        ]);

        return $order;
    }

    #[Test]
    public function a_supplier_sees_their_own_settlement_amount_on_the_order(): void
    {
        $supplier = $this->makeSupplier();
        $this->makeOrder($supplier, finalAmount: 3_100_000_000, pts: 2_900_000_000);

        Sanctum::actingAs($supplier);

        $row = $this->getJson('/api/profile/supplier/orders')->assertOk()->json('data.0');

        $this->assertSame('2900000000.00', $row['financial']['supplier_settlement']['payment_to_supplier']);
        $this->assertFalse($row['financial']['supplier_settlement']['is_post_weighbridge']);
        $this->assertFalse($row['financial']['supplier_settlement']['moadian_submitted']);
        $this->assertFalse($row['financial']['supplier_settlement']['debt_pending']);
    }

    #[Test]
    public function the_settlement_amount_is_null_before_payment_rather_than_a_misleading_zero(): void
    {
        $supplier = $this->makeSupplier();
        $this->makeOrder($supplier, finalAmount: 1_000_000, pts: null);

        Sanctum::actingAs($supplier);

        $row = $this->getJson('/api/profile/supplier/orders')->assertOk()->json('data.0');

        $this->assertNull($row['financial']['supplier_settlement']['payment_to_supplier']);
    }

    #[Test]
    public function the_settlement_block_flags_the_post_weighbridge_figure_and_open_obligations(): void
    {
        $supplier = $this->makeSupplier();
        $order = $this->makeOrder($supplier, finalAmount: 1_000_000, pts: 700_000);

        $order->forceFill([
            Order::WEIGHING_COMPLETED_AT => now(),
            Order::SUPPLIER_DEBT_PENDING => true,
            Order::SUPPLIER_DEBT_AMOUNT => 120_000,
        ])->save();

        Sanctum::actingAs($supplier);

        $settlement = $this->getJson('/api/profile/supplier/orders')
            ->assertOk()
            ->json('data.0.financial.supplier_settlement');

        // پس از باسکول رقم دیگر PTS نیست، PTSM است — پنل باید بتواند بگوید کدام.
        $this->assertTrue($settlement['is_post_weighbridge']);
        $this->assertTrue($settlement['debt_pending']);
        $this->assertSame('120000.00', $settlement['supplier_debt_amount']);
    }

    #[Test]
    public function the_buyer_never_sees_the_supplier_settlement_block(): void
    {
        $supplier = $this->makeSupplier();
        $buyer = User::factory()->create();
        $order = $this->makeOrder($supplier, finalAmount: 1_000_000, pts: 700_000, buyer: $buyer);

        Sanctum::actingAs($buyer);

        $financial = $this->getJson("/api/profile/orders/{$order->id}")
            ->assertOk()
            ->json('data.financial');

        $this->assertArrayNotHasKey('supplier_settlement', $financial);
        // در عوض خریدار تفکیکِ هزینهٔ خودش را می‌بیند.
        $this->assertArrayHasKey('vat_amount', $financial);
    }

    #[Test]
    public function a_supplier_buying_for_themselves_gets_the_buyer_view_of_their_own_order(): void
    {
        // معیارِ قبلی نقشِ سراسری (`hasRole('supplier')`) بود، پس یک تأمین‌کننده روی
        // سفارشِ خریدِ خودش هم نمای سانسورشده می‌گرفت و مالیات/کرایهٔ خودش را
        // نمی‌دید. حالا معیار نقش در همان سفارش است — و مهم‌تر: بلوکِ تسویه روی
        // سفارشی که او فقط خریدارش است ظاهر نمی‌شود.
        $supplier = $this->makeSupplier();
        $otherSupplier = $this->makeSupplier();

        $order = $this->makeOrder($otherSupplier, finalAmount: 1_000_000, pts: 700_000, buyer: $supplier);

        Sanctum::actingAs($supplier);

        $financial = $this->getJson("/api/profile/orders/{$order->id}")
            ->assertOk()
            ->json('data.financial');

        $this->assertArrayNotHasKey('supplier_settlement', $financial);
        $this->assertArrayHasKey('vat_amount', $financial);
        $this->assertArrayHasKey('shipping_cost', $financial);
    }

    #[Test]
    public function the_dashboard_totals_the_suppliers_own_money_not_the_buyers(): void
    {
        $supplier = $this->makeSupplier();
        $this->makeOrder($supplier, finalAmount: 1_000_000, pts: 800_000);
        $this->makeOrder($supplier, finalAmount: 2_000_000, pts: 1_500_000);

        Sanctum::actingAs($supplier);

        $stats = collect($this->getJson('/api/profile/supplier/dashboard')->assertOk()->json('data.order_stats'))
            ->keyBy('status');

        // cast صریح: JSON عددِ گردِ float را بدون `.0` سریالایز می‌کند.
        $this->assertSame(2_300_000.0, (float) $stats[Order::STATUS_PROCESSING]['total_amount']);
        // ارزشِ کلِ سفارش هنوز در دسترس است، ولی حالا اسمش دروغ نمی‌گوید.
        $this->assertSame(3_000_000.0, (float) $stats[Order::STATUS_PROCESSING]['order_value']);
    }

    #[Test]
    public function an_unpaid_order_contributes_zero_rather_than_dropping_out_of_the_totals(): void
    {
        // COALESCE: بدون آن، SUM روی ستونِ NULL کلِ گروه را NULL می‌کرد و
        // سفارشِ پرداخت‌نشده رقمِ داشبورد را می‌بلعید.
        $supplier = $this->makeSupplier();
        $this->makeOrder($supplier, finalAmount: 1_000_000, pts: null);

        Sanctum::actingAs($supplier);

        $stats = collect($this->getJson('/api/profile/supplier/dashboard')->assertOk()->json('data.order_stats'))
            ->keyBy('status');

        $this->assertSame(0.0, (float) $stats[Order::STATUS_PROCESSING]['total_amount']);
        $this->assertSame(1, $stats[Order::STATUS_PROCESSING]['count']);
    }

    #[Test]
    public function the_download_button_points_at_a_document_the_supplier_can_actually_open(): void
    {
        $supplier = $this->makeSupplier();
        $order = $this->makeOrder($supplier, finalAmount: 1_000_000, pts: 700_000);

        Sanctum::actingAs($supplier);

        $action = $this->getJson('/api/profile/supplier/orders')
            ->assertOk()
            ->json('data.0.actions.download_invoice');

        $this->assertTrue($action['active']);
        $this->assertStringEndsWith('/invoice/supplier_report', $action['url']);

        // و لینک واقعاً باز می‌شود — پیش از این به سندِ خریدار اشاره می‌کرد و ۴۰۳ بود.
        // رندرِ mPDF mock می‌شود: فونت‌های فارسی gitignore شده‌اند و روی یک کلونِ
        // تازه وجود ندارند، پس تستِ مجوز نباید به آن‌ها گره بخورد.
        $pdf = Mockery::mock();
        $pdf->shouldReceive('download')->andReturn(response('%PDF-stub'));
        $this->mock(InvoiceGenerator::class)->shouldReceive('download')->andReturn($pdf);

        $this->get("/api/profile/orders/{$order->id}/invoice/supplier_report")->assertOk();
    }
}
