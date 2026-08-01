<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Invoice\InvoiceGenerator;
use App\Support\OrderDocumentCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ذی‌نفع، **خریدار** است — نه الیت‌یار.
 *
 * `Order::partyRolesFor()` هر دو ستونِ `beneficiary_user_id` و
 * `elite_yar_user_id` را به یک مخاطب (`AUDIENCE_ELITE_YAR`) نگاشت می‌کرد. در
 * مسیرِ «الیت‌یار سفارش می‌دهد و کارفرما را نام می‌برد» — که `StoreOrderRequest`
 * اجباری‌اش می‌کند — ذی‌نفع همان کارفرما/مشتری است، پس نتیجه دو طرفه غلط بود:
 * کارفرما سندِ مالیاتیِ شخصیِ مشاورش را می‌گرفت و روی اسنادِ خودش ۴۰۳ می‌خورد.
 *
 * `OrderDocumentAccessTest` این را نمی‌دید چون هیچ فیکسچرِ آن
 * `beneficiary_user_id` را پر نمی‌کند — همهٔ ۵۱ ترکیبش روی مسیرِ
 * «کارفرما خودش سفارش می‌دهد» می‌دود، جایی که این ستون همیشه NULL است.
 */
class BeneficiaryDocumentAudienceTest extends TestCase
{
    use DatabaseTransactions;

    private User $eliteYar;

    private User $beneficiary;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        // مجوز تست می‌شود، نه رندرِ mPDF (فونت‌ها gitignore شده‌اند).
        $pdf = Mockery::mock();
        $pdf->shouldReceive('download')->andReturn(response('%PDF-stub'));
        $this->mock(InvoiceGenerator::class)->shouldReceive('download')->andReturn($pdf);

        $this->eliteYar = User::factory()->create();
        $this->beneficiary = User::factory()->create();

        // مسیرِ واقعیِ الیت‌یار: placer = الیت‌یار، elite_yar = خودش، ذی‌نفع = کارفرما.
        $this->order = Order::create([
            Order::PLACER_USER_ID => $this->eliteYar->id,
            Order::ELITE_YAR_USER_ID => $this->eliteYar->id,
            Order::BENEFICIARY_USER_ID => $this->beneficiary->id,
            Order::ORDER_NUMBER => 'BEN-'.Str::random(10),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::FINAL_AMOUNT => 1_000_000,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
        ]);

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $this->order->id,
            OrderItem::SUPPLIER_USER_ID => User::factory()->create()->id,
        ]);
    }

    private function url(string $slug): string
    {
        return "/api/profile/orders/{$this->order->id}/invoice/{$slug}";
    }

    #[Test]
    public function the_beneficiary_is_a_buyer_not_an_elite_yar(): void
    {
        $this->assertSame(
            [OrderDocumentCatalog::AUDIENCE_BUYER],
            $this->order->partyRolesFor($this->beneficiary->id),
        );
    }

    #[Test]
    public function the_elite_yar_keeps_their_own_audience(): void
    {
        $roles = $this->order->partyRolesFor($this->eliteYar->id);

        $this->assertContains(OrderDocumentCatalog::AUDIENCE_ELITE_YAR, $roles);
        // در این مسیر الیت‌یار ثبت‌کننده هم هست، پس خریدار هم حساب می‌شود.
        $this->assertContains(OrderDocumentCatalog::AUDIENCE_BUYER, $roles);
    }

    #[Test]
    public function the_beneficiary_cannot_download_the_elite_yars_tax_invoice(): void
    {
        Sanctum::actingAs($this->beneficiary);

        // سند Q به مبلغ payment_to_elite_yar است — فاکتور مودیانِ شخصیِ مشاور.
        $this->getJson($this->url('tax_elite_yar'))->assertForbidden();
        $this->getJson($this->url('elite_yar_report'))->assertForbidden();
    }

    #[Test]
    public function the_beneficiary_can_download_their_own_buyer_documents(): void
    {
        Sanctum::actingAs($this->beneficiary);

        // سند P — فاکتور مودیانی که به نامِ خودِ اوست — و بارنامه.
        $this->get($this->url('tax'))->assertOk();
        $this->get($this->url('receipt'))->assertOk();
        $this->get($this->url('bill_of_lading'))->assertOk();
    }

    #[Test]
    public function the_elite_yar_can_still_download_their_own_documents(): void
    {
        Sanctum::actingAs($this->eliteYar);

        $this->get($this->url('tax_elite_yar'))->assertOk();
        $this->get($this->url('elite_yar_report'))->assertOk();
    }
}
